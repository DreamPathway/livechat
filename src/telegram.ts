// Telegram 通知：新客户消息推送到管理员（带节流，与原版行为一致）
import type { Env } from './db';
import { getSetting, setSetting } from './db';

const NOTIFY_INTERVAL = 600; // 秒，与原版 TELEGRAM_NOTIFY_INTERVAL 一致

/**
 * 发送新客服消息通知。返回是否真正发送。
 * 节流：同一 client 在 NOTIFY_INTERVAL 秒内不重复通知（存 D1 settings）。
 */
export async function sendTelegramNotification(
  env: Env,
  db: D1Database,
  clientIP: string,
  clientId: string,
  message: string,
  hasImage = false,
  adminUrl: string
): Promise<boolean> {
  const settings = await getSetting(db, 'telegram_enabled');
  const enabled = (env.TELEGRAM_BOT_TOKEN ? '1' : settings ?? '0') === '1';
  const token = env.TELEGRAM_BOT_TOKEN || (await getSetting(db, 'telegram_token')) || '';
  const adminId = env.TELEGRAM_ADMIN_ID || (await getSetting(db, 'telegram_admin_id')) || '';
  if (!enabled || !token || !adminId) return false;

  // 节流：同客户不重复轰炸
  const lastKey = `last_tg_${clientId}`;
  const last = Number(await getSetting(db, lastKey)) || 0;
  const now = Math.floor(Date.now() / 1000);
  if (now - last < NOTIFY_INTERVAL) return false;

  const truncatedMsg = (hasImage ? '[图片消息] ' : '') +
    (message.length > 100 ? message.slice(0, 100) + '...' : message);

  const timeStr = new Date().toLocaleString('zh-CN', { hour12: false });
  const text =
    `📱 *新客服消息*\n` +
    `─────────────────\n` +
    `👤 *IP:* \`${clientIP}\`\n` +
    `⏰ *时间:* ${timeStr}\n` +
    `💬 *内容:* ${truncatedMsg}\n` +
    `─────────────────\n` +
    `[👨‍💻 进入管理后台](${adminUrl})`;

  try {
    const resp = await fetch(`https://api.telegram.org/bot${token}/sendMessage`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        chat_id: adminId,
        text,
        parse_mode: 'Markdown',
        disable_web_page_preview: false,
      }),
    });
    if (resp.ok) {
      await setSetting(db, lastKey, String(now));
      return true;
    }
    console.error('[Telegram] send failed:', resp.status, await resp.text());
  } catch (e) {
    console.error('[Telegram] error:', e);
  }
  return false;
}
