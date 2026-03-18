<?php

/**
 * Helper Functions for Silo
 *
 * URL generation and routing helper functions.
 */

/**
 * Generate URL for a named route
 *
 * @param string $name Route name
 * @param array $params Route parameters (e.g., ['id' => 123])
 * @param array $query Query string parameters (e.g., ['page' => 2])
 * @return string Generated URL
 *
 * @example route('model.show', ['id' => 123])
 * @example route('browse', [], ['category' => 5, 'sort' => 'newest'])
 */
function route(string $name, array $params = [], array $query = []): string
{
    return Router::url($name, $params, $query);
}

/**
 * Generate a simple URL path with optional query string
 *
 * @param string $path URL path
 * @param array $query Query string parameters
 * @return string Generated URL
 *
 * @example url('/browse', ['category' => 5])
 */
function url(string $path = '', array $query = []): string
{
    $baseUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
    $path = '/' . ltrim($path, '/');

    if (!empty($query)) {
        $path .= '?' . http_build_query($query);
    }

    return $baseUrl . $path;
}

/**
 * Get the current URL with modified query parameters
 *
 * @param array $modify Parameters to add/modify (null value removes the param)
 * @return string Modified URL
 *
 * @example currentUrl(['page' => 2]) // Add or update page param
 * @example currentUrl(['filter' => null]) // Remove filter param
 */
function currentUrl(array $modify = []): string
{
    $current = $_GET;

    // Remove internal router parameters
    unset($current['route']);

    // Apply modifications
    foreach ($modify as $key => $value) {
        if ($value === null) {
            unset($current[$key]);
        } else {
            $current[$key] = $value;
        }
    }

    // Get current path
    $path = $_SERVER['REQUEST_URI'];
    $path = explode('?', $path)[0];

    if (!empty($current)) {
        $path .= '?' . http_build_query($current);
    }

    return $path;
}

/**
 * Check if the current route matches a given name
 *
 * @param string $name Route name to check
 * @return bool True if current route matches
 *
 * @example if (routeIs('model.show')) { ... }
 */
function routeIs(string $name): bool
{
    return Router::is($name);
}

/**
 * Check if the current route starts with a given prefix
 *
 * @param string $prefix Route name prefix
 * @return bool True if current route starts with prefix
 *
 * @example if (routeStartsWith('admin.')) { ... }
 */
function routeStartsWith(string $prefix): bool
{
    $currentRoute = $_SERVER['ROUTE_NAME'] ?? '';
    return strpos($currentRoute, $prefix) === 0;
}

/**
 * Get a route parameter from the current route
 *
 * @param string $name Parameter name
 * @param mixed $default Default value if not found
 * @return mixed Parameter value or default
 *
 * @example $id = routeParam('id', 0);
 */
function routeParam(string $name, mixed $default = null): mixed
{
    return Router::param($name, $default);
}

/**
 * Get all route parameters from the current route
 *
 * @return array All route parameters
 */
function routeParams(): array
{
    return Router::params();
}

/**
 * Generate an asset URL (CSS, JS, images)
 *
 * @param string $path Asset path relative to public/
 * @return string Full URL to asset
 *
 * @example asset('css/style.css')  // Returns /public/css/style.css
 * @example asset('js/main.js')     // Returns /public/js/main.js
 */
function asset(string $path): string
{
    $baseUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
    // Prepend 'public/' if it's a known asset type
    if (preg_match('#^(css|js|images)/#', $path)) {
        return $baseUrl . '/public/' . ltrim($path, '/');
    }
    return $baseUrl . '/' . ltrim($path, '/');
}

/**
 * Redirect to a named route
 *
 * @param string $name Route name
 * @param array $params Route parameters
 * @param int $status HTTP status code (default 302)
 */
function redirectToRoute(string $name, array $params = [], int $status = 302): never
{
    $url = route($name, $params);
    header('Location: ' . $url, true, $status);
    exit;
}

/**
 * Redirect to a URL
 *
 * @param string $url URL to redirect to
 * @param int $status HTTP status code (default 302)
 */
function redirect(string $url, int $status = 302): never
{
    header('Location: ' . $url, true, $status);
    exit;
}

/**
 * Redirect back to the previous page
 *
 * @param string $fallback Fallback URL if no referrer
 * @param int $status HTTP status code (default 302)
 */
function back(string $fallback = '/', int $status = 302): never
{
    $url = $_SERVER['HTTP_REFERER'] ?? $fallback;
    // Prevent open redirect: only allow local paths
    $parsed = parse_url($url);
    if (isset($parsed['host'])) {
        $siteHost = parse_url(defined('SITE_URL') ? SITE_URL : '', PHP_URL_HOST);
        if ($siteHost && $parsed['host'] !== $siteHost) {
            $url = $fallback;
        }
    }
    // Block protocol-relative URLs
    if (str_starts_with($url, '//')) {
        $url = $fallback;
    }
    header('Location: ' . $url, true, $status);
    exit;
}

/**
 * Convert PHP ini size notation to bytes
 *
 * @param string $size Size string (e.g., '128M', '2G', '1024K')
 * @return int Size in bytes
 *
 * @example convertToBytes('128M')  // Returns 134217728
 * @example convertToBytes('2G')    // Returns 2147483648
 */
function convertToBytes(string $size): int
{
    $size = trim($size);
    if (empty($size)) {
        return 0;
    }

    $unit = strtoupper(substr($size, -1));
    $value = (int) $size;

    switch ($unit) {
        case 'G':
            $value *= 1024;
            // fallthrough
        case 'M':
            $value *= 1024;
            // fallthrough
        case 'K':
            $value *= 1024;
    }

    return $value;
}

/**
 * Format bytes into human-readable string
 *
 * @param int $bytes Number of bytes
 * @param int $precision Decimal precision (default 2)
 * @return string Human-readable size (e.g., "1.5 MB")
 * @example formatBytes(1536)      // Returns "1.5 KB"
 * @example formatBytes(1048576)   // Returns "1 MB"
 */
function formatBytes(int|float $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = (int)floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Generate a method override field for PUT/DELETE forms
 *
 * @param string $method HTTP method (PUT, DELETE, PATCH)
 * @return string HTML input field
 */
function methodField(string $method): string
{
    return '<input type="hidden" name="_method" value="' . htmlspecialchars(strtoupper($method)) . '">';
}

/**
 * Check if current page is active (for navigation highlighting)
 *
 * @param string|array $routes Route name(s) to check
 * @return string 'active' if current route matches, empty string otherwise
 *
 * @example <li class="<?= activeNav('home') ?>">
 * @example <li class="<?= activeNav(['admin.settings', 'admin.users']) ?>">
 */
function activeNav(string|array $routes): string
{
    $currentRoute = $_SERVER['ROUTE_NAME'] ?? '';

    if (is_string($routes)) {
        $routes = [$routes];
    }

    foreach ($routes as $route) {
        // Exact match
        if ($currentRoute === $route) {
            return 'active';
        }
        // Prefix match (e.g., 'admin.' matches 'admin.settings')
        if (str_ends_with($route, '.') && str_starts_with($currentRoute, $route)) {
            return 'active';
        }
    }

    return '';
}

/**
 * Create a URL-safe slug from a string
 *
 * @param string $string Input string
 * @return string URL-safe slug
 */
function slugify(string $string): string
{
    // Convert to lowercase
    $string = strtolower($string);

    // Replace non-alphanumeric characters with hyphens
    $string = preg_replace('/[^a-z0-9]+/', '-', $string);

    // Remove leading/trailing hyphens
    $string = trim($string, '-');

    return $string;
}

// ========================================
// Security Helpers
// ========================================

if (!function_exists('e')) {
    /**
     * Escape HTML special characters
     */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8', false);
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Get the CSRF token
     */
    function csrf_token(): string
    {
        if (class_exists('Csrf')) {
            return Csrf::getToken();
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Generate a CSRF token hidden input field
     */
    function csrf_field(): string
    {
        if (class_exists('Csrf')) {
            return Csrf::field();
        }
        return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    }
}

// ========================================
// Misc Helpers
// ========================================

if (!function_exists('env')) {
    /**
     * Get an environment variable
     */
    function env(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false) {
            return $default;
        }

        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'null':
            case '(null)':
                return null;
        }

        return $value;
    }
}

if (!function_exists('now')) {
    /**
     * Get current datetime
     */
    function now(string $format = 'Y-m-d H:i:s'): string
    {
        return date($format);
    }
}

if (!function_exists('retry')) {
    /**
     * Retry an operation a given number of times
     */
    function retry(int $times, callable $callback, int $sleep = 0)
    {
        $attempts = 0;
        $exception = null;

        while ($attempts < $times) {
            try {
                return $callback($attempts);
            } catch (Exception $e) {
                $exception = $e;
                $attempts++;

                if ($attempts < $times && $sleep > 0) {
                    usleep($sleep * 1000);
                }
            }
        }

        throw $exception;
    }
}

if (!function_exists('dump')) {
    /**
     * Dump variables for debugging (only works when DEBUG mode is enabled)
     */
    function dump(...$vars): void
    {
        // Only output in CLI or when DEBUG is explicitly enabled
        $debugEnabled = (defined('DEBUG') && DEBUG === true) || php_sapi_name() === 'cli';
        if (!$debugEnabled) {
            // Log instead of outputting in production
            if (function_exists('logDebug')) {
                foreach ($vars as $var) {
                    logDebug('dump() called in production', ['value' => print_r($var, true)]);
                }
            }
            return;
        }

        foreach ($vars as $var) {
            echo '<pre>';
            var_dump($var);
            echo '</pre>';
        }
    }
}

if (!function_exists('dd')) {
    /**
     * Dump and die (only outputs when DEBUG mode is enabled)
     */
    function dd(...$vars): never
    {
        dump(...$vars);
        exit(1);
    }
}

/**
 * Send a JSON success response and exit.
 */
function jsonSuccess(array $data = []): never
{
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

/**
 * Send a JSON error response and exit.
 */
function jsonError(string $message, int $httpCode = 400): never
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

/**
 * Require a valid CSRF token for JSON endpoints, or return a 403 JSON error.
 */
function requireCsrfJson(): void
{
    if (!Csrf::check()) {
        jsonError('Invalid request token', 403);
    }
}
