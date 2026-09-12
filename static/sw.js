/**
 * LiveChat 客服管理后台 — Service Worker（PWA）
 *
 * 策略说明：
 *  - 仅缓存管理后台的静态资源（admin.html / manifest / 图标），不缓存聊天 API 与访客页面
 *  - admin.html 走 Network-First：保证后台更新及时生效，离线时回退缓存
 *  - 图标 / manifest 走 Cache-First：版本化路径，长缓存
 *  - 其余请求（API / 访客页 / 媒体）一律直接放行，不拦截
 */
const VERSION = 'lc-admin-v1';
const CACHE_NAME = VERSION;

const PRECACHE = [
  '/admin',
  '/manifest.webmanifest',
  '/icons/icon.svg',
];

// 安装：预缓存管理后台入口
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting())
  );
});

// 激活：清理旧版本缓存
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

// 请求处理
self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  // 只处理本站同源请求
  if (url.origin !== self.location.origin) return;

  // 静态资源：缓存优先（带版本路径，无需更新）
  if (url.pathname.startsWith('/icons/') || url.pathname === '/manifest.webmanifest') {
    event.respondWith(
      caches.match(request).then((cached) => cached || fetch(request).then((res) => {
        const clone = res.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
        return res;
      }))
    );
    return;
  }

  // 管理后台入口：网络优先，离线回退缓存
  if (url.pathname === '/' || url.pathname === '/admin' || url.pathname === '/admin.html') {
    event.respondWith(
      fetch(request)
        .then((res) => {
          // 只缓存成功响应
          if (res && res.status === 200) {
            const clone = res.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
          }
          return res;
        })
        .catch(() => caches.match(request).then((cached) => cached || caches.match('/admin')))
    );
    return;
  }

  // 其余请求（/api/*、/media/*、访客页等）不拦截
});
