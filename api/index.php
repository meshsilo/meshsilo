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

// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Load configuration without triggering auth redirect
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/api-auth.php';
require_once __DIR__ . '/../includes/api-helpers.php';

// Set up error handling
setupErrorHandler();

// Parse the request
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Remove query string and base path
$uri = parse_url($uri, PHP_URL_PATH);
$uri = preg_replace('#^.*/api/#', '', $uri);
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

// Log API request
logApiRequest($apiUser['id'], $method, $uri);

// Route the request
try {
    switch ($resource) {
        case '':
        case 'health':
            apiResponse(['status' => 'ok', 'version' => '1.0']);
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

        case 'webhooks':
            require_once __DIR__ . '/routes/webhooks.php';
            handleWebhooksRoute($method, $id, $apiUser);
            break;

        default:
            apiError('Not found', 404);
    }
} catch (Exception $e) {
    logException($e, ['api_route' => $resource, 'method' => $method]);
    apiError('Internal server error', 500);
}
