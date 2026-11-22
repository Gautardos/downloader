/* Downloader PWA service worker. No framework, no build step. */
'use strict';

const CACHE_VERSION = 'downloader-shell-v1';
const OFFLINE_URL = '/offline.html';
const PRECACHE_URLS = [
    OFFLINE_URL,
    '/manifest.webmanifest',
    '/pwa-icons/icon-192.png',
    '/pwa-icons/icon-512.png',
    '/pwa-icons/maskable-512.png',
    '/logo.png',
    '/apple-touch-icon.png'
];

const STATIC_ASSET_PATHS = new Set([
    '/offline.html',
    '/manifest.webmanifest',
    '/logo.png',
    '/logo.svg',
    '/apple-touch-icon.png',
    '/favicon.ico',
    '/pwa-icons/icon-192.png',
    '/pwa-icons/icon-512.png',
    '/pwa-icons/maskable-512.png'
]);

const STATIC_ASSET_PREFIXES = ['/pwa-icons/', '/help/'];

// These routes must always hit the network and must never enter a cache.
const NETWORK_ONLY_PREFIXES = [
    '/jellyfin',
    '/login',
    '/logout',
    '/pwa/',
    '/search/global',
    '/queue-status',
    '/queue-active-log',
    '/notifications-poll',
    '/notifications-ack',
    '/progress/'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_VERSION)
            .then((cache) => cache.addAll(PRECACHE_URLS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key.startsWith('downloader-shell-') && key !== CACHE_VERSION)
                    .map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

function isNetworkOnly(url, request) {
    if (request.method !== 'GET') {
        return true;
    }

    const accept = (request.headers.get('Accept') || '').toLowerCase();
    if (accept.includes('application/json')) {
        return true;
    }

    return NETWORK_ONLY_PREFIXES.some((prefix) => url.pathname.startsWith(prefix));
}

function isStaticAsset(url) {
    if (STATIC_ASSET_PATHS.has(url.pathname)) {
        return true;
    }

    return STATIC_ASSET_PREFIXES.some((prefix) => url.pathname.startsWith(prefix));
}

function offlineResponse() {
    return caches.match(OFFLINE_URL, { ignoreSearch: true }).then((cached) => {
        if (cached) {
            return cached;
        }

        return new Response(
            '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Offline</title></head><body><h1>You are offline</h1><p>Reconnect and retry.</p></body></html>',
            { status: 503, headers: { 'Content-Type': 'text/html; charset=UTF-8' } }
        );
    });
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    let url;
    try {
        url = new URL(request.url);
    } catch (error) {
        return;
    }

    if (url.origin !== self.location.origin) {
        return;
    }

    if (isNetworkOnly(url, request)) {
        return;
    }

    if (request.mode === 'navigate') {
        // Network-first navigation. Authenticated HTML is never written to a cache.
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (!response || response.status >= 500) {
                        throw new Error('Navigation failed');
                    }

                    return response;
                })
                .catch(() => offlineResponse())
        );
        return;
    }

    if (isStaticAsset(url)) {
        // Cache-first with background revalidation for known static assets.
        const cachePromise = caches.open(CACHE_VERSION);
        const cachedPromise = cachePromise.then((cache) => cache.match(request, { ignoreSearch: true }));
        const networkPromise = cachePromise
            .then((cache) => fetch(request)
                .then((response) => {
                    if (response && response.ok) {
                        cache.put(request, response.clone());
                    }

                    return response;
                })
                .catch(() => null));

        event.waitUntil(networkPromise.then(() => undefined).catch(() => undefined));
        event.respondWith((async () => {
            const cached = await cachedPromise;
            if (cached) {
                return cached;
            }

            const response = await networkPromise;
            if (response) {
                return response;
            }

            return new Response('', { status: 503, statusText: 'Offline' });
        })());
        return;
    }

    // Unknown GET requests (including JSON APIs) are network-only.
});

function normalizePushUrl(value) {
    try {
        const url = new URL(value || '/', self.location.origin);
        if (url.origin !== self.location.origin) {
            return '/';
        }

        return url.pathname + url.search;
    } catch (error) {
        return '/';
    }
}

self.addEventListener('push', (event) => {
    let payload = {};
    if (event.data) {
        try {
            payload = event.data.json();
        } catch (error) {
            payload = { body: event.data.text() };
        }
    }

    const title = typeof payload.title === 'string' && payload.title.trim() !== ''
        ? payload.title.trim().slice(0, 80)
        : 'Downloader';
    const body = typeof payload.body === 'string' && payload.body.trim() !== ''
        ? payload.body.trim().slice(0, 300)
        : 'You have a new notification.';
    const icon = typeof payload.icon === 'string' && payload.icon.startsWith('/')
        ? payload.icon
        : '/pwa-icons/icon-192.png';

    event.waitUntil(
        self.registration.showNotification(title, {
            body,
            icon,
            badge: '/pwa-icons/icon-192.png',
            data: { url: normalizePushUrl(payload.url) }
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = normalizePushUrl(event.notification.data && event.notification.data.url);

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async (clients) => {
            for (const client of clients) {
                try {
                    const clientUrl = new URL(client.url);
                    if (clientUrl.origin !== self.location.origin) {
                        continue;
                    }

                    await client.focus();
                    if ('navigate' in client) {
                        try {
                            await client.navigate(target);
                        } catch (error) {
                            // The existing page can still be focused even if navigation is denied.
                        }
                    }

                    return;
                } catch (error) {
                    continue;
                }
            }

            if (self.clients.openWindow) {
                await self.clients.openWindow(target);
            }
        })
    );
});
