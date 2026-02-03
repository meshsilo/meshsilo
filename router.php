<?php
/**
 * Router script for PHP's built-in development server.
 * Usage: php -S 127.0.0.1:8000 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static files directly
$staticExtensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'webp', 'json'];
$ext = pathinfo($uri, PATHINFO_EXTENSION);

if (in_array(strtolower($ext), $staticExtensions)) {
    $file = __DIR__ . $uri;
    if (file_exists($file)) {
        return false; // Let PHP serve the static file
    }
}

// Handle install.php directly
if ($uri === '/install.php' || $uri === '/install') {
    require __DIR__ . '/install.php';
    return;
}

// Route all other requests through index.php with route parameter
if ($uri !== '/' && $uri !== '/index.php') {
    $_GET['route'] = ltrim($uri, '/');
}

require __DIR__ . '/index.php';
