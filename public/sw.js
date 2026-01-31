/**
 * Silo Service Worker
 * Provides offline support and caching for PWA
 */

const CACHE_VERSION = 'silo-v1';
const STATIC_CACHE = CACHE_VERSION + '-static';
const DYNAMIC_CACHE = CACHE_VERSION + '-dynamic';
const MODEL_CACHE = CACHE_VERSION + '-models';

// Static assets to cache immediately
const STATIC_ASSETS = [
    '/',
    '/css/style.css',
    '/js/main.js',
    '/js/viewer.js',
    '/manifest.json'
];

// External resources to cache
const EXTERNAL_ASSETS = [
    'https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.min.js',
    'https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/controls/OrbitControls.js',
    'https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/loaders/STLLoader.js',
    'https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/loaders/3MFLoader.js'
];

// Install event - cache static assets
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then(cache => {
                console.log('[SW] Caching static assets');
                return cache.addAll(STATIC_ASSETS);
            })
            .then(() => {
                return caches.open(DYNAMIC_CACHE).then(cache => {
                    // Try to cache external assets, but don't fail if they're unavailable
                    return Promise.allSettled(
                        EXTERNAL_ASSETS.map(url =>
                            fetch(url, { mode: 'cors' })
                                .then(response => {
                                    if (response.ok) {
                                        return cache.put(url, response);
                                    }
                                })
                                .catch(() => {})
                        )
                    );
                });
            })
            .then(() => self.skipWaiting())
    );
});

// Activate event - cleanup old caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => {
                return Promise.all(
                    keys.filter(key => !key.startsWith(CACHE_VERSION))
                        .map(key => {
                            console.log('[SW] Removing old cache:', key);
                            return caches.delete(key);
                        })
                );
            })
            .then(() => self.clients.claim())
    );
});

// Fetch event - serve from cache, fall back to network
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Skip non-GET requests
    if (event.request.method !== 'GET') {
        return;
    }

    // Skip API/action requests (they need fresh data)
    if (url.pathname.startsWith('/actions/') ||
        url.pathname.startsWith('/api/') ||
        url.pathname.includes('?action=')) {
        return;
    }

    // For 3D model files, use cache-first strategy with background update
    if (isModelFile(url.pathname)) {
        event.respondWith(cacheFirstWithRefresh(event.request, MODEL_CACHE));
        return;
    }

    // For static assets, use cache-first
    if (isStaticAsset(url.pathname)) {
        event.respondWith(cacheFirst(event.request, STATIC_CACHE));
        return;
    }

    // For external CDN resources, use cache-first
    if (!url.origin.includes(self.location.origin)) {
        event.respondWith(cacheFirst(event.request, DYNAMIC_CACHE));
        return;
    }

    // For HTML pages, use network-first with cache fallback
    event.respondWith(networkFirst(event.request, DYNAMIC_CACHE));
});

// Cache strategies
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
        return new Response('Offline', { status: 503 });
    }
}

async function cacheFirstWithRefresh(request, cacheName) {
    const cached = await caches.match(request);

    // Start fetch in background to update cache
    const fetchPromise = fetch(request)
        .then(response => {
            if (response.ok) {
                caches.open(cacheName).then(cache => {
                    cache.put(request, response.clone());
                });
            }
            return response;
        })
        .catch(() => null);

    // Return cached immediately if available
    if (cached) {
        return cached;
    }

    // Otherwise wait for network
    const response = await fetchPromise;
    if (response) {
        return response;
    }

    return new Response('Model not available offline', { status: 503 });
}

async function networkFirst(request, cacheName) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, response.clone());
        }
        return response;
    } catch (error) {
        const cached = await caches.match(request);
        if (cached) {
            return cached;
        }

        // Return offline page for navigation requests
        if (request.mode === 'navigate') {
            return caches.match('/') || new Response(
                '<!DOCTYPE html><html><head><title>Offline</title></head><body><h1>You are offline</h1><p>Please check your internet connection.</p></body></html>',
                { headers: { 'Content-Type': 'text/html' } }
            );
        }

        return new Response('Offline', { status: 503 });
    }
}

// Helper functions
function isStaticAsset(pathname) {
    return pathname.match(/\.(css|js|woff2?|ttf|eot|svg|png|jpg|jpeg|gif|ico)$/i);
}

function isModelFile(pathname) {
    return pathname.match(/\.(stl|3mf|obj|ply|gltf|glb|fbx)$/i);
}

// Handle background sync for offline uploads
self.addEventListener('sync', event => {
    if (event.tag === 'upload-queue') {
        event.waitUntil(processUploadQueue());
    }
});

async function processUploadQueue() {
    // Implementation for background sync uploads
    // This would process any queued uploads when back online
    console.log('[SW] Processing upload queue');
}

// Handle push notifications
self.addEventListener('push', event => {
    if (!event.data) return;

    const data = event.data.json();
    const options = {
        body: data.body || '',
        icon: '/images/icon-192.png',
        badge: '/images/icon-192.png',
        data: data.url || '/'
    };

    event.waitUntil(
        self.registration.showNotification(data.title || 'Silo', options)
    );
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.waitUntil(
        clients.openWindow(event.notification.data)
    );
});
