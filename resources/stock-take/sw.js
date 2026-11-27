/*
 * Stock Take's service worker (release 2, A8). Served from /stock-take/sw.js
 * by StockTakePwaController, which fills in the two values below, and
 * registered with scope /stock-take/: it controls the counting app only.
 * The till's own worker (/sw.js, scope /) keeps every other page.
 *
 * What it keeps on the phone, so the app opens and works with no network:
 *   st-shell-<v>   the app's page, per shop (shown at once, refreshed behind)
 *   st-assets-<v>  the app's JS and CSS (cached on install; never change)
 *   st-thumbs      product pictures
 *
 * Counts, the task pack and the upload queue are not here: they are in the
 * app's own IndexedDB store (BizacStockTake). API calls always go to the
 * network.
 *
 * Only caches named st-… are ever touched here. The till's caches are left
 * alone.
 */
const VERSION = '__VERSION__';
const PRECACHE = __PRECACHE__;

const SHELL = `st-shell-${VERSION}`;
const ASSETS = `st-assets-${VERSION}`;
const THUMBS = 'st-thumbs';
const THUMBS_MAX = 2500;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(ASSETS)
            .then((cache) => cache.addAll(PRECACHE))
            .catch(() => { /* offline while installing: filled as the app loads */ })
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keep = [SHELL, ASSETS, THUMBS];
        const names = await caches.keys();
        // The shop pages cached by the version before: carried over, so the
        // app still opens offline straight after an update.
        for (const name of names) {
            if (name.startsWith('st-shell-') && name !== SHELL) {
                const old = await caches.open(name);
                const fresh = await caches.open(SHELL);
                for (const request of await old.keys()) {
                    const response = await old.match(request);
                    if (response) await fresh.put(request, response);
                }
            }
        }
        await Promise.all(names
            .filter((name) => name.startsWith('st-') && !keep.includes(name))
            .map((name) => caches.delete(name)));
        await self.clients.claim();
    })());
});

// The app asks for its page to be stored as soon as this worker is ready: the
// very first visit happens before the worker exists, so nothing else would
// store it until the next one.
self.addEventListener('message', (event) => {
    if (event.data?.type !== 'store-shell') return;
    const url = new URL(event.data.url, self.location.origin);
    if (url.origin !== self.location.origin || !isShell(url)) return;

    event.waitUntil((async () => {
        try {
            const response = await fetch(url.pathname, { credentials: 'include' });
            if (response.ok && !response.redirected) {
                await (await caches.open(SHELL)).put(url.pathname.replace(/\/$/, ''), response);
            }
        } catch { /* no signal: stored on the next visit */ }
    })());
});

const isShell = (url) => /^\/stock-take\/[^/]+\/?$/.test(url.pathname) && url.pathname !== '/stock-take/sw.js';

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // The app's page: from the phone at once, refreshed in the background.
    if (request.mode === 'navigate' && isShell(url)) {
        event.respondWith(shell(request, event));
        return;
    }

    // Built JS and CSS: the name changes when the file does, so a stored copy is always right.
    if (url.pathname.startsWith('/build/assets/')) {
        event.respondWith(cacheFirst(ASSETS, request));
        return;
    }

    // Product pictures.
    if (request.destination === 'image') {
        event.respondWith(cacheFirst(THUMBS, request, THUMBS_MAX));
    }

    // Everything else (the API above all) goes to the network as usual.
});

async function shell(request, event) {
    const cache = await caches.open(SHELL);
    const key = new URL(request.url).pathname.replace(/\/$/, '');
    const stored = await cache.match(key);

    const refresh = fetch(request).then(async (response) => {
        // Signed out (sent to the login page) or an error: never stored.
        if (response.ok && !response.redirected) await cache.put(key, response.clone());
        return response;
    });

    if (stored) {
        event.waitUntil(refresh.catch(() => {}));
        return stored;
    }

    try {
        return await refresh;
    } catch {
        return new Response(
            '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Stock Take</title>'
            + '<body style="font:16px system-ui;padding:24px;color:#111827">'
            + '<h1 style="font-size:20px">No connection</h1><p>Open Stock Take once with signal; after that it works offline.</p></body>',
            { headers: { 'Content-Type': 'text/html; charset=utf-8' } },
        );
    }
}

async function cacheFirst(name, request, max = 0) {
    const cache = await caches.open(name);
    const stored = await cache.match(request);
    if (stored) return stored;

    const response = await fetch(request);
    if (response.ok || response.type === 'opaque') {
        await cache.put(request, response.clone());
        if (max) trim(cache, max);
    }
    return response;
}

async function trim(cache, max) {
    const keys = await cache.keys();
    for (let i = 0; i < keys.length - max; i++) await cache.delete(keys[i]);
}
