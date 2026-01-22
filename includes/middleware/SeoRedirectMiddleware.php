<?php
/**
 * SEO Redirect Middleware
 *
 * Redirects old-style URLs (model.php?id=123) to clean URLs (/model/123)
 * with a 301 permanent redirect for SEO benefits.
 */

require_once __DIR__ . '/MiddlewareInterface.php';

class SeoRedirectMiddleware implements MiddlewareInterface {
    /**
     * URL mapping from old patterns to new route names
     */
    private static array $urlMappings = [
        // Page mappings: old file => [route name, param mappings]
        'model.php' => ['model.show', ['id' => 'id']],
        'category.php' => ['category.show', ['id' => 'id']],
        'collection.php' => ['collection.show', ['name' => 'name']],
        'categories.php' => ['categories', []],
        'collections.php' => ['collections', []],
        'browse.php' => ['browse', []],
        'search.php' => ['search', []],
        'tags.php' => ['tags', []],
        'favorites.php' => ['favorites', []],
        'upload.php' => ['upload', []],
        'login.php' => ['login', []],
        'logout.php' => ['logout', []],
        'print-queue.php' => ['print-queue', []],
        'printers.php' => ['printers', []],
        'edit-model.php' => ['model.edit', ['id' => 'id']],

        // Admin pages
        'admin/settings.php' => ['admin.settings', []],
        'admin/users.php' => ['admin.users', []],
        'admin/user.php' => ['admin.user', ['id' => 'id']],
        'admin/groups.php' => ['admin.groups', []],
        'admin/categories.php' => ['admin.categories', []],
        'admin/collections.php' => ['admin.collections', []],
        'admin/models.php' => ['admin.models', []],
        'admin/stats.php' => ['admin.stats', []],
        'admin/activity.php' => ['admin.activity', []],
        'admin/storage.php' => ['admin.storage', []],
        'admin/database.php' => ['admin.database', []],
        'admin/api-keys.php' => ['admin.api-keys', []],
        'admin/webhooks.php' => ['admin.webhooks', []],
        'admin/license.php' => ['admin.license', []],

        // Actions with clean URL shortcuts
        'actions/download.php' => ['download', ['id' => 'id']],
        'actions/download-all.php' => ['download.all', ['id' => 'id']],
    ];

    /**
     * Handle the middleware - check for old URL patterns and redirect
     */
    public function handle(array $params): bool {
        // Only process if SEO redirects are enabled
        if (function_exists('getSetting') && getSetting('seo_redirects', '1') !== '1') {
            return true;
        }

        // Get the request URI
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $parsedUrl = parse_url($requestUri);
        $path = $parsedUrl['path'] ?? '';

        // Remove leading slash and check if it matches an old URL pattern
        $path = ltrim($path, '/');

        // Check if this looks like an old-style .php URL
        if (!preg_match('/\.php$/', $path)) {
            return true; // Not an old URL, continue
        }

        // Check if it's in our mapping
        if (!isset(self::$urlMappings[$path])) {
            return true; // Not a mapped URL, continue
        }

        // Get the mapping
        [$routeName, $paramMappings] = self::$urlMappings[$path];

        // Build route parameters from query string
        $routeParams = [];
        $queryParams = [];

        parse_str($parsedUrl['query'] ?? '', $queryString);

        foreach ($queryString as $key => $value) {
            if (isset($paramMappings[$key])) {
                // This is a route parameter
                $routeParams[$paramMappings[$key]] = $value;
            } else {
                // This is a query parameter to preserve
                $queryParams[$key] = $value;
            }
        }

        // Check if all required route params are present
        foreach ($paramMappings as $queryKey => $routeKey) {
            if (!isset($routeParams[$routeKey]) && !empty($paramMappings)) {
                // Missing required param, don't redirect
                return true;
            }
        }

        // Generate the clean URL
        try {
            $cleanUrl = Router::url($routeName, $routeParams, $queryParams);

            // Perform 301 redirect
            header('Location: ' . $cleanUrl, true, 301);
            header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
            exit;
        } catch (Exception $e) {
            // If URL generation fails, continue without redirect
            return true;
        }
    }

    /**
     * Register custom URL mappings
     */
    public static function addMapping(string $oldPath, string $routeName, array $paramMappings = []): void {
        self::$urlMappings[$oldPath] = [$routeName, $paramMappings];
    }

    /**
     * Get all registered mappings (for debugging)
     */
    public static function getMappings(): array {
        return self::$urlMappings;
    }
}

/**
 * Global function to check and redirect old URLs
 * Can be called at the top of individual PHP files for per-file redirects
 */
function redirectToCleanUrl(string $routeName, array $paramMappings = []): void {
    // Check if SEO redirects are enabled
    if (function_exists('getSetting') && getSetting('seo_redirects', '1') !== '1') {
        return;
    }

    // Check if this is a direct file access (not through router)
    $routeParam = $_GET['route'] ?? '';
    if (!empty($routeParam)) {
        return; // Already using router
    }

    // Build route params
    $routeParams = [];
    $queryParams = [];

    foreach ($_GET as $key => $value) {
        if (isset($paramMappings[$key])) {
            $routeParams[$paramMappings[$key]] = $value;
        } elseif ($key !== 'route') {
            $queryParams[$key] = $value;
        }
    }

    // Check if all required params are present
    foreach ($paramMappings as $queryKey => $routeKey) {
        if (!isset($routeParams[$routeKey])) {
            return; // Missing required param
        }
    }

    // Generate and redirect
    try {
        $cleanUrl = Router::url($routeName, $routeParams, $queryParams);
        header('Location: ' . $cleanUrl, true, 301);
        exit;
    } catch (Exception $e) {
        // Continue without redirect
    }
}
