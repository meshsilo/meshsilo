<?php

/**
 * PHP OPcache Preloading Script
 *
 * This script preloads frequently-used classes into OPcache at PHP-FPM startup.
 * Benefits:
 * - Classes are compiled once and shared across all requests
 * - Eliminates file I/O and parsing overhead for core classes
 * - 5-15% faster response times for typical requests
 *
 * Enable by adding to php.ini:
 *   opcache.preload=/var/www/meshsilo/includes/preload.php
 *   opcache.preload_user=www-data
 *
 * Note: Preloading requires PHP 7.4+ and works best in production.
 * Changes to preloaded files require PHP-FPM restart.
 */

// Only run during preloading (CLI context at startup)
if (php_sapi_name() !== 'cli') {
    return;
}

$basePath = dirname(__DIR__);

// Core classes to preload (order matters for dependencies)
$preloadFiles = [
    // Core utilities
    $basePath . '/includes/logger.php',
    $basePath . '/includes/helpers.php',
    $basePath . '/includes/db.php',
    $basePath . '/includes/Cache.php',
    $basePath . '/includes/Asset.php',

    // Authentication & permissions
    $basePath . '/includes/permissions.php',
    $basePath . '/includes/DatabaseSessionHandler.php',

    // Router
    $basePath . '/includes/Router.php',
    $basePath . '/includes/routes.php',

    // Core features
    $basePath . '/includes/features.php',
    $basePath . '/includes/Events.php',
    $basePath . '/includes/Search.php',
    $basePath . '/includes/ThumbnailGenerator.php',

    // Middleware (interface must load before implementations)
    $basePath . '/includes/middleware/MiddlewareInterface.php',
    $basePath . '/includes/middleware/RateLimitMiddleware.php',
];

$loaded = 0;
$failed = 0;

foreach ($preloadFiles as $file) {
    if (file_exists($file)) {
        try {
            opcache_compile_file($file);
            $loaded++;
        } catch (Throwable $e) {
            $failed++;
            error_log("Preload failed for $file: " . $e->getMessage());
        }
    }
}

// Log preload summary
error_log("OPcache Preload: Loaded $loaded files, $failed failed");
