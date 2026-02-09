<?php
/**
 * Silo REST API Entry Point
 *
 * Routes:
 * GET    /api/models              - List models
 * GET    /api/models/{id}         - Get single model
 * POST   /api/models              - Create model (upload)
 * PUT    /api/models/{id}         - Update model
 * DELETE /api/models/{id}         - Delete model
 * GET    /api/categories          - List categories
 * GET    /api/tags                - List tags
 * GET    /api/collections         - List collections
 * GET    /api/stats               - Get statistics
 */

// Prevent session-based auth redirect
define('API_REQUEST', true);

// Set JSON content type
header('Content-Type: application/json');

// Handle CORS using configurable settings
require_once __DIR__ . '/../../includes/middleware/CorsMiddleware.php';
$cors = CorsMiddleware::api();
if (!$cors->handle([])) {
    exit; // Preflight request handled
}

// Load configuration without triggering auth redirect
require_once __DIR__ . '/../../includes/logger.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api-auth.php';
require_once __DIR__ . '/../../includes/api-helpers.php';
require_once __DIR__ . '/../../includes/ApiVersion.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';

// Set up error handling
setupErrorHandler();

// Detect and validate API version
$apiVersion = ApiVersion::fromRequest();
try {
    $apiVersion->validate();
} catch (Exception $e) {
    apiError($e->getMessage(), 400);
}
$apiVersion->addDeprecationHeaders();

// Parse the request
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Remove query string and base path
$uri = parse_url($uri, PHP_URL_PATH);
$uri = preg_replace('#^.*/api/#', '', $uri);
// Strip version prefix if present (e.g., v1/)
$uri = ApiVersion::stripVersionFromUri('/' . $uri);
$uri = trim($uri, '/');

// Parse route segments
$segments = $uri ? explode('/', $uri) : [];
$resource = $segments[0] ?? '';
$id = $segments[1] ?? null;
$subResource = $segments[2] ?? null;

// Authenticate the request
$apiUser = authenticateApiRequest();
if (!$apiUser) {
    apiError('Unauthorized. Provide a valid API key via X-API-Key header or api_key parameter.', 401);
}

// Apply rate limiting
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$tier = RateLimiter::getTierForUser($apiUser['id'] ?? null, $apiKey);
$rateLimitResult = RateLimiter::check(
    $apiKey ?: ($apiUser['id'] ?? $_SERVER['REMOTE_ADDR']),
    $tier,
    'api:' . $resource
);
RateLimiter::setHeaders($rateLimitResult);

if (!$rateLimitResult['allowed']) {
    http_response_code(429);
    echo json_encode([
        'error' => 'Rate limit exceeded',
        'retry_after' => $rateLimitResult['reset'] - time(),
        'tier' => $rateLimitResult['tier']
    ]);
    exit;
}

// Log API request
logApiRequest($apiUser['id'], $method, $uri);

// Route the request
try {
    switch ($resource) {
        case '':
        case 'health':
            apiResponse([
                'status' => 'ok',
                'api' => $apiVersion->getVersionInfo()
            ]);
            break;

        case 'version':
            apiResponse($apiVersion->getVersionInfo());
            break;

        case 'models':
            require_once __DIR__ . '/routes/models.php';
            handleModelsRoute($method, $id, $subResource, $apiUser);
            break;

        case 'categories':
            require_once __DIR__ . '/routes/categories.php';
            handleCategoriesRoute($method, $id, $apiUser);
            break;

        case 'tags':
            require_once __DIR__ . '/routes/tags.php';
            handleTagsRoute($method, $id, $apiUser);
            break;

        case 'collections':
            require_once __DIR__ . '/routes/collections.php';
            handleCollectionsRoute($method, $id, $apiUser);
            break;

        case 'stats':
            require_once __DIR__ . '/routes/stats.php';
            handleStatsRoute($method, $apiUser);
            break;

        default:
            // Plugin hook: api_routes - custom API endpoints for integrations, external services
            if (class_exists('PluginManager')) {
                $pluginApiRoutes = PluginManager::applyFilter('api_routes', []);
                if (isset($pluginApiRoutes[$resource]) && is_callable($pluginApiRoutes[$resource])) {
                    call_user_func($pluginApiRoutes[$resource], $method, $id, $subResource, $apiUser);
                    exit;
                }
            }
            apiError('Not found', 404);
    }
} catch (Exception $e) {
    logException($e, ['api_route' => $resource, 'method' => $method]);
    apiError('Internal server error', 500);
}
