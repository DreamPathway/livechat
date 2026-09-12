// validate-jsonc.mjs — 校验 wrangler.jsonc 语法（在项目根目录运行：node scripts/validate-jsonc.mjs）
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const c = readFileSync(join(ROOT, 'wrangler.jsonc'), 'utf8');
// 去行注释、去块注释、去尾逗号
let s = c
  .replace(/^\s*\/\/.*$/gm, '')
  .replace(/\/\*[\s\S]*?\*\//g, '')
  .replace(/,\s*([}\]])/g, '$1')
  .trim();
try {
  const j = JSON.parse(s);
  console.log('JSONC 合法，顶层键:', Object.keys(j).join(', '));
  console.log('main =', j.main);
  console.log('compatibility_date =', j.compatibility_date);
  console.log('workers_dev =', j.workers_dev);
  console.log('assets directory =', j.assets?.directory, '| binding =', j.assets?.binding);
  console.log('d1 database_name =', j.d1_databases?.[0]?.database_name, '| database_id =', j.d1_databases?.[0]?.database_id);
  console.log('r2 bucket =', j.r2_buckets?.[0]?.bucket_name, '| binding =', j.r2_buckets?.[0]?.binding);
  console.log('crons =', j.triggers?.crons?.join(', ') || '(无)');
  if (j.d1_databases?.[0]?.database_id === 'REPLACE_WITH_D1_DATABASE_ID') {
    console.log('⚠️  database_id 仍是占位符，请先运行 npm run setup');
    process.exit(2);
  }
} catch (e) {
  console.log('JSONC 解析失败:', e.message);
  process.exit(1);
}
