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
function route(string $name, array $params = [], array $query = []): string {
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
function url(string $path = '', array $query = []): string {
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
function currentUrl(array $modify = []): string {
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
function routeIs(string $name): bool {
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
function routeStartsWith(string $prefix): bool {
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
function routeParam(string $name, mixed $default = null): mixed {
    return Router::param($name, $default);
}

/**
 * Get all route parameters from the current route
 *
 * @return array All route parameters
 */
function routeParams(): array {
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
function asset(string $path): string {
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
function redirectToRoute(string $name, array $params = [], int $status = 302): never {
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
function redirect(string $url, int $status = 302): never {
    header('Location: ' . $url, true, $status);
    exit;
}

/**
 * Redirect back to the previous page
 *
 * @param string $fallback Fallback URL if no referrer
 * @param int $status HTTP status code (default 302)
 */
function back(string $fallback = '/', int $status = 302): never {
    $url = $_SERVER['HTTP_REFERER'] ?? $fallback;
    header('Location: ' . $url, true, $status);
    exit;
}

/**
 * Generate a CSRF token field for forms
 *
 * @return string HTML input field with CSRF token
 */
function csrfField(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
}

/**
 * Generate a method override field for PUT/DELETE forms
 *
 * @param string $method HTTP method (PUT, DELETE, PATCH)
 * @return string HTML input field
 */
function methodField(string $method): string {
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
function activeNav(string|array $routes): string {
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
 * Generate pagination links
 *
 * @param int $currentPage Current page number
 * @param int $totalPages Total number of pages
 * @param array $queryParams Additional query parameters to preserve
 * @return array Array of pagination link data
 */
function paginationLinks(int $currentPage, int $totalPages, array $queryParams = []): array {
    $links = [];

    // Previous link
    if ($currentPage > 1) {
        $links[] = [
            'type' => 'prev',
            'page' => $currentPage - 1,
            'url' => currentUrl(array_merge($queryParams, ['page' => $currentPage - 1])),
            'label' => 'Previous'
        ];
    }

    // Page numbers
    $range = 2; // Pages to show on each side of current
    $start = max(1, $currentPage - $range);
    $end = min($totalPages, $currentPage + $range);

    // First page
    if ($start > 1) {
        $links[] = [
            'type' => 'page',
            'page' => 1,
            'url' => currentUrl(array_merge($queryParams, ['page' => 1])),
            'label' => '1',
            'active' => false
        ];
        if ($start > 2) {
            $links[] = ['type' => 'ellipsis', 'label' => '...'];
        }
    }

    // Page range
    for ($i = $start; $i <= $end; $i++) {
        $links[] = [
            'type' => 'page',
            'page' => $i,
            'url' => currentUrl(array_merge($queryParams, ['page' => $i])),
            'label' => (string)$i,
            'active' => $i === $currentPage
        ];
    }

    // Last page
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $links[] = ['type' => 'ellipsis', 'label' => '...'];
        }
        $links[] = [
            'type' => 'page',
            'page' => $totalPages,
            'url' => currentUrl(array_merge($queryParams, ['page' => $totalPages])),
            'label' => (string)$totalPages,
            'active' => false
        ];
    }

    // Next link
    if ($currentPage < $totalPages) {
        $links[] = [
            'type' => 'next',
            'page' => $currentPage + 1,
            'url' => currentUrl(array_merge($queryParams, ['page' => $currentPage + 1])),
            'label' => 'Next'
        ];
    }

    return $links;
}

/**
 * Create a URL-safe slug from a string
 *
 * @param string $string Input string
 * @return string URL-safe slug
 */
function slugify(string $string): string {
    // Convert to lowercase
    $string = strtolower($string);

    // Replace non-alphanumeric characters with hyphens
    $string = preg_replace('/[^a-z0-9]+/', '-', $string);

    // Remove leading/trailing hyphens
    $string = trim($string, '-');

    return $string;
}

// ========================================
// Array Helpers
// ========================================

if (!function_exists('array_get')) {
    /**
     * Get an item from an array using "dot" notation
     */
    function array_get(array $array, string $key, $default = null) {
        if (isset($array[$key])) {
            return $array[$key];
        }

        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }

        return $array;
    }
}

if (!function_exists('array_only')) {
    /**
     * Get a subset of the items from the given array
     */
    function array_only(array $array, array $keys): array {
        return array_intersect_key($array, array_flip($keys));
    }
}

if (!function_exists('array_except')) {
    /**
     * Get all of the given array except for a specified array of keys
     */
    function array_except(array $array, array $keys): array {
        return array_diff_key($array, array_flip($keys));
    }
}

if (!function_exists('array_first')) {
    /**
     * Return the first element in an array passing a given truth test
     */
    function array_first(array $array, ?callable $callback = null, $default = null) {
        if (is_null($callback)) {
            return empty($array) ? $default : reset($array);
        }

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }
}

if (!function_exists('array_flatten')) {
    /**
     * Flatten a multi-dimensional array into a single level
     */
    function array_flatten(array $array, int $depth = INF): array {
        $result = [];

        foreach ($array as $item) {
            if (!is_array($item)) {
                $result[] = $item;
            } elseif ($depth === 1) {
                $result = array_merge($result, array_values($item));
            } else {
                $result = array_merge($result, array_flatten($item, $depth - 1));
            }
        }

        return $result;
    }
}

// ========================================
// String Helpers
// ========================================

if (!function_exists('str_limit')) {
    /**
     * Limit the number of characters in a string
     */
    function str_limit(string $value, int $limit = 100, string $end = '...'): string {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit) . $end;
    }
}

if (!function_exists('str_random')) {
    /**
     * Generate a random string of the specified length
     */
    function str_random(int $length = 16): string {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $result;
    }
}

if (!function_exists('str_uuid')) {
    /**
     * Generate a UUID v4
     */
    function str_uuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

// ========================================
// Path Helpers
// ========================================

if (!function_exists('base_path')) {
    /**
     * Get the path to the base of the install
     */
    function base_path(string $path = ''): string {
        $basePath = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
        return $basePath . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : '');
    }
}

if (!function_exists('storage_path')) {
    /**
     * Get the path to the storage folder
     */
    function storage_path(string $path = ''): string {
        return base_path('assets' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : ''));
    }
}

if (!function_exists('cache_path')) {
    /**
     * Get the path to the cache folder
     */
    function cache_path(string $path = ''): string {
        return base_path('cache' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : ''));
    }
}

// ========================================
// Response Helpers
// ========================================

if (!function_exists('response_json')) {
    /**
     * Return a JSON response
     */
    function response_json($data, int $status = 200): never {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// ========================================
// Request Helpers
// ========================================

if (!function_exists('request_method')) {
    /**
     * Get the request method
     */
    function request_method(): string {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }
}

if (!function_exists('request_is')) {
    /**
     * Check if request method matches
     */
    function request_is(string $method): bool {
        return request_method() === strtoupper($method);
    }
}

if (!function_exists('request_ajax')) {
    /**
     * Check if request is AJAX
     */
    function request_ajax(): bool {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

if (!function_exists('request_json')) {
    /**
     * Get JSON body from request
     */
    function request_json(): array {
        $body = file_get_contents('php://input');
        return json_decode($body, true) ?: [];
    }
}

if (!function_exists('request_ip')) {
    /**
     * Get the client IP address
     */
    function request_ip(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }
}

// ========================================
// Security Helpers
// ========================================

if (!function_exists('e')) {
    /**
     * Escape HTML special characters
     */
    function e(?string $value): string {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8', false);
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Get the CSRF token
     */
    function csrf_token(): string {
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
    function csrf_field(): string {
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
    function env(string $key, $default = null) {
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
    function now(string $format = 'Y-m-d H:i:s'): string {
        return date($format);
    }
}

if (!function_exists('retry')) {
    /**
     * Retry an operation a given number of times
     */
    function retry(int $times, callable $callback, int $sleep = 0) {
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
     * Dump variables for debugging
     */
    function dump(...$vars): void {
        foreach ($vars as $var) {
            echo '<pre>';
            var_dump($var);
            echo '</pre>';
        }
    }
}

if (!function_exists('dd')) {
    /**
     * Dump and die
     */
    function dd(...$vars): never {
        dump(...$vars);
        exit(1);
    }
}
