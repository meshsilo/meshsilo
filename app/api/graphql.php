<?php
/**
 * GraphQL API Endpoint
 *
 * Usage:
 *   POST /api/graphql
 *   Content-Type: application/json
 *
 *   { "query": "{ models(limit: 10) { id name } }", "variables": {} }
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/GraphQL.php';
require_once __DIR__ . '/../../includes/api-auth.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';

header('Content-Type: application/json');

// Handle CORS using configurable settings
require_once __DIR__ . '/../../includes/middleware/CorsMiddleware.php';
$cors = CorsMiddleware::api();
if (!$cors->handle([])) {
    exit; // Preflight request handled
}

// Get current user ID if authenticated
$userId = null;
// Session callers keep existing behavior (mutations gated per-resolver). API-key
// callers may only run mutations when the key carries write/admin scope.
$canMutate = true;
$apiToken = null;
if (isLoggedIn()) {
    $userId = getCurrentUser()['id'] ?? null;
} else {
    // Check for API key authentication via X-API-Key header or Authorization: Bearer
    $token = null;
    if (!empty($_SERVER['HTTP_X_API_KEY'])) {
        $token = $_SERVER['HTTP_X_API_KEY'];
    } else {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }
    if ($token) {
        $apiToken = $token;
        $db = getDB();
        $stmt = $db->prepare("SELECT ak.user_id, ak.permissions, u.is_admin
            FROM api_keys ak
            JOIN users u ON ak.user_id = u.id
            WHERE ak.key_hash = :hash AND ak.is_active = 1 AND (ak.expires_at IS NULL OR ak.expires_at > CURRENT_TIMESTAMP)");
        $stmt->execute([':hash' => hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $userId = $row['user_id'];
            // Reuse the REST permission convention: admin keys and admin users pass;
            // otherwise the key must explicitly carry the write scope to mutate.
            $apiKeyUser = [
                'permissions_array' => json_decode($row['permissions'] ?? '', true) ?: [],
                'is_admin' => $row['is_admin'],
            ];
            $canMutate = apiHasPermission($apiKeyUser, API_PERM_WRITE);
        }
    }
}

// Require authentication: reject if there is no valid session and no valid API key
if ($userId === null) {
    http_response_code(401);
    echo json_encode([
        'errors' => [['message' => 'Unauthorized. Provide a valid API key via X-API-Key header or Authorization: Bearer, or sign in.']]
    ]);
    exit;
}

// Apply rate limiting (mirrors the REST API entry point)
$rateLimitKey = $apiToken ?: ($userId ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
$tier = RateLimiter::getTierForUser($userId, $apiToken);
$rateLimitResult = RateLimiter::check($rateLimitKey, $tier, 'api:graphql');
RateLimiter::setHeaders($rateLimitResult);
if (!$rateLimitResult['allowed']) {
    http_response_code(429);
    echo json_encode([
        'errors' => [['message' => 'Rate limit exceeded. Retry after ' . max(0, ($rateLimitResult['reset'] ?? time()) - time()) . ' seconds.']]
    ]);
    exit;
}

// Parse request
$input = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $input = [
        'query' => $_GET['query'] ?? '',
        'variables' => isset($_GET['variables']) ? json_decode($_GET['variables'], true) : null,
        'operationName' => $_GET['operationName'] ?? null,
    ];
}

if (!$input || empty($input['query'])) {
    http_response_code(400);
    echo json_encode([
        'errors' => [['message' => 'No query provided']]
    ]);
    exit;
}

$query = $input['query'];
$variables = $input['variables'] ?? null;
$operationName = $input['operationName'] ?? null;

// Query length limit (prevent DoS via extremely large queries)
$maxQueryLength = 10000;
if (strlen($query) > $maxQueryLength) {
    http_response_code(400);
    echo json_encode([
        'errors' => [['message' => "Query too large. Maximum length is $maxQueryLength characters."]]
    ]);
    exit;
}

// Query depth limit (prevent DoS via deeply nested queries)
$maxDepth = 10;
$depth = 0;
$maxFound = 0;
for ($i = 0; $i < strlen($query); $i++) {
    if ($query[$i] === '{') {
        $depth++;
        if ($depth > $maxFound) {
            $maxFound = $depth;
        }
    } elseif ($query[$i] === '}') {
        $depth--;
    }
}
if ($maxFound > $maxDepth) {
    http_response_code(400);
    echo json_encode([
        'errors' => [['message' => "Query depth exceeds maximum of $maxDepth levels."]]
    ]);
    exit;
}

// Block introspection queries in production
$isDebug = defined('APP_DEBUG') && APP_DEBUG === true;
if (!$isDebug) {
    if (preg_match('/\b__schema\b|\b__type\b/i', $query)) {
        http_response_code(400);
        echo json_encode([
            'errors' => [['message' => 'Introspection queries are disabled in production.']]
        ]);
        exit;
    }
}

// Execute GraphQL query
$result = GraphQL::execute($query, $variables, $userId, $canMutate);

// Return result
echo json_encode($result, JSON_PRETTY_PRINT);
