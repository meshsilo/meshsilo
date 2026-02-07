<?php
/**
 * URL Router for Silo
 *
 * Provides clean URL routing with named routes, middleware support,
 * and backward compatibility with file-based routing.
 */

class Router {
    private array $routes = [];
    private array $namedRoutes = [];
    private array $currentGroupOptions = [];
    private static ?Router $instance = null;
    private array $middlewareAliases = [];

    /**
     * Get singleton instance
     */
    public static function getInstance(): Router {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset instance (useful for testing)
     */
    public static function resetInstance(): void {
        self::$instance = null;
    }

    /**
     * Register a GET route
     */
    public function get(string $pattern, array|callable $handler, ?string $name = null): self {
        return $this->addRoute('GET', $pattern, $handler, $name);
    }

    /**
     * Register a POST route
     */
    public function post(string $pattern, array|callable $handler, ?string $name = null): self {
        return $this->addRoute('POST', $pattern, $handler, $name);
    }

    /**
     * Register a PUT route
     */
    public function put(string $pattern, array|callable $handler, ?string $name = null): self {
        return $this->addRoute('PUT', $pattern, $handler, $name);
    }

    /**
     * Register a DELETE route
     */
    public function delete(string $pattern, array|callable $handler, ?string $name = null): self {
        return $this->addRoute('DELETE', $pattern, $handler, $name);
    }

    /**
     * Register a route for any HTTP method
     */
    public function any(string $pattern, array|callable $handler, ?string $name = null): self {
        foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $this->addRoute($method, $pattern, $handler, $name ? "{$name}.{$method}" : null);
        }
        return $this;
    }

    /**
     * Register routes for multiple methods
     */
    public function match(array $methods, string $pattern, array|callable $handler, ?string $name = null): self {
        foreach ($methods as $method) {
            $this->addRoute(strtoupper($method), $pattern, $handler, $name);
        }
        return $this;
    }

    /**
     * Create a route group with shared options
     */
    public function group(array $options, callable $callback): self {
        $previousOptions = $this->currentGroupOptions;

        // Merge group options
        $this->currentGroupOptions = [
            'prefix' => ($previousOptions['prefix'] ?? '') . ($options['prefix'] ?? ''),
            'middleware' => array_merge(
                $previousOptions['middleware'] ?? [],
                $options['middleware'] ?? []
            ),
            'namespace' => $options['namespace'] ?? ($previousOptions['namespace'] ?? ''),
        ];

        $callback($this);

        $this->currentGroupOptions = $previousOptions;
        return $this;
    }

    /**
     * Register middleware aliases
     */
    public function aliasMiddleware(string $alias, string|callable $middleware): self {
        $this->middlewareAliases[$alias] = $middleware;
        return $this;
    }

    /**
     * Add a route to the collection
     */
    private function addRoute(string $method, string $pattern, array|callable $handler, ?string $name): self {
        // Apply group prefix
        $prefix = $this->currentGroupOptions['prefix'] ?? '';
        if ($prefix) {
            $pattern = rtrim($prefix, '/') . '/' . ltrim($pattern, '/');
        }

        // Normalize pattern
        $pattern = '/' . trim($pattern, '/');
        if ($pattern !== '/') {
            $pattern = rtrim($pattern, '/');
        }

        $route = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => $this->patternToRegex($pattern),
            'handler' => $handler,
            'middleware' => $this->currentGroupOptions['middleware'] ?? [],
            'name' => $name,
        ];

        $this->routes[] = $route;

        if ($name && !isset($this->namedRoutes[$name])) {
            $this->namedRoutes[$name] = $pattern;
        }

        return $this;
    }

    /**
     * Add middleware to the last registered route
     */
    public function middleware(string|array $middleware): self {
        if (empty($this->routes)) {
            return $this;
        }

        $lastIndex = count($this->routes) - 1;
        $middlewares = is_array($middleware) ? $middleware : [$middleware];

        $this->routes[$lastIndex]['middleware'] = array_merge(
            $this->routes[$lastIndex]['middleware'],
            $middlewares
        );

        return $this;
    }

    /**
     * Dispatch the current request
     */
    public function dispatch(): bool {
        $method = $_SERVER['REQUEST_METHOD'];

        // Handle method override for PUT/DELETE via POST
        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper($_POST['_method']);
        }

        // Get URI from route parameter or REQUEST_URI
        $uri = $_GET['route'] ?? '';
        if (empty($uri)) {
            $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        }

        // Normalize URI
        $uri = '/' . trim($uri, '/');
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        // Remove base path if configured
        if (defined('BASE_PATH') && BASE_PATH !== '/') {
            $basePath = rtrim(BASE_PATH, '/');
            if (strpos($uri, $basePath) === 0) {
                $uri = substr($uri, strlen($basePath)) ?: '/';
            }
        }

        // Find matching route
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $uri, $matches)) {
                // Extract named parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return $this->handleRoute($route, $params);
            }
        }

        return false;
    }

    /**
     * Handle a matched route
     */
    private function handleRoute(array $route, array $params): bool {
        // Run middleware chain
        if (!$this->runMiddleware($route['middleware'], $params)) {
            return true; // Middleware handled the response
        }

        $handler = $route['handler'];

        // File-based handler
        if (is_array($handler) && isset($handler['file'])) {
            return $this->handleFileRoute($handler, $params);
        }

        // Callable handler
        if (is_callable($handler)) {
            $result = call_user_func_array($handler, array_values($params));
            if (is_string($result)) {
                echo $result;
            }
            return true;
        }

        // Controller@method handler
        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            if (is_string($class)) {
                $class = new $class();
            }
            $result = call_user_func_array([$class, $method], array_values($params));
            if (is_string($result)) {
                echo $result;
            }
            return true;
        }

        return false;
    }

    /**
     * Handle a file-based route
     */
    private function handleFileRoute(array $handler, array $params): bool {
        $file = $handler['file'];

        // Resolve file path
        $filePath = __DIR__ . '/../' . ltrim($file, '/');
        if (!file_exists($filePath)) {
            return false;
        }

        // Map route parameters to $_GET for backward compatibility
        if (isset($handler['map'])) {
            foreach ($handler['map'] as $param => $getKey) {
                if (isset($params[$param])) {
                    $_GET[$getKey] = $params[$param];
                }
            }
        } else {
            // Auto-map all params to $_GET
            foreach ($params as $key => $value) {
                $_GET[$key] = $value;
            }
        }

        // Store matched route info
        $_SERVER['ROUTE_NAME'] = $handler['name'] ?? null;
        $_SERVER['ROUTE_PARAMS'] = $params;

        // Include the file
        require $filePath;
        return true;
    }

    /**
     * Run middleware chain
     */
    private function runMiddleware(array $middlewares, array $params): bool {
        foreach ($middlewares as $middleware) {
            $result = $this->executeMiddleware($middleware, $params);
            if ($result === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Execute a single middleware
     */
    private function executeMiddleware(string|callable $middleware, array $params): bool {
        // Parse middleware with parameters (e.g., "permission:edit")
        $middlewareParams = [];
        if (is_string($middleware) && strpos($middleware, ':') !== false) {
            [$middleware, $paramStr] = explode(':', $middleware, 2);
            $middlewareParams = explode(',', $paramStr);
        }

        // Resolve middleware alias
        if (is_string($middleware) && isset($this->middlewareAliases[$middleware])) {
            $middleware = $this->middlewareAliases[$middleware];
        }

        // Handle built-in middleware names
        if (is_string($middleware)) {
            switch ($middleware) {
                case 'auth':
                    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
                        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
                        header('Location: ' . self::url('login'));
                        exit;
                    }
                    return true;

                case 'guest':
                    if (function_exists('isLoggedIn') && isLoggedIn()) {
                        header('Location: ' . self::url('home'));
                        exit;
                    }
                    return true;

                case 'admin':
                    if (!function_exists('isAdmin') || !isAdmin()) {
                        $_SESSION['error'] = 'You do not have permission to access this page.';
                        header('Location: ' . self::url('home'));
                        exit;
                    }
                    return true;

                case 'permission':
                    $permission = $middlewareParams[0] ?? '';
                    $permConst = 'PERM_' . strtoupper($permission);
                    if (!defined($permConst)) {
                        // Fail-closed: deny access if permission constant is not defined
                        error_log("Undefined permission constant: $permConst");
                        http_response_code(403);
                        $_SESSION['error'] = 'You do not have permission to perform this action.';
                        header('Location: ' . self::url('home'));
                        exit;
                    }
                    if (function_exists('hasPermission') && !hasPermission(constant($permConst))) {
                        $_SESSION['error'] = 'You do not have permission to perform this action.';
                        header('Location: ' . self::url('home'));
                        exit;
                    }
                    return true;

                case 'csrf':
                    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
                        $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                        if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
                            http_response_code(419);
                            echo json_encode(['error' => 'CSRF token mismatch']);
                            return false;
                        }
                    }
                    return true;

                case 'ratelimit':
                    // Rate limiting: ratelimit:maxRequests,windowSeconds,prefix
                    require_once __DIR__ . '/middleware/RateLimitMiddleware.php';
                    $maxRequests = (int)($middlewareParams[0] ?? 60);
                    $windowSeconds = (int)($middlewareParams[1] ?? 60);
                    $prefix = $middlewareParams[2] ?? 'default';
                    $limiter = new RateLimitMiddleware($maxRequests, $windowSeconds, $prefix);
                    return $limiter->handle($params);

                case 'maintenance':
                    require_once __DIR__ . '/middleware/MaintenanceMiddleware.php';
                    $maintenance = new MaintenanceMiddleware();
                    return $maintenance->handle($params);

                case 'seo':
                case 'seoredirect':
                    require_once __DIR__ . '/middleware/SeoRedirectMiddleware.php';
                    $seo = new SeoRedirectMiddleware();
                    return $seo->handle($params);

                case 'cors':
                    require_once __DIR__ . '/middleware/CorsMiddleware.php';
                    $cors = new CorsMiddleware();
                    return $cors->handle($params);

                case 'signed':
                    require_once __DIR__ . '/SignedUrl.php';
                    return SignedUrl::verify();

                default:
                    // Try to load middleware class
                    $className = ucfirst($middleware) . 'Middleware';
                    $classFile = __DIR__ . '/middleware/' . $className . '.php';
                    if (file_exists($classFile)) {
                        require_once $classFile;
                        if (class_exists($className)) {
                            $instance = new $className(...$middlewareParams);
                            return $instance->handle($params);
                        }
                    }
                    return true;
            }
        }

        // Callable middleware
        if (is_callable($middleware)) {
            return $middleware($params) !== false;
        }

        return true;
    }

    /**
     * Convert route pattern to regex
     */
    private function patternToRegex(string $pattern): string {
        // Escape regex special characters except our placeholders
        $regex = preg_quote($pattern, '#');

        // Restore placeholders and convert to named capture groups
        // {param} - required parameter, matches anything except /
        // {param?} - optional parameter
        // {param:regex} - parameter with custom regex constraint

        $regex = preg_replace_callback(
            '/\\\\{([a-zA-Z_][a-zA-Z0-9_]*)(?:\\\\:([^}]+))?(\\\\\?)?\\\\}/',
            function($matches) {
                $name = $matches[1];
                $constraint = isset($matches[2]) ? stripslashes($matches[2]) : '[^/]+';
                $optional = !empty($matches[3]);

                if ($optional) {
                    return "(?:/(?P<{$name}>{$constraint}))?";
                }
                return "(?P<{$name}>{$constraint})";
            },
            $regex
        );

        return '#^' . $regex . '/?$#';
    }

    /**
     * Generate URL for a named route
     */
    public static function url(string $name, array $params = [], array $query = []): string {
        $router = self::getInstance();

        if (!isset($router->namedRoutes[$name])) {
            // Fall back to treating name as a path
            $url = '/' . ltrim($name, '/');
        } else {
            $pattern = $router->namedRoutes[$name];

            // Replace parameters in pattern
            $url = preg_replace_callback(
                '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]+)?(\?)?\}/',
                function($m) use ($params) {
                    $paramName = $m[1];
                    $optional = !empty($m[2]);

                    if (isset($params[$paramName])) {
                        return $params[$paramName];
                    }

                    if ($optional) {
                        return '';
                    }

                    return '';
                },
                $pattern
            );

            // Clean up any double slashes from optional params
            $url = preg_replace('#/+#', '/', $url);
        }

        // Add base URL if configured
        $baseUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';

        // Add query string
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $baseUrl . $url;
    }

    /**
     * Check if a named route exists
     */
    public function hasRoute(string $name): bool {
        return isset($this->namedRoutes[$name]);
    }

    /**
     * Get all registered routes (for debugging)
     */
    public function getRoutes(): array {
        return $this->routes;
    }

    /**
     * Get all named routes (for debugging)
     */
    public function getNamedRoutes(): array {
        return $this->namedRoutes;
    }

    /**
     * Check if current route matches a name
     */
    public static function is(string $name): bool {
        return ($_SERVER['ROUTE_NAME'] ?? '') === $name;
    }

    /**
     * Get current route parameters
     */
    public static function params(): array {
        return $_SERVER['ROUTE_PARAMS'] ?? [];
    }

    /**
     * Get a specific route parameter
     */
    public static function param(string $name, mixed $default = null): mixed {
        return $_SERVER['ROUTE_PARAMS'][$name] ?? $default;
    }

    // ========================================================================
    // ROUTE CACHING
    // ========================================================================

    private const CACHE_DIR = __DIR__ . '/../storage/cache';
    private const CACHE_FILE = 'routes.cache.php';

    /**
     * Load routes from cache if available and valid
     *
     * @return bool True if cache was loaded successfully
     */
    public function loadFromCache(): bool {
        $cacheFile = self::CACHE_DIR . '/' . self::CACHE_FILE;

        if (!file_exists($cacheFile)) {
            return false;
        }

        // Check if routes.php was modified after cache
        $routesFile = __DIR__ . '/routes.php';
        if (file_exists($routesFile) && filemtime($routesFile) > filemtime($cacheFile)) {
            return false; // Cache is stale
        }

        try {
            $cached = require $cacheFile;

            if (!is_array($cached) || !isset($cached['routes']) || !isset($cached['named'])) {
                return false;
            }

            $this->routes = $cached['routes'];
            $this->namedRoutes = $cached['named'];

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Save current routes to cache
     *
     * @return bool True if cache was saved successfully
     */
    public function saveToCache(): bool {
        $cacheDir = self::CACHE_DIR;

        if (!is_dir($cacheDir)) {
            if (!mkdir($cacheDir, 0755, true)) {
                return false;
            }
        }

        $cacheFile = $cacheDir . '/' . self::CACHE_FILE;

        // Filter out non-serializable handlers (closures)
        $cacheableRoutes = array_filter($this->routes, function($route) {
            return !is_callable($route['handler']) || is_array($route['handler']);
        });

        $content = "<?php\n// Auto-generated route cache - " . date('Y-m-d H:i:s') . "\n";
        $content .= "// Delete this file to regenerate the cache\n";
        $content .= "return " . var_export([
            'routes' => $cacheableRoutes,
            'named' => $this->namedRoutes,
            'generated' => time()
        ], true) . ";\n";

        return file_put_contents($cacheFile, $content, LOCK_EX) !== false;
    }

    /**
     * Clear the route cache
     *
     * @return bool True if cache was cleared
     */
    public static function clearCache(): bool {
        $cacheFile = self::CACHE_DIR . '/' . self::CACHE_FILE;

        if (file_exists($cacheFile)) {
            return unlink($cacheFile);
        }

        return true;
    }

    /**
     * Check if route cache exists and is valid
     *
     * @return bool True if cache is valid
     */
    public static function isCacheValid(): bool {
        $cacheFile = self::CACHE_DIR . '/' . self::CACHE_FILE;

        if (!file_exists($cacheFile)) {
            return false;
        }

        $routesFile = __DIR__ . '/routes.php';
        if (file_exists($routesFile) && filemtime($routesFile) > filemtime($cacheFile)) {
            return false;
        }

        return true;
    }

    /**
     * Get cache statistics
     *
     * @return array Cache information
     */
    public static function getCacheStats(): array {
        $cacheFile = self::CACHE_DIR . '/' . self::CACHE_FILE;

        if (!file_exists($cacheFile)) {
            return [
                'exists' => false,
                'valid' => false,
                'size' => 0,
                'age' => null,
                'routes' => 0
            ];
        }

        $cached = @include $cacheFile;

        return [
            'exists' => true,
            'valid' => self::isCacheValid(),
            'size' => filesize($cacheFile),
            'age' => time() - filemtime($cacheFile),
            'routes' => is_array($cached['routes'] ?? null) ? count($cached['routes']) : 0,
            'generated' => $cached['generated'] ?? filemtime($cacheFile)
        ];
    }

    /**
     * Load routes with caching (recommended for production)
     *
     * @param bool $forceRefresh Force cache regeneration
     * @return Router The router instance
     */
    public static function cached(bool $forceRefresh = false): Router {
        $router = self::getInstance();

        // Try to load from cache first (unless forced refresh)
        if (!$forceRefresh && $router->loadFromCache()) {
            return $router;
        }

        // Load routes normally
        require_once __DIR__ . '/routes.php';

        // Save to cache for next request
        $router->saveToCache();

        return $router;
    }
}
