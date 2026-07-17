const CACHE_NAME = 'os-journal-v13';
const STATIC_CACHE = 'os-static-v13';
const API_CACHE = 'os-api-v2';
const OFFLINE_URL = '/api/offline.html';
const PRECACHE_URLS = [
  '/api/offline.html',
  '/styles.css',
  '/dist/app.js',
  '/api/js/offline-cache.js',
  '/api/js/sync-manager.js',
];

// URLs cached WITHOUT query strings; runtime lookups use { ignoreSearch: true }
// so requests like /dist/app.js?v=30 still hit the cache offline.
const STATIC_ASSETS = [
  '/dist/app.js',
  '/api/js/appeals-ui.js',
  '/api/js/sessions-ui.js',
  '/api/js/calendar.js',
  '/api/js/pwa-install.js',
  '/api/js/notify-feed.js',
  '/api/js/export-center.js',
  '/api/js/admin-errors-ui.js',
  '/js/site-config.js',
  '/js/site-docs.js',
  '/api/manifest.json',
  '/api/icons/icon-192.png',
  '/api/icons/icon-512.png',
  '/assets/vendor/bootstrap.min.css',
  '/assets/vendor/bootstrap.bundle.min.js',
  '/assets/vendor/bootstrap-icons.css',
  '/assets/vendor/chart.umd.min.js',
  '/assets/vendor/inter.css',
  '/assets/vendor/fonts/bootstrap-icons.woff2',
  '/assets/vendor/fonts/inter-UcC73FwrK3iLTeHuS_nVMrMxCp50SjIa0ZL7SUc.woff2',
  '/assets/vendor/fonts/inter-UcC73FwrK3iLTeHuS_nVMrMxCp50SjIa1ZL7.woff2',
];

const API_ROUTES = [
  '/api/letters.php',
  '/api/members.php',
  '/api/events.php',
  '/api/config_public.php',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    // Add items one by one so a single 404 does not abort the whole install.
    caches.open(CACHE_NAME).then((cache) =>
      Promise.allSettled(PRECACHE_URLS.map((u) => cache.add(u).catch(() => {})))
    ).then(() => caches.open(STATIC_CACHE).then((cache) =>
      Promise.allSettled(STATIC_ASSETS.map((u) => cache.add(u).catch(() => {})))
    ))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  const keep = [CACHE_NAME, STATIC_CACHE, API_CACHE];
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => !keep.includes(k)).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

function isApiRoute(url) {
  return API_ROUTES.some((route) => url.pathname.startsWith(route));
}

function isStaticAsset(url) {
  return url.pathname.endsWith('.js') ||
    url.pathname.endsWith('.css') ||
    url.pathname.endsWith('.png') ||
    url.pathname.endsWith('.svg') ||
    url.pathname.endsWith('.woff2');
}

self.addEventListener('fetch', (event) => {
  // Non-GET requests (POST/PUT/DELETE) must never be cached or answered from
  // cache — let the browser handle them directly (offline queue lives in the app).
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) return;

  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request).catch(() => caches.match(OFFLINE_URL))
    );
    return;
  }

  if (isStaticAsset(url)) {
    // Network-first: always try to get the latest version, fall back to cache offline
    event.respondWith(
      fetch(event.request).then((response) => {
        if (response.ok) {
          const clone = response.clone();
          // Key by pathname (no query): otherwise every ?v=N bump adds a new
          // entry and an offline { ignoreSearch } match could return a stale
          // version cached under an older ?v=.
          caches.open(STATIC_CACHE).then((c) => c.put(url.pathname, clone));
        }
        return response;
      }).catch(() => caches.match(event.request, { ignoreSearch: true }))
    );
    return;
  }

  if (isApiRoute(url)) {
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          if (response.ok) {
            const clone = response.clone();
            // config_public.php is requested with ?v=N — key it by pathname so
            // only one (latest) copy exists and the ignoreSearch fallback can
            // never pick up a stale version.
            const key = url.pathname.endsWith('/config_public.php')
              ? url.pathname
              : event.request;
            caches.open(API_CACHE).then((c) => c.put(key, clone));
          }
          return response;
        })
        .catch(() =>
          caches.match(event.request).then((cached) => {
            if (cached) return cached;
            // config_public.php is loaded with ?v=N — allow a query-insensitive
            // fallback so offline initialization does not break.
            if (url.pathname.endsWith('/config_public.php')) {
              return caches.match(event.request, { ignoreSearch: true }).then((c) => {
                if (c) return c;
                return offlineJsonResponse();
              });
            }
            return offlineJsonResponse();
          })
        )
    );
    return;
  }

  event.respondWith(
    fetch(event.request).catch(() => caches.match(event.request, { ignoreSearch: true }))
  );
});

function offlineJsonResponse() {
  return new Response(JSON.stringify({ error: 'offline', cached: false }), {
    status: 503,
    headers: { 'Content-Type': 'application/json' },
  });
}

self.addEventListener('sync', (event) => {
  if (event.tag === 'offline-queue-sync') {
    event.waitUntil(syncOfflineQueue());
  }
});

async function syncOfflineQueue() {
  const clients = await self.clients.matchAll();
  clients.forEach((client) => {
    client.postMessage({ type: 'SYNC_START' });
  });

  try {
    const cache = await caches.open(API_CACHE);
    const keys = await cache.keys();
    await Promise.all(
      keys.map((req) =>
        fetch(req).then((resp) => {
          if (resp.ok) cache.put(req, resp);
        }).catch(() => {})
      )
    );
    clients.forEach((client) => {
      client.postMessage({ type: 'SYNC_COMPLETE' });
    });
  } catch (err) {
    clients.forEach((client) => {
      client.postMessage({ type: 'SYNC_ERROR', error: err.message });
    });
  }
}

self.addEventListener('message', (event) => {
  if (event.data?.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
  if (event.data?.type === 'CACHE_URLS') {
    const urls = event.data.urls || [];
    caches.open(STATIC_CACHE).then((c) =>
      Promise.allSettled(urls.map((u) => c.add(u)))
    );
  }
});

self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data?.json() ?? {}; } catch (e) { /* non-JSON payload */ }
  event.waitUntil(
    self.registration.showNotification(data.title || 'Журнал ОС', {
      body: data.body || '',
      icon: '/api/icons/icon-192.png',
      badge: '/api/icons/icon-192.png',
      tag: data.tag || 'os-journal',
      data: data.url || '/',
      renotify: true,
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil(clients.openWindow(event.notification.data));
});
