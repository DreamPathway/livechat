// 共享类型与数据库访问工具
export interface Env {
  DB: D1Database;
  MEDIA: R2Bucket;
  ASSETS: Fetcher;
  // 首次登录使用的初始管理员密码（部署后写入 hash，此 secret 可删除）
  ADMIN_PASSWORD?: string;
  // 可选：env 覆盖 Telegram 配置（优先级高于 settings 表）
  TELEGRAM_BOT_TOKEN?: string;
  TELEGRAM_ADMIN_ID?: string;
  // 可选：env 覆盖企业微信机器人 webhook（优先级高于 settings 表）
  WECOM_WEBHOOK_URL?: string;
  APP_BASE_URL?: string;
}

export interface Client {
  client_id: string;
  user_id: string | null;
  nickname: string;
  email: string | null;
  phone: string | null;
  ip: string;
  user_agent: string | null;
  first_visit: number;
  last_active: number;
  is_online: number;
}

export interface Message {
  id: number;
  message_id: string;
  client_id: string;
  sender: string;
  sender_name: string;
  message: string;
  image_url: string | null;
  is_admin: number;
  is_read: number;
  timestamp: number;
}

export interface ChatSettings {
  chat_title: string;
  admin_name: string;
  telegram_enabled: string;
  telegram_token: string;
  telegram_admin_id: string;
  wecom_enabled: string;
  wecom_webhook: string;
  [k: string]: string | undefined;
}

// 读取全部设置（扁平 map）
export async function getSettingsMap(db: D1Database): Promise<Record<string, string>> {
  const { results } = await db
    .prepare('SELECT setting_key, setting_value FROM settings')
    .all<{ setting_key: string; setting_value: string }>();
  const map: Record<string, string> = {};
  for (const r of results) map[r.setting_key] = r.setting_value ?? '';
  return map;
}

// 读取单条设置
export async function getSetting(db: D1Database, key: string): Promise<string> {
  const row = await db
    .prepare('SELECT setting_value FROM settings WHERE setting_key = ?')
    .bind(key)
    .first<{ setting_value: string }>();
  return row?.setting_value ?? '';
}

// 写入设置（upsert）
export async function setSetting(db: D1Database, key: string, value: string): Promise<void> {
  await db
    .prepare(
      `INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, datetime('now'))
       ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = datetime('now')`
    )
    .bind(key, value)
    .run();
}

// 有效的 Telegram 配置（settings 表优先，env secret 可覆盖）
export function telegramConfig(env: Env, s: Record<string, string>) {
  const enabled = (env.TELEGRAM_BOT_TOKEN ? '1' : s.telegram_enabled ?? '0') === '1';
  const token = env.TELEGRAM_BOT_TOKEN || s.telegram_token || '';
  const adminId = env.TELEGRAM_ADMIN_ID || s.telegram_admin_id || '';
  return { enabled, token, adminId };
}

// 有效的企业微信机器人配置（settings 表优先，env secret 可覆盖）
export function wecomConfig(env: Env, s: Record<string, string>) {
  const enabled = (env.WECOM_WEBHOOK_URL ? '1' : s.wecom_enabled ?? '0') === '1';
  const webhook = env.WECOM_WEBHOOK_URL || s.wecom_webhook || '';
  return { enabled, webhook };
}
