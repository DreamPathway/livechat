// LiveChat Cloudflare Worker 入口
// 路由：/ 聊天页 | /admin 管理后台 | /api/* API | /media/* 图片 | scheduled 定时清理
import { Hono } from 'hono';
import type { Env } from './db';
import { getSettingsMap } from './db';
import { chat } from './chat';
import { admin } from './admin';
import { runCleanup } from './cleanup';

const app = new Hono<{ Bindings: Env }>();

// ── 页面 ──
// / → index.html（聊天页）；/admin 由 Assets clean-URL 自动映射到 admin.html
app.get('/', async (c) => {
  const res = await c.env.ASSETS.fetch(new URL('/index.html', c.req.url));
  return new Response(res.body, {
    status: res.status,
    headers: { 'content-type': 'text/html; charset=utf-8', 'cache-control': 'no-cache' },
  });
});

// ── API ──
app.route('/api/chat', chat);
app.route('/api/admin', admin);

// 站点元信息（聊天页标题/客服名）
app.get('/api/meta', async (c) => {
  const map = await getSettingsMap(c.env.DB);
  return c.json({
    chat_title: map.chat_title || '在线客服',
    admin_name: map.admin_name || '客服 Live chat',
  });
});

// 健康检查
app.get('/api/health', (c) => c.json({ ok: true, ts: Date.now() }));

// ── 图片（R2）──
app.get('/media/*', async (c) => {
  const key = c.req.path.slice('/media/'.length);
  if (!key) return c.notFound();
  const obj = await c.env.MEDIA.get(key);
  if (!obj) return c.notFound();
  const h = new Headers();
  obj.writeHttpMetadata(h);
  h.set('etag', obj.httpEtag);
  h.set('cache-control', 'public, max-age=31536000, immutable');
  return new Response(obj.body, { headers: h });
});

// ── 定时清理（每天 03:00 UTC）──
export default {
  fetch: app.fetch,
  async scheduled(_event: ScheduledEvent, env: Env, ctx: ExecutionContext) {
    try {
      const summary = await runCleanup(env.DB);
      ctx.waitUntil(Promise.resolve(console.log(summary)));
    } catch (e) {
      console.error('[Cleanup] error:', e);
    }
  },
};
