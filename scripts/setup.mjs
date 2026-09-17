#!/usr/bin/env node
/**
 * setup.mjs — 一键初始化 Cloudflare 资源（确定性脚本，适合人工或 AI Agent 直接执行）
 *
 * 做什么：
 *   1. 检查前置条件（CLOUDFLARE_API_TOKEN、wrangler 可用）
 *   2. 创建 D1 数据库 lc-db（已存在则复用）并抓取 database_id
 *   3. 创建 R2 bucket lc-media（已存在则复用）
 *   4. 把真实 database_id 回填进 wrangler.jsonc（替换占位符 REPLACE_WITH_D1_DATABASE_ID）
 *   5. 依序执行 migrations/*.sql（0001 → 0002）
 *   6. 生成 ADMIN_PASSWORD 并尝试写入 Worker secret（首次部署必填，登录后后台改密，可删）
 *   7. 输出部署总结
 *
 * 用法：npm run setup
 * 环境：需要 CLOUDFLARE_API_TOKEN（权限见 AGENTS.md：Workers Scripts Edit + D1 Edit + R2 Edit）
 *
 * 本项目说明：管理员密码、Telegram 通知、企业微信通知均可通过 env secret 覆盖；
 *   也可在后台 /admin → 系统设置 维护 settings 表（env secret 优先级更高）。
 *   R2（lc-media）存聊天图片与管理员头像；Cron 每日 03:00 UTC 自动清理旧数据。
 */
import { spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync, readdirSync, existsSync } from 'node:fs';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
const CONFIG = join(ROOT, 'wrangler.jsonc');
const MIGRATIONS = join(ROOT, 'migrations');

const DB_NAME = 'livechat-db';
const R2_NAME = 'livechat-media-r2';
const ID_PLACEHOLDER = 'REPLACE_WITH_D1_DATABASE_ID';
const UUID_RE = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i;

const RED = (s) => `\x1b[31m${s}\x1b[0m`;
const GREEN = (s) => `\x1b[32m${s}\x1b[0m`;
const YELLOW = (s) => `\x1b[33m${s}\x1b[0m`;
const CYAN = (s) => `\x1b[36m${s}\x1b[0m`;

function log(...a) { console.log(CYAN('[setup]'), ...a); }
function ok(...a) { console.log(GREEN('[ ok ]'), ...a); }
function warn(...a) { console.log(YELLOW('[warn]'), ...a); }
function fail(...a) { console.log(RED('[FAIL]'), ...a); }

/** 运行 wrangler 命令；shell:true 保证 Windows/Linux 都可用。返回 {status, stdout, stderr} */
function wrangler(args, { input, env, noCi } = {}) {
  const res = spawnSync('npx', ['wrangler', ...args], {
    cwd: ROOT,
    encoding: 'utf8',
    shell: true,
    stdio: ['pipe', 'pipe', 'pipe'],
    input,
    env: {
      ...process.env,
      // secret put 需要交互式确认，不能进 CI 模式
      // 注意：不要设 WRANGLER_LOG=warn，否则 d1 list 等正常输出会被抑制，脚本无法解析 ID
      ...(noCi ? {} : { CI: '1' }),
      ...env,
    },
    timeout: 120_000,
  });
  if (res.error) throw res.error;
  return { status: res.status, stdout: res.stdout || '', stderr: res.stderr || '' };
}

function firstUuid(text) {
  const m = text.match(UUID_RE);
  return m ? m[0] : null;
}

function checkPrereqs() {
  if (!process.env.CLOUDFLARE_API_TOKEN) {
    warn('未检测到 CLOUDFLARE_API_TOKEN，将尝试使用本机已有的 wrangler 登录态。');
    warn('建议显式设置：export CLOUDFLARE_API_TOKEN="cf_..."（权限见 AGENTS.md）');
  }
  const r = wrangler(['--version']);
  if (r.status !== 0) {
    fail('无法运行 wrangler。请先：npm install');
    process.exit(1);
  }
  ok('wrangler 可用：' + (r.stdout || r.stderr).trim().split('\n')[0]);
}

/** 确保 D1 存在，返回 database_id */
async function ensureD1() {
  const list = wrangler(['d1', 'list']);
  const listText = (list.stdout + list.stderr) || '';
  const line = listText.split('\n').find((l) => l.includes(DB_NAME));
  if (line) {
    const id = firstUuid(line);
    if (id) {
      ok(`D1 已存在：${DB_NAME} (${id})`);
      return id;
    }
    warn(`D1 列表中存在 ${DB_NAME}，但未能解析 ID，将尝试 create 输出。`);
  }
  const r = wrangler(['d1', 'create', DB_NAME]);
  const text = (r.stdout + r.stderr) || '';
  const id = firstUuid(text);
  if (r.status === 0 && id) {
    ok(`D1 创建成功：${DB_NAME} (${id})`);
    return id;
  }
  const r2 = wrangler(['d1', 'create', DB_NAME]);
  const text2 = (r2.stdout + r2.stderr) || '';
  const id2 = firstUuid(text2);
  if (r2.status === 0 && id2) {
    ok(`D1 创建成功：${DB_NAME} (${id2})`);
    return id2;
  }
  // 创建失败（例如"already exists"）时，再查一次列表复用现有 ID
  const reList = wrangler(['d1', 'list']);
  const reText = (reList.stdout + reList.stderr) || '';
  const reLine = reText.split('\n').find((l) => l.includes(DB_NAME));
  if (reLine) {
    const id3 = firstUuid(reLine);
    if (id3) {
      ok(`D1 已存在（create 报已存在，复用）：${DB_NAME} (${id3})`);
      return id3;
    }
  }
  fail('创建 D1 失败，请检查 Token 是否包含 "D1 Edit" 权限。\n--- 输出 ---\n' + text + text2 + reText);
  process.exit(1);
}

/** 确保 R2 bucket 存在（幂等） */
async function ensureR2() {
  const list = wrangler(['r2', 'bucket', 'list']);
  const listText = (list.stdout + list.stderr) || '';
  if (listText.includes(R2_NAME)) {
    ok(`R2 bucket 已存在：${R2_NAME}`);
    return;
  }
  const r = wrangler(['r2', 'bucket', 'create', R2_NAME]);
  if (r.status === 0) {
    ok(`R2 bucket 创建成功：${R2_NAME}`);
    return;
  }
  // 已存在/并发创建时 create 会报错，只要 list 里能看到即视为成功
  const re = wrangler(['r2', 'bucket', 'list']);
  if ((re.stdout + re.stderr || '').includes(R2_NAME)) {
    warn(`R2 bucket 已存在（create 返回非零，忽略）：${R2_NAME}`);
    return;
  }
  fail(`创建 R2 bucket 失败，请检查 Token 是否包含 "R2 Edit" 权限。\n--- 输出 ---\n${r.stdout}\n${r.stderr}`);
  process.exit(1);
}

/** 把 database_id 回填进 wrangler.jsonc */
function injectDatabaseId(id) {
  if (!existsSync(CONFIG)) {
    fail(`找不到配置文件 ${CONFIG}`);
    process.exit(1);
  }
  let cfg = readFileSync(CONFIG, 'utf8');
  if (!cfg.includes(ID_PLACEHOLDER)) {
    warn('wrangler.jsonc 中未找到占位符（可能已被回填），跳过注入。');
    return;
  }
  cfg = cfg.replace(ID_PLACEHOLDER, id);
  writeFileSync(CONFIG, cfg, 'utf8');
  ok(`已把 database_id 写入 ${CONFIG}`);
}

/** 依序执行所有迁移 */
async function runMigrations(dbName) {
  if (!existsSync(MIGRATIONS)) {
    warn('没有 migrations 目录，跳过数据库迁移。');
    return;
  }
  const files = readdirSync(MIGRATIONS).filter((f) => /^\d{4}_.+\.sql$/.test(f)).sort();
  if (!files.length) {
    warn('migrations 目录为空，跳过迁移。');
    return;
  }
  log(`将依序执行 ${files.length} 个迁移脚本…`);
  for (const f of files) {
    const r = wrangler(['d1', 'execute', dbName, '--remote', '--file=' + join(MIGRATIONS, f)]);
    if (r.status !== 0) {
      fail(`迁移失败：${f}\n--- 输出 ---\n${r.stdout}\n${r.stderr}`);
      process.exit(1);
    }
    ok(`迁移完成：${f}`);
  }
}

/** 生成 ADMIN_PASSWORD 并尝试写入 Worker secret（需 Worker 已存在；未部署时给出手动命令） */
async function setupAdminSecret() {
  const adminPw = randomBytes(9).toString('base64url'); // ~12 字符，无歧义字符
  console.log(GREEN('\n  🔑 管理员初始密码 ADMIN_PASSWORD（请立即保存）：'));
  console.log(GREEN('     ' + adminPw));
  console.log(GREEN('     首次登录 https://<your-worker>.workers.dev/admin 时用它初始化后台密码；'));
  console.log(GREEN('     登录后请在「后台 → 修改密码」改成自己的强密码，之后此 secret 可删除。\n'));

  log('尝试写入 Worker secret ADMIN_PASSWORD …');
  const r = wrangler(['secret', 'put', 'ADMIN_PASSWORD'], { input: adminPw + '\n', noCi: true });
  if (r.status === 0 && /success/i.test(r.stdout + r.stderr)) {
    ok('ADMIN_PASSWORD secret 已写入（Worker 需已存在）。');
  } else {
    warn('自动写入失败（通常是因为 Worker 尚未部署）。部署后再执行：');
    warn('  npx wrangler secret put ADMIN_PASSWORD   （然后粘贴上面生成的密码）');
    warn('  或  echo -n "' + adminPw + '" | npx wrangler secret put ADMIN_PASSWORD');
  }
}

async function main() {
  console.log(CYAN('=============================================='));
  console.log(CYAN('  LiveChat — Cloudflare Setup'));
  console.log(CYAN('=============================================='));
  checkPrereqs();

  log('步骤 1/4：D1 数据库');
  const d1Id = await ensureD1();

  log('步骤 2/4：R2 bucket（聊天图片存储）');
  await ensureR2();

  log('步骤 3/4：回填 database_id + 执行迁移');
  injectDatabaseId(d1Id);
  await runMigrations(DB_NAME);

  log('步骤 4/4：管理员初始密码');
  await setupAdminSecret();

  console.log(GREEN('=============================================='));
  console.log(GREEN('  ✅ 初始化完成，可以部署了：'));
  console.log(GREEN('     npm run deploy'));
  console.log(GREEN('=============================================='));
  console.log(CYAN('  部署后配置（可选，不填则功能降级）：'));
  console.log(CYAN('    · Telegram 通知：wrangler secret put TELEGRAM_BOT_TOKEN / TELEGRAM_ADMIN_ID'));
  console.log(CYAN('      或在后台 /admin → 系统设置 填写 telegram_token / telegram_admin_id'));
  console.log(CYAN('    · 企业微信通知：wrangler secret put WECOM_WEBHOOK_URL'));
  console.log(CYAN('      或在后台 /admin → 系统设置 填写 wecom_webhook'));
  console.log(CYAN('    · APP_BASE_URL：通知消息里的管理后台链接前缀（默认取请求来源）'));
  console.log(CYAN('    定时清理：每日 03:00 UTC 自动执行（每客户端保最新 20% 至少 50 条；删 90 天僵尸客户端）'));
}

main().catch((e) => {
  fail('脚本异常：', e);
  process.exit(1);
});
