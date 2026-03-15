/**
 * MeshSilo Service Worker
 * Provides offline support and intelligent caching for PWA
 */

const CACHE_VERSION = 'silo-v3';
const STATIC_CACHE = CACHE_VERSION + '-static';
const DYNAMIC_CACHE = CACHE_VERSION + '-dynamic';
const MODEL_CACHE = CACHE_VERSION + '-models';
const IMAGE_CACHE = CACHE_VERSION + '-images';

// Cache size limits (number of entries)
const CACHE_LIMITS = {
    [DYNAMIC_CACHE]: 50,
    [MODEL_CACHE]: 100,
    [IMAGE_CACHE]: 200
};

// Cache expiration times (in seconds)
const CACHE_TTL = {
    static: 30 * 24 * 60 * 60,  // 30 days
    dynamic: 24 * 60 * 60,       // 1 day
    models: 7 * 24 * 60 * 60,    // 7 days
    images: 7 * 24 * 60 * 60     // 7 days
};

// Static assets to precache
const PRECACHE_ASSETS = [
    '/',
    '/public/css/style.css',
    '/public/js/main.js',
    '/public/js/viewer.js',
    '/public/manifest.json',
    '/public/images/icon.svg'
];

// CDN resources to precache
const CDN_ASSETS = [
    'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js',
    'https://cdn.jsdelivr.net/npm/fflate@0.8.0/umd/index.js',
    'https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/STLLoader.js',
    'https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/3MFLoader.js',
    'https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/OBJLoader.js',
    'https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js'
];

// Install event - precache essential assets
self.addEventListener('install', event => {
    event.waitUntil(
        Promise.all([
            // Cache static assets
            caches.open(STATIC_CACHE).then(cache => {
                return cache.addAll(PRECACHE_ASSETS).catch(err => {
                    // Static assets failed to cache - non-fatal
                });
            }),
            // Cache CDN assets (best effort)
            caches.open(DYNAMIC_CACHE).then(cache => {
                return Promise.allSettled(
                    CDN_ASSETS.map(url =>
                        fetch(url, { mode: 'cors', credentials: 'omit' })
                            .then(response => {
                                if (response.ok) {
                                    return cache.put(url, response);
                                }
                            })
                            .catch(() => {})
                    )
                );
            })
        ]).then(() => self.skipWaiting())
    );
});

// Activate event - cleanup old caches
self.addEventListener('activate', event => {
    const currentCaches = [STATIC_CACHE, DYNAMIC_CACHE, MODEL_CACHE, IMAGE_CACHE];

    event.waitUntil(
        caches.keys()
            .then(keys => {
                return Promise.all(
                    keys.filter(key => !currentCaches.includes(key))
                        .map(key => {
                            return caches.delete(key);
                        })
                );
            })
            .then(() => self.clients.claim())
    );
});

// Fetch event - intelligent caching strategies
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Skip non-GET requests
    if (event.request.method !== 'GET') {
        return;
    }

    // Skip API/action requests (need fresh data)
    if (url.pathname.startsWith('/actions/') ||
        url.pathname.startsWith('/api/') ||
        url.pathname.includes('?action=')) {
        return;
    }

    // Skip admin pages
    if (url.pathname.startsWith('/admin')) {
        return;
    }

    // Strategy selection based on resource type
    if (isModelFile(url.pathname)) {
        // 3D models: Cache-first with background refresh
        event.respondWith(staleWhileRevalidate(event.request, MODEL_CACHE));
    } else if (isImageFile(url.pathname) || url.pathname.startsWith('/assets/')) {
        // Images and uploaded assets: Cache-first
        event.respondWith(cacheFirstWithLimit(event.request, IMAGE_CACHE));
    } else if (isStaticAsset(url.pathname)) {
        // Static assets (CSS/JS): Cache-first with network fallback
        event.respondWith(cacheFirst(event.request, STATIC_CACHE));
    } else if (!isSameOrigin(url)) {
        // External CDN resources: Cache-first
        event.respondWith(cacheFirst(event.request, DYNAMIC_CACHE));
    } else if (event.request.mode === 'navigate') {
        // HTML pages: Network-first with offline fallback
        event.respondWith(networkFirstWithOffline(event.request));
    } else {
        // Other requests: Network-first
        event.respondWith(networkFirst(event.request, DYNAMIC_CACHE));
    }
});

// ======================
// Caching Strategies
// ======================

/**
 * Cache-first: Try cache, fall back to network
 */
async function cacheFirst(request, cacheName) {
    const cached = await caches.match(request);
    if (cached) {
        return cached;
    }

    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, response.clone());
        }
        return response;
    } catch (error) {
        return offlineResponse(request);
    }
}

/**
 * Cache-first with size limit enforcement
 */
async function cacheFirstWithLimit(request, cacheName) {
    const cached = await caches.match(request);
    if (cached) {
        return cached;
    }

    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, response.clone());
            // Enforce cache size limit in background
            limitCacheSize(cacheName);
        }
        return response;
    } catch (error) {
        return offlineResponse(request);
    }
}

/**
 * Stale-while-revalidate: Return cached immediately, update in background
 */
async function staleWhileRevalidate(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);

    // Fetch fresh version in background
    const fetchPromise = fetch(request)
        .then(response => {
            if (response.ok) {
                cache.put(request, response.clone());
                limitCacheSize(cacheName);
            }
            return response;
        })
        .catch(() => null);

    // Return cached immediately if available
    if (cached) {
        return cached;
    }

    // Wait for network if no cache
    const response = await fetchPromise;
    return response || offlineResponse(request);
}

/**
 * Network-first: Try network, fall back to cache
 */
async function networkFirst(request, cacheName) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, response.clone());
            limitCacheSize(cacheName);
        }
        return response;
    } catch (error) {
        const cached = await caches.match(request);
        return cached || offlineResponse(request);
    }
}

/**
 * Network-first with offline page fallback for navigation
 */
async function networkFirstWithOffline(request) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(DYNAMIC_CACHE);
            cache.put(request, response.clone());
        }
        return response;
    } catch (error) {
        const cached = await caches.match(request);
        if (cached) {
            return cached;
        }

        // Return cached homepage for navigation requests
        const homeCached = await caches.match('/');
        if (homeCached) {
            return homeCached;
        }

        // Generate offline page
        return new Response(
            `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline - MeshSilo</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #1a1a2e;
            color: #eee;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            text-align: center;
        }
        .container { max-width: 400px; padding: 2rem; }
        h1 { font-size: 2rem; margin-bottom: 1rem; }
        p { color: #aaa; margin-bottom: 1.5rem; }
        button {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            cursor: pointer;
            font-size: 1rem;
        }
        button:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="container">
        <h1>You're Offline</h1>
        <p>Please check your internet connection and try again.</p>
        <button type="button" onclick="location.reload()">Retry</button>
    </div>
</body>
</html>`,
            { headers: { 'Content-Type': 'text/html' } }
        );
    }
}

/**
 * Generate appropriate offline response based on request type
 */
function offlineResponse(request) {
    const url = new URL(request.url);

    if (isImageFile(url.pathname)) {
        // Return placeholder SVG for images
        return new Response(
            `<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">
                <rect fill="#2a2a3e" width="200" height="200"/>
                <text x="100" y="100" fill="#666" text-anchor="middle" dy=".3em" font-family="system-ui">Offline</text>
            </svg>`,
            { headers: { 'Content-Type': 'image/svg+xml' } }
        );
    }

    return new Response('Offline', {
        status: 503,
        statusText: 'Service Unavailable'
    });
}

// ======================
// Cache Management
// ======================

/**
 * Enforce cache size limits by removing oldest entries
 */
async function limitCacheSize(cacheName) {
    const limit = CACHE_LIMITS[cacheName];
    if (!limit) return;

    const cache = await caches.open(cacheName);
    const keys = await cache.keys();

    if (keys.length > limit) {
        // Remove oldest entries (FIFO)
        const deleteCount = keys.length - limit;
        for (let i = 0; i < deleteCount; i++) {
            await cache.delete(keys[i]);
        }
    }
}

/**
 * Periodic cache cleanup
 */
async function cleanupCaches() {
    const cacheNames = Object.keys(CACHE_LIMITS);
    for (const cacheName of cacheNames) {
        await limitCacheSize(cacheName);
    }
}

// ======================
// Helper Functions
// ======================

function isStaticAsset(pathname) {
    return /\.(css|js|woff2?|ttf|eot)$/i.test(pathname);
}

function isImageFile(pathname) {
    return /\.(svg|png|jpg|jpeg|gif|ico|webp)$/i.test(pathname);
}

function isModelFile(pathname) {
    return /\.(stl|3mf|obj|ply|gltf|glb|fbx|step|stp|iges|igs|amf|dae|3ds)$/i.test(pathname);
}

function isSameOrigin(url) {
    return url.origin === self.location.origin;
}

// ======================
// Background Sync
// ======================

self.addEventListener('sync', event => {
    if (event.tag === 'upload-queue') {
        event.waitUntil(processUploadQueue());
    } else if (event.tag === 'cache-cleanup') {
        event.waitUntil(cleanupCaches());
    }
});

async function processUploadQueue() {
    // Implementation for background sync uploads
}

// ======================
// Push Notifications
// ======================

self.addEventListener('push', event => {
    if (!event.data) return;

    try {
        const data = event.data.json();
        const options = {
            body: data.body || '',
            icon: '/public/images/icon-192.png',
            badge: '/public/images/icon-192.png',
            vibrate: [100, 50, 100],
            data: { url: data.url || '/' },
            actions: data.actions || []
        };

        event.waitUntil(
            self.registration.showNotification(data.title || 'MeshSilo', options)
        );
    } catch (e) {
        console.error('[SW] Push notification error:', e);
    }
});

self.addEventListener('notificationclick', event => {
    event.notification.close();

    const url = event.notification.data?.url || '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then(clientList => {
                // Focus existing window if available
                for (const client of clientList) {
                    if (client.url.includes(self.location.origin) && 'focus' in client) {
                        client.navigate(url);
                        return client.focus();
                    }
                }
                // Open new window
                return clients.openWindow(url);
            })
    );
});

// ======================
// Message Handling
// ======================

self.addEventListener('message', event => {
    if (event.data?.action === 'skipWaiting') {
        self.skipWaiting();
    } else if (event.data?.action === 'clearCache') {
        event.waitUntil(
            caches.keys().then(keys =>
                Promise.all(keys.map(key => caches.delete(key)))
            )
        );
    } else if (event.data?.action === 'cacheModel') {
        // Prefetch a model file
        const url = event.data.url;
        if (url && isModelFile(url)) {
            event.waitUntil(
                caches.open(MODEL_CACHE).then(cache =>
                    fetch(url).then(response => {
                        if (response.ok) {
                            cache.put(url, response);
                        }
                    })
                )
            );
        }
    }
});
