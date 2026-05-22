const CACHE_VERSION = 'v1';
const STATIC_CACHE  = `gp-static-${CACHE_VERSION}`;
const IMAGE_CACHE   = `gp-images-${CACHE_VERSION}`;
const PAGE_CACHE    = `gp-pages-${CACHE_VERSION}`;

// Assets pre-cached on install
const PRECACHE_URLS = [
    '/offline.html',
    '/android-chrome-192x192.png',
    '/android-chrome-512x512.png',
    '/apple-touch-icon.png',
];

// ── Install ──────────────────────────────────────────────────────────────────
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(PRECACHE_URLS))
            .then(() => self.skipWaiting())
    );
});

// ── Activate — purge old caches ──────────────────────────────────────────────
self.addEventListener('activate', (event) => {
    const KEEP = [STATIC_CACHE, IMAGE_CACHE, PAGE_CACHE];
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((k) => !KEEP.includes(k)).map((k) => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

// ── Fetch strategy ───────────────────────────────────────────────────────────
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Never intercept: API calls, Filament panel, admin panel, POST/non-GET
    if (
        request.method !== 'GET' ||
        url.pathname.startsWith('/api/') ||
        url.pathname.startsWith('/plug/') ||
        url.pathname.startsWith('/admin/') ||
        url.pathname.startsWith('/livewire/')
    ) {
        return;
    }

    // Vite build assets — Cache-first (content-hashed filenames, safe forever)
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(request, STATIC_CACHE));
        return;
    }

    // Static files — Cache-first
    if (
        url.pathname.match(/\.(ico|png|jpg|jpeg|webp|svg|woff2?|ttf|otf)$/) &&
        !url.pathname.startsWith('/storage/')
    ) {
        event.respondWith(cacheFirst(request, IMAGE_CACHE));
        return;
    }

    // Product images from storage — Cache-first with long TTL
    if (url.pathname.startsWith('/storage/')) {
        event.respondWith(cacheFirst(request, IMAGE_CACHE));
        return;
    }

    // POS shell — Cache-first (it's a SPA; React Router handles sub-routes)
    if (url.pathname.startsWith('/pos')) {
        event.respondWith(cacheFirst(request, STATIC_CACHE));
        return;
    }

    // All other pages (storefront) — Network-first, fall back to cache, then offline page
    event.respondWith(networkFirst(request));
});

// ── Strategies ───────────────────────────────────────────────────────────────

async function cacheFirst(request, cacheName) {
    const cached = await caches.match(request);
    if (cached) return cached;

    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, response.clone());
        }
        return response;
    } catch {
        // For navigations, return offline page
        if (request.mode === 'navigate') {
            return caches.match('/offline.html');
        }
        return new Response('', { status: 408 });
    }
}

async function networkFirst(request) {
    try {
        const response = await fetch(request);
        if (response.ok && request.mode === 'navigate') {
            const cache = await caches.open(PAGE_CACHE);
            cache.put(request, response.clone());
        }
        return response;
    } catch {
        const cached = await caches.match(request);
        if (cached) return cached;
        if (request.mode === 'navigate') {
            return caches.match('/offline.html');
        }
        return new Response('', { status: 408 });
    }
}
