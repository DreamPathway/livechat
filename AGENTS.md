# AGENTS.md — AI / 开发者部署指南

本文件面向**任何 Agent（或人）**自动化部署本 Worker。请严格按下面步骤照单执行，不要自由发挥；遇到报错先查「常见报错对照表」。

---

## 1. 项目速览

- **形态**：Cloudflare Workers 全栈应用（Hono + TypeScript），单 Worker + **D1 数据库**（SQLite）+ **R2 存储**（聊天图片/头像）+ **Workers Assets**（静态页面）+ **Cron 定时清理**。
- **功能**：在线客服系统（LiveChat）。访客聊天（含图片上传）、管理后台（客户列表/回复/设置/改密码）、Telegram 与企业微信群机器人新消息通知、每日定时清理旧数据。
- **配置**：`wrangler.jsonc`（JSONC，支持注释）
- **部署目标**：默认部署到 `https://<worker-name>.<account>.workers.dev`（`workers_dev: true`，未绑定任何自定义域名）。
- **入口脚本**：`npm run setup`（一键创建 D1 + R2、回填 database_id、执行迁移、生成 ADMIN_PASSWORD，见 `scripts/setup.mjs`）。
- **密钥存放**：支持两种方式并存——**env secret（优先级高）** 与 **D1 `settings` 表（后台 → 系统设置维护）**。管理员密码：首次登录用 env `ADMIN_PASSWORD` 初始化 PBKDF2 hash 写入 settings 表，之后 secret 可删除。

## 2. 前置条件

| 项目 | 要求 |
|---|---|
| Node.js | >= 20 |
| npm | >= 9 |
| Cloudflare 账号 | 免费版即可 |
| API Token | 见下方「3. Token 权限清单」 |

## 3. Token 权限清单（CLOUDFLARE_API_TOKEN）

在 Cloudflare 控制台 → My Profile → API Tokens → Create Token，选 **Edit Cloudflare Workers** 模板，并按下表勾选（权限必须 ≥ 下表的粒度，否则 setup/deploy 会失败）：

| 权限 | 级别 | 资源范围 | 用于 |
|---|---|---|---|
| Workers Scripts | **Edit** | Account | `wrangler deploy` / `dev` / `secret put` |
| D1 | **Edit** | Account | `wrangler d1 create/execute` |
| R2 | **Edit** | Account | `wrangler r2 bucket create/list` |
| Account Settings | **Read** | Account | 校验账号信息（部分 wrangler 命令需要） |

> 本项目不涉及 KV / Workers AI，无需这些权限（Token 模板自带也无妨）。Token 创建后：
> ```bash
> export CLOUDFLARE_API_TOKEN="cf_YOUR_TOKEN_HERE"
> ```

## 4. 一键部署（推荐路径）

```bash
# 1) 安装依赖
npm install

# 2) 一键初始化：创建 D1 lc-db + R2 lc-media、回填 database_id、
#    依序执行 migrations/*.sql、生成 ADMIN_PASSWORD 并尝试写入 secret
#    （要求 CLOUDFLARE_API_TOKEN 已设置，或本机已 wrangler login）
npm run setup

# 3) 本地验证（可选）
npm run dev        # 打开 http://localhost:8787

# 4) 部署到 workers.dev
npm run deploy

# 5) 若 setup 阶段未写入成功，补设管理员初始密码（登录后台 /admin 时使用）
npx wrangler secret put ADMIN_PASSWORD

# 6) 确认上线
curl -s https://<your-worker>.<your-account>.workers.dev/api/health   # {"ok":true,...}
curl -s https://<your-worker>.<your-account>.workers.dev/api/meta     # 站点元信息

# 7) 可选：Telegram / 企业微信通知
npx wrangler secret put TELEGRAM_BOT_TOKEN
npx wrangler secret put TELEGRAM_ADMIN_ID
npx wrangler secret put WECOM_WEBHOOK_URL
# 或在后台 /admin → 系统设置 填写（env secret 优先级更高）
```

## 5. 手动步骤（等价于 npm run setup，排查时用）

```bash
# 创建 D1 并记录返回的 database_id
npx wrangler d1 create lc-db
# 把输出的 UUID 填进 wrangler.jsonc 的 database_id（替换 REPLACE_WITH_D1_DATABASE_ID）

# 创建 R2 bucket
npx wrangler r2 bucket create lc-media

# 依序执行所有迁移
for f in migrations/*.sql; do
  npx wrangler d1 execute lc-db --remote --file="$f"
done

# 部署
npx wrangler deploy

# 设置管理员初始密码（首次登录 /admin 时初始化，之后可删除）
npx wrangler secret put ADMIN_PASSWORD
```

## 6. secrets 清单表

### 6.1 环境变量 / Worker secrets（env，优先级高于 settings 表）

| 变量 | 干什么用 | 哪里获取 | 必填 | 不填会怎样 |
|---|---|---|---|---|
| `ADMIN_PASSWORD` | 首次登录后台 `/admin` 的初始密码（登录后写入 PBKDF2 hash 到 settings 表，此 secret 可删除） | 自定强密码（`npm run setup` 自动生成） | ✅ 首次部署必填 | 后台无法登录，提示「未初始化管理员密码」 |
| `TELEGRAM_BOT_TOKEN` | Telegram 机器人 token（新消息推送给管理员） | @BotFather 创建机器人 | 否 | 通知可用后台 settings 表 `telegram_token` 替代；否则无 Telegram 通知（功能降级） |
| `TELEGRAM_ADMIN_ID` | 接收通知的 Telegram 用户 ID | @userinfobot 查询 | 否 | 同上 |
| `WECOM_WEBHOOK_URL` | 企业微信群机器人 webhook（完整 URL 或仅 key） | 企业微信 → 群机器人 → 添加 | 否 | 可用后台 settings 表 `wecom_webhook` 替代；否则无企业微信通知（功能降级） |
| `APP_BASE_URL` | 通知消息里「进入管理后台」链接的基础 URL | 你的部署域名，如 `https://chat.example.com` | 否 | 默认取请求来源 Origin（功能降级） |

### 6.2 后台系统设置（D1 `settings` 表，登录 /admin → 系统设置 填写；env secret 存在时优先级更高）

| 设置 key | 干什么用 | 必填 | 不填会怎样 |
|---|---|---|---|
| `chat_title` | 聊天页标题 | 否 | 默认「在线客服」 |
| `admin_name` | 客服昵称 | 否 | 默认「客服 Live chat」 |
| `telegram_enabled` / `telegram_token` / `telegram_admin_id` | Telegram 通知开关/token/接收人 ID | 否 | Telegram 通知不可用（其余功能正常） |
| `wecom_enabled` / `wecom_webhook` | 企业微信通知开关/webhook | 否 | 企业微信通知不可用（其余功能正常） |

> 设计原则：除 `ADMIN_PASSWORD` 外，Telegram / 企业微信 / APP_BASE_URL 缺失时**功能降级而非崩溃**——聊天、图片、后台照常运行。

## 7. 部署后自定义

1. **站点域名**：默认 `workers.dev` 可用。若用自定义域名，在 Cloudflare 控制台 Worker → 设置 → 域名绑定（本仓库 wrangler.jsonc 未配置 routes，避免与你的真实域名冲突）。
2. **定时清理**：`wrangler.jsonc` 的 `triggers.crons` 已配置每日 03:00 UTC 清理（每客户端保最新 20% 至少 50 条消息；删除 90 天无消息且未活跃的僵尸客户端）。如需调整时间，改 cron 表达式后重新 deploy。

## 8. 常见报错对照表

| 报错信息 | 原因 | 解决办法 |
|---|---|---|
| `Missing credentials` / `Authentication error` / `Could not find account` | 未登录或 Token 无效 | `export CLOUDFLARE_API_TOKEN="cf_..."` 或 `npx wrangler login` |
| `You do not have permission to perform this action` | Token 权限不够 | 回看第 3 节，补 Workers Scripts / D1 / R2 的 Edit 权限 |
| `A database with the name ... already exists` | D1 已存在 | 复用现有 ID：`npx wrangler d1 list` 里找 ID 填进 wrangler.jsonc |
| `database_id` 未替换就 deploy | 忘跑 setup | `npm run setup` 或用 `npx wrangler d1 list` 回填 |
| `✘ [ERROR] Processing wrangler.jsonc: ... ` | wrangler.jsonc 语法错误 | 运行 `npm run check:config` 检查 |
| 迁移执行报 `table X already exists` | 重复执行迁移 | migrations 均为幂等（IF NOT EXISTS / OR IGNORE），可安全重跑 |
| `wrangler d1 execute` 很慢或超时 | 首次冷启动 / 网络 | 重试一次，或分段执行 |
| 部署后 404 | 未设置 `workers_dev` 或路由冲突 | 确认 wrangler.jsonc 有 `"workers_dev": true` |
| 后台提示「未初始化管理员密码」 | 未设置 ADMIN_PASSWORD | `npx wrangler secret put ADMIN_PASSWORD` 后重试登录 |
| Telegram 通知收不到 | token/ID 错、节流中、settings 表未启用 | 检查 telegram_enabled='1' 与 token；同客户端 600 秒内只推一次 |
| 图片打不开 | R2 bucket 未创建或绑定错 | `npx wrangler r2 bucket create lc-media`，确认 wrangler.jsonc 绑定 MEDIA |

## 9. 对 Agent 的硬性提示

- **不要**向任何第三方暴露 `CLOUDFLARE_API_TOKEN`、`ADMIN_PASSWORD`、Telegram/企业微信密钥。
- **不要**提交 `.dev.vars`、`reference/uploads/`（含真实聊天图片，隐私）、`*.db`、`.wrangler/`、`*.bak*`（已在 `.gitignore` 覆盖）。
- 每次部署前，确认 `wrangler.jsonc` 中 `database_id` 不是 `REPLACE_WITH_D1_DATABASE_ID`。
- 本仓库是公开模板：不要把真实生产域名、真实数据库 ID、真实 Bot Token、真实管理员密码写进代码或提交。
- 敏感扫描：提交前运行 `npm run scan:secrets`（应 0 命中）。
