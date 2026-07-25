/*
 * Yoked service worker.
 *
 * Carried over from Friendspace, where the strategy below was the fix for the
 * "users don't see updates" trap. Deliberately conservative:
 *   - /api/*             → never touched (auth + live data always hit the network)
 *   - /assets/*, /fonts/ → cache-first (content-hashed or effectively immutable)
 *   - navigations/HTML   → network-first, last good shell as offline fallback
 *
 * Because HTML is network-first and asset URLs change per build, new deploys
 * always load fresh. skipWaiting + clients.claim activate updates immediately.
 *
 * One change from Friendspace: /fonts/ is cached here. Friendspace could ignore
 * fonts because they were cross-origin; Yoked self-hosts them, so without this
 * rule an offline launch would fall back to system faces.
 */
const CACHE = 'yoked-cache-v1';

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;   // leave cross-origin alone
  if (url.pathname.startsWith('/api/')) return;      // never cache API / auth / media

  // Immutable build assets and self-hosted fonts → cache-first.
  if (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/fonts/')) {
    event.respondWith((async () => {
      const cache = await caches.open(CACHE);
      const hit = await cache.match(req);
      if (hit) return hit;
      const res = await fetch(req);
      if (res.ok) cache.put(req, res.clone());
      return res;
    })());
    return;
  }

  // App shell / navigations → network-first so deploys always show up.
  if (req.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        const res = await fetch(req);
        const cache = await caches.open(CACHE);
        cache.put('/', res.clone());
        return res;
      } catch (e) {
        const cache = await caches.open(CACHE);
        const fallback = await cache.match('/');
        if (fallback) return fallback;
        throw e;
      }
    })());
  }
});
