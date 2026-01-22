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
 * @param string $path Asset path relative to root
 * @return string Full URL to asset
 *
 * @example asset('css/style.css')
 * @example asset('js/main.js')
 */
function asset(string $path): string {
    $baseUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
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
