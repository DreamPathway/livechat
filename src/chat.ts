// 客户端聊天 API：init_client / send / get_messages / check_new
import { Hono } from 'hono';
import type { Env } from './db';
import { getSetting, setSetting } from './db';
import { sendTelegramNotification } from './telegram';
import { sendWecomNotification } from './wecom';
import { randomId } from './util';

export const chat = new Hono<{ Bindings: Env }>();

// 生成客户端 ID（与原版一致：guest_ + md5(ip)；无登录系统，统一 guest）
function clientIdFromIP(ip: string): string {
  // 简单非加密 hash（32 hex），保持原版 guest_ 前缀语义
  let h1 = 0xdeadbeef ^ ip.length, h2 = 0x41c6ce57 ^ ip.length;
  for (let i = 0; i < ip.length; i++) {
    const ch = ip.charCodeAt(i);
    h1 = Math.imul(h1 ^ ch, 2654435761);
    h2 = Math.imul(h2 ^ ch, 1597334677);
  }
  h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909);
  h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909);
  return 'guest_' + (h2 >>> 0).toString(16).padStart(8, '0') + (h1 >>> 0).toString(16).padStart(8, '0');
}

function clientIP(c: { env: Env; req: { header: (n: string) => string | undefined } }): string {
  const cf = c.req.header('CF-Connecting-IP');
  if (cf) return cf;
  const xff = c.req.header('X-Forwarded-For');
  if (xff) return xff.split(',')[0].trim();
  return '127.0.0.1';
}

const MAX_MESSAGE_HISTORY = 100;

export async function ensureClient(
  db: D1Database,
  clientId: string,
  nickname: string,
  ip: string,
  userAgent: string | undefined
): Promise<void> {
  const now = Math.floor(Date.now() / 1000);
  const existing = await db
    .prepare('SELECT id FROM clients WHERE client_id = ?')
    .bind(clientId)
    .first();
  if (!existing) {
    await db
      .prepare(
        `INSERT INTO clients (client_id, user_id, nickname, email, phone, ip, user_agent, first_visit, last_active, is_online)
         VALUES (?, NULL, ?, NULL, NULL, ?, ?, ?, ?, 1)`
      )
      .bind(clientId, nickname.slice(0, 128), ip, userAgent, now, now)
      .run();
  } else {
    await db
      .prepare('UPDATE clients SET last_active = ?, is_online = 1 WHERE client_id = ?')
      .bind(now, clientId)
      .run();
  }
}

chat.post('/', async (c) => {
  const db = c.env.DB;
  const ip = clientIP(c);
  const body = await c.req.parseBody();
  const action = String(body.action || '');
  const clientId = String(body.client_id || clientIdFromIP(ip));
  const nickname = String(body.nickname || ip);

  switch (action) {
    case 'init_client': {
      await ensureClient(db, clientId, nickname, ip, c.req.header('user-agent'));
      return c.json({ success: true, client_id: clientId });
    }

    case 'send': {
      const message = String(body.message || '').trim();
      let imageUrl: string | null = null;

      const file = body.image;
      if (file instanceof File) {
        const maxSize = 5 * 1024 * 1024;
        if (file.size > maxSize) return c.json({ success: false, error: '文件大小不能超过5MB' });
        if (!file.type.startsWith('image/')) return c.json({ success: false, error: '只能上传图片文件' });
        const ext = (file.type.split('/')[1] || 'jpg').replace(/[^a-z0-9]/g, '');
        const key = `lc_${Date.now()}_${randomId(4)}.${ext}`;
        await c.env.MEDIA.put(key, file.stream(), { httpMetadata: { contentType: file.type } });
        imageUrl = key;
      }

      if (!message && !imageUrl) return c.json({ success: false, error: '消息不能为空' });

      await ensureClient(db, clientId, nickname, ip, c.req.header('user-agent'));

      const messageId = `msg_${Math.floor(Date.now() / 1000)}_${randomId(4)}`;
      await db
        .prepare(
          `INSERT INTO messages (message_id, client_id, sender, sender_name, message, image_url, is_admin, is_read, timestamp)
           VALUES (?, ?, 'client', ?, ?, ?, 0, 0, ?)`
        )
        .bind(
          messageId,
          clientId,
          nickname.slice(0, 128),
          message ? escapeHtml(message) : '[图片消息]',
          imageUrl,
          Math.floor(Date.now() / 1000)
        )
        .run();

      await db
        .prepare('UPDATE clients SET last_active = ? WHERE client_id = ?')
        .bind(Math.floor(Date.now() / 1000), clientId)
        .run();

      // Telegram 通知（后台异步发送，不阻塞响应）
      const base = c.env.APP_BASE_URL || new URL(c.req.url).origin;
      c.executionCtx.waitUntil(
        sendTelegramNotification(c.env, db, ip, clientId, message || '[图片消息]', !!imageUrl, `${base}/admin`)
      );
      // 企业微信机器人通知（后台异步发送，与 Telegram 并行）
      c.executionCtx.waitUntil(
        sendWecomNotification(c.env, db, ip, clientId, message || '[图片消息]', !!imageUrl, `${base}/admin`)
      );

      return c.json({ success: true, message_id: messageId, image_url: imageUrl });
    }

    case 'get_messages': {
      const rows = await db
        .prepare(
          `SELECT * FROM messages WHERE client_id = ? ORDER BY timestamp ASC LIMIT ${MAX_MESSAGE_HISTORY}`
        )
        .bind(clientId)
        .all<Record<string, unknown>>();

      // 管理员回复标记为已读
      await db
        .prepare('UPDATE messages SET is_read = 1 WHERE client_id = ? AND is_admin = 1 AND is_read = 0')
        .bind(clientId)
        .run();

      if (!rows.results.length) {
        return c.html('<div class="empty-chat">暂无消息，开始聊天吧 💬</div>');
      }
      const adminName = (await getSetting(db, 'admin_name')) || '客服 Live chat';
      const html = rows.results
        .map((m) => renderClientMessage(m, adminName))
        .join('');
      return c.html(html);
    }

    case 'check_new': {
      const row = await db
        .prepare(
          'SELECT COUNT(*) as count FROM messages WHERE client_id = ? AND is_admin = 1 AND is_read = 0'
        )
        .bind(clientId)
        .first<{ count: number }>();
      const count = row?.count ?? 0;
      return c.json({ has_new: count > 0, count });
    }

    default:
      return c.json({ success: false, error: `未知操作: ${action}` });
  }
});

// 客户端消息渲染（保留原版 HTML 片段格式，前端直接 innerHTML）
function renderClientMessage(m: Record<string, unknown>, adminName: string): string {
  const message = String(m.message ?? '');
  const imageUrl = m.image_url ? String(m.image_url) : '';
  const isAdmin = Number(m.is_admin) === 1;
  const ts = Number(m.timestamp);
  const time = new Date(ts * 1000).toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit', hour12: false });
  const sender = isAdmin
    ? '👨‍💼 ' + escapeHtml(adminName)
    : '👤 ' + escapeHtml(String(m.sender_name ?? ''));

  let content = makeLinksClickable(escapeHtml(message).replace(/\n/g, '<br>'));
  let imageHtml = '';
  if (imageUrl) {
    imageHtml =
      `<div class="chat-image-container" data-image="${escapeAttr(imageUrl)}">` +
      `<img src="/media/${escapeAttr(imageUrl)}" alt="图片" class="chat-image">` +
      `<div class="image-overlay">🖼️ 点击查看</div></div>`;
  }

  return (
    `<div class="message ${isAdmin ? 'admin-msg' : 'client-msg'}" data-message-id="${escapeAttr(String(m.message_id))}">` +
    `<div class="msg-header">` +
    `<span class="sender">${sender}</span>` +
    `<span class="time">${time}</span>` +
    `</div>` +
    `<div class="msg-content">${content}</div>` +
    imageHtml +
    `</div>`
  );
}

// ── 小工具 ──
export function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function escapeAttr(s: string): string {
  return escapeHtml(s);
}

export function makeLinksClickable(text: string): string {
  return text.replace(
    /(https?:\/\/[^\s<]+)/gi,
    '<a href="$1" target="_blank" rel="noopener noreferrer" class="chat-link">$1</a>'
  );
}
