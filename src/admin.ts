// 管理后台 API：登录 / 客户 / 消息 / 设置 / 改密码
import { Hono } from 'hono';
import type { Env } from './db';
import { getSetting, getSettingsMap, setSetting } from './db';
import {
  clearSessionCookie,
  hashPassword,
  readCookie,
  rotateSessionSecret,
  sessionCookie,
  SESSION_COOKIE,
  signSession,
  verifyPassword,
  verifySession,
} from './auth';
import { escapeHtml } from './chat';
import { randomId } from './util';

export const admin = new Hono<{ Bindings: Env }>();

// 登录
admin.post('/login', async (c) => {
  const body = await c.req.parseBody();
  const password = String(body.password || '');

  // 首次登录：无 hash 时用 env.ADMIN_PASSWORD 初始化
  let storedHash = await getSetting(c.env.DB, 'admin_password_hash');
  if (!storedHash && c.env.ADMIN_PASSWORD) {
    if (password === c.env.ADMIN_PASSWORD) {
      storedHash = await hashPassword(password);
      await setSetting(c.env.DB, 'admin_password_hash', storedHash);
    }
  }
  if (!storedHash) {
    return c.json({ success: false, error: '未初始化管理员密码，请通过 wrangler secret 设置 ADMIN_PASSWORD 后重试' });
  }

  const ok = await verifyPassword(password, storedHash);
  if (!ok) return c.json({ success: false, error: '密码错误' });

  const token = await signSession(c.env.DB);
  return c.json({ success: true }, 200, { 'set-cookie': sessionCookie(token) });
});

// 登出
admin.post('/logout', (c) => {
  return c.json({ success: true }, 200, { 'set-cookie': clearSessionCookie() });
});

// 改密码
admin.post('/change_password', async (c) => {
  if (!(await authed(c))) return c.json({ success: false, error: '未登录' }, 401);
  const body = await c.req.parseBody();
  const oldPw = String(body.old_password || '');
  const newPw = String(body.new_password || '');
  if (newPw.length < 4) return c.json({ success: false, error: '密码长度至少4位' });

  const storedHash = await getSetting(c.env.DB, 'admin_password_hash');
  if (!storedHash) return c.json({ success: false, error: '管理员密码未初始化' });
  const ok = await verifyPassword(oldPw, storedHash);
  if (!ok) return c.json({ success: false, error: '原密码错误' });

  await setSetting(c.env.DB, 'admin_password_hash', await hashPassword(newPw));
  await rotateSessionSecret(c.env.DB); // 旧 cookie 全部失效
  return c.json({ success: true });
});

// ── 客户与消息 ──
admin.post('/get_clients', async (c) => {
  if (!(await authed(c))) return c.json({ success: false, error: '未登录' }, 401);
  const db = c.env.DB;
  const { results } = await db
    .prepare(
      `SELECT c.client_id, c.nickname, c.ip, c.last_active, c.is_online, c.created_at,
        (SELECT COUNT(*) FROM messages m WHERE m.client_id = c.client_id AND m.is_admin = 0 AND m.is_read = 0) as unread,
        (SELECT m.message FROM messages m WHERE m.client_id = c.client_id ORDER BY m.timestamp DESC LIMIT 1) as last_message,
        (SELECT m.timestamp FROM messages m WHERE m.client_id = c.client_id ORDER BY m.timestamp DESC LIMIT 1) as last_time
       FROM clients c ORDER BY c.last_active DESC LIMIT 200`
    )
    .all<Record<string, unknown>>();

  const clients = results.map((row) => {
    const lastMsg = row.last_message ? String(row.last_message) : '';
    const short = lastMsg.length > 30 ? lastMsg.slice(0, 30) + '...' : lastMsg || '无消息';
    return { ...row, last_message: short };
  });
  return c.json(clients);
});

admin.post('/get_messages', async (c) => {
  if (!(await authed(c))) return c.json({ success: false, error: '未登录' }, 401);
  const body = await c.req.parseBody();
  const clientId = String(body.client_id || '');
  if (!clientId) return c.html('<div class="empty-msg">缺少 client_id</div>');

  const db = c.env.DB;
  const { results } = await db
    .prepare('SELECT * FROM messages WHERE client_id = ? ORDER BY timestamp DESC LIMIT 100')
    .bind(clientId)
    .all<Record<string, unknown>>();

  await db
    .prepare('UPDATE messages SET is_read = 1 WHERE client_id = ? AND is_admin = 0 AND is_read = 0')
    .bind(clientId)
    .run();

  if (!results.length) return c.html('<div class="empty-msg">暂无消息</div>');
  const html = results
    .map((m) => renderAdminMessage(m))
    .join('');
  return c.html(html);
});

admin.post('/send_message', async (c) => {
  if (!(await authed(c))) return c.json({ success: false, error: '未登录' }, 401);
  const body = await c.req.parseBody();
  const clientId = String(body.client_id || '');
  const message = String(body.message || '').trim();
  const adminName = (await getSetting(c.env.DB, 'admin_name')) || '客服 Live chat';

  let imageUrl: string | null = null;
  const file = body.image;
  if (file instanceof File) {
    if (file.size > 5 * 1024 * 1024) return c.json({ success: false, error: '文件大小不能超过5MB' });
    const ext = (file.type.split('/')[1] || 'jpg').replace(/[^a-z0-9]/g, '');
    const key = `lc_${Date.now()}_${randomId(4)}.${ext}`;
    await c.env.MEDIA.put(key, file.stream(), { httpMetadata: { contentType: file.type } });
    imageUrl = key;
  }

  if (!message && !imageUrl) return c.json({ success: false, error: '消息不能为空' });

  const messageId = `msg_${Math.floor(Date.now() / 1000)}_${randomId(4)}`;
  await c.env.DB
    .prepare(
      `INSERT INTO messages (message_id, client_id, sender, sender_name, message, image_url, is_admin, is_read, timestamp)
       VALUES (?, ?, 'admin', ?, ?, ?, 1, 0, ?)`
    )
    .bind(
      messageId,
      clientId,
      adminName.slice(0, 128),
      message ? escapeHtml(message) : '[图片消息]',
      imageUrl,
      Math.floor(Date.now() / 1000)
    )
    .run();

  return c.json({ success: true, message_id: messageId });
});

admin.post('/delete_message', async (c) => {
  if (!(await authed(c))) return c.json({ success: false, error: '未登录' }, 401);
  const body = await c.req.parseBody();
  const clientId = String(body.client_id || '');
  const messageId = String(body.message_id || '');
  await c.env.DB
    .prepare('DELETE FROM messages WHERE message_id = ? AND client_id = ?')
    .bind(messageId, clientId)
    .run();
  return c.json({ success: true });
});

admin.post('/mark_read', async (c) => {
  if (!(await authed(c))) return c.json({ success: false, error: '未登录' }, 401);
  const body = await c.req.parseBody();
  const clientId = String(body.client_id || '');
  await c.env.DB
    .prepare('UPDATE messages SET is_read = 1 WHERE client_id = ? AND is_admin = 0')
    .bind(clientId)
    .run();
  return c.json({ success: true });
});

admin.post('/check_new', async (c) => {
  if (!(await authed(c))) return c.json({ success: false, error: '未登录' }, 401);
  const body = await c.req.parseBody();
  const clientId = String(body.client_id || '');
  const db = c.env.DB;
  if (clientId) {
    const row = await db
      .prepare('SELECT COUNT(*) as c FROM messages WHERE client_id = ? AND is_admin = 0 AND is_read = 0')
      .bind(clientId)
      .first<{ c: number }>();
    return c.json({ has_new: (row?.c ?? 0) > 0, client_unread: row?.c ?? 0 });
  }
  const row = await db
    .prepare('SELECT COUNT(*) as c FROM messages WHERE is_admin = 0 AND is_read = 0')
    .first<{ c: number }>();
  return c.json({ has_new: (row?.c ?? 0) > 0, total_unread: row?.c ?? 0 });
});

admin.post('/delete_client', async (c) => {
  if (!(await authed(c))) return c.json({ success: false, error: '未登录' }, 401);
  const body = await c.req.parseBody();
  const clientId = String(body.client_id || '');
  await c.env.DB.prepare('DELETE FROM messages WHERE client_id = ?').bind(clientId).run();
  await c.env.DB.prepare('DELETE FROM clients WHERE client_id = ?').bind(clientId).run();
  return c.json({ success: true });
});

// ── 设置 ──
admin.post('/get_settings', async (c) => {
  if (!(await authed(c))) return c.json({ success: false, error: '未登录' }, 401);
  const map = await getSettingsMap(c.env.DB);
  return c.json({
    chat_title: map.chat_title ?? '在线客服',
    admin_name: map.admin_name ?? '客服 Live chat',
    telegram_enabled: map.telegram_enabled ?? '1',
    telegram_token: c.env.TELEGRAM_BOT_TOKEN ? '***ENV***' : (map.telegram_token ?? ''),
    telegram_admin_id: c.env.TELEGRAM_ADMIN_ID || (map.telegram_admin_id ?? ''),
    wecom_enabled: map.wecom_enabled ?? '0',
    wecom_webhook: c.env.WECOM_WEBHOOK_URL ? '***ENV***' : (map.wecom_webhook ?? ''),
  });
});

admin.post('/update_settings', async (c) => {
  if (!(await authed(c))) return c.json({ success: false, error: '未登录' }, 401);
  const body = await c.req.parseBody();
  const keys = [
    'chat_title',
    'admin_name',
    'telegram_enabled',
    'telegram_token',
    'telegram_admin_id',
    'wecom_enabled',
    'wecom_webhook',
  ] as const;
  for (const key of keys) {
    if (body[key] === undefined) continue;
    // env secret 覆盖时，前端提交的 ***ENV*** 不应覆盖 DB 里的空值
    if ((key === 'telegram_token' || key === 'wecom_webhook') && String(body[key]) === '***ENV***') continue;
    await setSetting(c.env.DB, key, String(body[key]));
  }
  return c.json({ success: true });
});

// 管理员头像（存 R2，固定 key）
admin.post('/upload_avatar', async (c) => {
  if (!(await authed(c))) return c.json({ success: false, error: '未登录' }, 401);
  const body = await c.req.parseBody();
  const file = body.avatar;
  if (!(file instanceof File)) return c.json({ success: false, error: '未收到上传文件或上传出错' });
  const allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
  if (!allowed.includes(file.type)) return c.json({ success: false, error: '只支持 JPG, PNG, GIF, WEBP 格式' });
  if (file.size > 2 * 1024 * 1024) return c.json({ success: false, error: '文件大小不能超过 2MB' });
  await c.env.MEDIA.put('admin_avatar.jpg', file.stream(), {
    httpMetadata: { contentType: file.type },
  });
  return c.json({ success: true });
});

// ── 认证辅助 ──
async function authed(c: { env: Env; req: { raw: Request } }): Promise<boolean> {
  const token = readCookie(c.req.raw, SESSION_COOKIE);
  return verifySession(c.env.DB, token);
}

// ── 渲染 ──
function renderAdminMessage(m: Record<string, unknown>): string {
  const message = String(m.message ?? '');
  const imageUrl = m.image_url ? String(m.image_url) : '';
  const isAdmin = Number(m.is_admin) === 1;
  const ts = Number(m.timestamp);
  const time = new Date(ts * 1000).toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit', hour12: false });
  const sender = isAdmin ? '👨‍💼 管理员' : '👤 ' + escapeHtml(String(m.sender_name ?? ''));
  const msgClass = isAdmin ? 'admin-msg' : 'client-msg';
  const unreadClass = !isAdmin && Number(m.is_read) === 0 ? 'unread' : '';

  let content = String(m.message ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\n/g, '<br>');
  content = content.replace(
    /(https?:\/\/[^\s<]+)/gi,
    '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>'
  );

  let imageHtml = '';
  if (imageUrl) {
    imageHtml =
      `<div class="msg-image" onclick="viewImage('/media/${imageUrl.replace(/"/g, '')}')">` +
      `<img src="/media/${imageUrl.replace(/"/g, '')}" alt="图片"><span>🖼️ 点击查看</span></div>`;
  }

  return (
    `<div class="message ${msgClass} ${unreadClass}" data-id="${String(m.message_id).replace(/"/g, '')}">` +
    `<div class="msg-hd">` +
    `<span class="sender">${sender}</span>` +
    `<span class="time">${time}</span>` +
    `<button class="del-btn" onclick="deleteMsg('${String(m.message_id).replace(/'/g, '')}')">×</button>` +
    `</div>` +
    `<div class="msg-ct">${content}</div>` +
    imageHtml +
    `</div>`
  );
}
