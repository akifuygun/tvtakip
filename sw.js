// Service worker: stale-while-revalidate for same-origin STATIC assets only.
// HTML pages and /api/* pass straight through to the network so authenticated,
// per-user content is never served stale or to the wrong session.
const CACHE = 'tvtakip-v1';
const STATIC = /\.(css|js|png|svg|webmanifest|woff2?|ico)$/;

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  // Only handle our own static assets; everything else (HTML, API,
  // cross-origin) uses the default network behaviour.
  if (url.origin !== location.origin || !STATIC.test(url.pathname)) return;

  event.respondWith(
    caches.open(CACHE).then(async (cache) => {
      const cached = await cache.match(req);
      const network = fetch(req)
        .then((res) => {
          // Only cache genuine same-origin 200s (avoids caching an anti-bot
          // interstitial or error page in place of an asset).
          if (res.ok && res.type === 'basic') cache.put(req, res.clone());
          return res;
        })
        .catch(() => cached);
      return cached || network;
    }),
  );
});
