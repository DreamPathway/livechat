// 企业微信机器人通知：新客户消息推送到企业微信群（带节流，与 Telegram 通知行为一致）
import type { Env } from './db';
import { getSetting, setSetting } from './db';

const NOTIFY_INTERVAL = 600; // 秒，与 Telegram 通知节流一致

/**
 * 发送新客服消息到企业微信群机器人。返回是否真正发送。
 * 节流：同一 client 在 NOTIFY_INTERVAL 秒内不重复通知（存 D1 settings）。
 * webhook 支持两种写法：完整 URL，或仅填 key（自动补全 qyapi 地址）。
 */
export async function sendWecomNotification(
  env: Env,
  db: D1Database,
  clientIP: string,
  clientId: string,
  message: string,
  hasImage = false,
  adminUrl: string
): Promise<boolean> {
  const settings = await getSetting(db, 'wecom_enabled');
  const enabled = (env.WECOM_WEBHOOK_URL ? '1' : settings ?? '0') === '1';
  let webhook = env.WECOM_WEBHOOK_URL || (await getSetting(db, 'wecom_webhook')) || '';
  if (!enabled || !webhook) return false;

  // 只填了 key（形如 693a91f6-xxxx）时补全完整 webhook 地址
  if (!/^https?:\/\//i.test(webhook)) {
    webhook = `https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=${webhook}`;
  }

  // 节流：同客户不重复轰炸
  const lastKey = `last_wecom_${clientId}`;
  const last = Number(await getSetting(db, lastKey)) || 0;
  const now = Math.floor(Date.now() / 1000);
  if (now - last < NOTIFY_INTERVAL) return false;

  const truncatedMsg =
    (hasImage ? '[图片消息] ' : '') + (message.length > 100 ? message.slice(0, 100) + '...' : message);

  const timeStr = new Date().toLocaleString('zh-CN', { hour12: false });
  const content =
    `📱 新客服消息\n` +
    `─────────────\n` +
    `👤 IP：${clientIP}\n` +
    `⏰ 时间：${timeStr}\n` +
    `💬 内容：${truncatedMsg}\n` +
    `─────────────\n` +
    `[进入管理后台](${adminUrl})`;

  try {
    const resp = await fetch(webhook, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        msgtype: 'markdown',
        markdown: { content },
      }),
    });
    const data = (await resp.json().catch(() => null)) as { errcode?: number } | null;
    // errcode === 0 表示推送成功
    if (resp.ok && data && data.errcode === 0) {
      await setSetting(db, lastKey, String(now));
      return true;
    }
    console.error('[WeCom] send failed:', resp.status, JSON.stringify(data));
  } catch (e) {
    console.error('[WeCom] error:', e);
  }
  return false;
}
