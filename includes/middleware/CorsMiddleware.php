<?php

/**
 * CORS (Cross-Origin Resource Sharing) Middleware
 *
 * Handles cross-origin requests for API endpoints.
 * Configurable allowed origins, methods, and headers.
 */

require_once __DIR__ . '/MiddlewareInterface.php';

class CorsMiddleware implements MiddlewareInterface
{
    private array $options;

    // Default CORS options
    private const DEFAULTS = [
        'allowed_origins' => [],              // No cross-origin by default (configure in settings)
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'X-API-Key', 'X-CSRF-Token'],
        'exposed_headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
        'max_age' => 86400,                   // Preflight cache: 24 hours
        'allow_credentials' => false,
        'vary_header' => true,                // Add Vary: Origin header
    ];

    /**
     * Create CORS middleware with options
     *
     * @param array $options CORS configuration options
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge(self::DEFAULTS, $options);

        // Load settings from database if available
        if (function_exists('getSetting')) {
            $this->loadSettingsFromDatabase();
        }
    }

    /**
     * Load CORS settings from database
     */
    private function loadSettingsFromDatabase(): void
    {
        $origins = getSetting('cors_allowed_origins', '');
        if (!empty($origins)) {
            $this->options['allowed_origins'] = array_map('trim', explode(',', $origins));
        }

        $methods = getSetting('cors_allowed_methods', '');
        if (!empty($methods)) {
            $this->options['allowed_methods'] = array_map('trim', explode(',', $methods));
        }

        $headers = getSetting('cors_allowed_headers', '');
        if (!empty($headers)) {
            $this->options['allowed_headers'] = array_map('trim', explode(',', $headers));
        }

        $credentials = getSetting('cors_allow_credentials', '0');
        $this->options['allow_credentials'] = $credentials === '1';
    }

    /**
     * Handle the middleware
     */
    public function handle(array $params): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // Check if origin is allowed
        if (!$this->isOriginAllowed($origin)) {
            // No CORS headers for disallowed origins
            return true;
        }

        // Handle preflight OPTIONS request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $this->handlePreflightRequest($origin);
            return false; // Stop processing, preflight handled
        }

        // Add CORS headers for actual request
        $this->addCorsHeaders($origin);

        return true;
    }

    /**
     * Check if origin is allowed
     */
    private function isOriginAllowed(string $origin): bool
    {
        if (empty($origin)) {
            return false;
        }

        $allowedOrigins = $this->options['allowed_origins'];

        // Wildcard allows all -- but NOT when credentials are enabled
        if (in_array('*', $allowedOrigins)) {
            if ($this->options['allow_credentials']) {
                // With credentials, wildcard is not safe. Origin must be explicitly listed.
                // Fall through to check if this specific origin is listed elsewhere.
            } else {
                return true;
            }
        }

        // Exact match
        if (in_array($origin, $allowedOrigins)) {
            return true;
        }

        // Pattern matching (e.g., *.example.com)
        foreach ($allowedOrigins as $allowed) {
            if ($allowed !== '*' && strpos($allowed, '*') !== false) {
                // Split on *, quote each segment, then join with .*
                $segments = explode('*', $allowed);
                $pattern = implode('.*', array_map(function ($s) {
                    return preg_quote($s, '/');
                }, $segments));
                if (preg_match('/^' . $pattern . '$/', $origin)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Handle preflight OPTIONS request
     */
    private function handlePreflightRequest(string $origin): void
    {
        http_response_code(204); // No Content

        // Set origin header
        $this->setOriginHeader($origin);

        // Allowed methods
        header('Access-Control-Allow-Methods: ' . implode(', ', $this->options['allowed_methods']));

        // Allowed headers
        $requestedHeaders = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '';
        if (!empty($requestedHeaders)) {
            // Validate requested headers against allowed list
            $requested = array_map('trim', explode(',', $requestedHeaders));
            $allowed = array_intersect(
                array_map('strtolower', $requested),
                array_map('strtolower', $this->options['allowed_headers'])
            );
            if (!empty($allowed)) {
                header('Access-Control-Allow-Headers: ' . implode(', ', $this->options['allowed_headers']));
            }
        } else {
            header('Access-Control-Allow-Headers: ' . implode(', ', $this->options['allowed_headers']));
        }

        // Max age for preflight caching
        header('Access-Control-Max-Age: ' . $this->options['max_age']);

        // Credentials
        if ($this->options['allow_credentials']) {
            header('Access-Control-Allow-Credentials: true');
        }

        // Vary header
        if ($this->options['vary_header']) {
            header('Vary: Origin');
        }

        exit;
    }

    /**
     * Add CORS headers to actual response
     */
    private function addCorsHeaders(string $origin): void
    {
        $this->setOriginHeader($origin);

        // Exposed headers
        if (!empty($this->options['exposed_headers'])) {
            header('Access-Control-Expose-Headers: ' . implode(', ', $this->options['exposed_headers']));
        }

        // Credentials
        if ($this->options['allow_credentials']) {
            header('Access-Control-Allow-Credentials: true');
        }

        // Vary header
        if ($this->options['vary_header']) {
            header('Vary: Origin');
        }
    }

    /**
     * Set the Access-Control-Allow-Origin header
     */
    private function setOriginHeader(string $origin): void
    {
        $allowedOrigins = $this->options['allowed_origins'];

        if (in_array('*', $allowedOrigins) && !$this->options['allow_credentials']) {
            // Wildcard (not allowed with credentials)
            header('Access-Control-Allow-Origin: *');
        } else {
            // Specific origin
            header('Access-Control-Allow-Origin: ' . $origin);
        }
    }

    /**
     * Create CORS middleware for API routes
     */
    public static function api(): self
    {
        return new self([
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
            'allow_credentials' => false,
        ]);
    }

    /**
     * Create CORS middleware for authenticated API routes
     *
     * Note: With credentials, wildcard origins are rejected. Configure
     * cors_allowed_origins in settings to list specific trusted origins.
     */
    public static function authenticatedApi(): self
    {
        // Do not use wildcard with credentials -- load from settings or deny cross-origin
        $origins = [];
        if (function_exists('getSetting')) {
            $configured = getSetting('cors_allowed_origins', '');
            if (!empty($configured)) {
                $origins = array_map('trim', explode(',', $configured));
            }
        }
        /** @phpstan-ignore booleanAnd.rightAlwaysTrue */
        if (empty($origins) && defined('SITE_URL') && SITE_URL) {
            $parsed = parse_url(SITE_URL);
            if ($parsed && isset($parsed['scheme'], $parsed['host'])) {
                $origins[] = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
            }
        }
        return new self([
            'allowed_origins' => $origins ?: [],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
            'allow_credentials' => true,
        ]);
    }

    /**
     * Create strict CORS middleware (same-origin only)
     */
    public static function strict(): self
    {
        return new self([
            'allowed_origins' => [], // No cross-origin allowed
        ]);
    }
}

/**
 * Quick function to add CORS headers for simple use cases
 */
function cors(array $allowedOrigins = []): void
{
    $middleware = new CorsMiddleware(['allowed_origins' => $allowedOrigins]);
    $middleware->handle([]);
}
