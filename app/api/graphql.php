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

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/GraphQL.php';

header('Content-Type: application/json');

// Handle CORS using configurable settings
require_once __DIR__ . '/../includes/middleware/CorsMiddleware.php';
$cors = CorsMiddleware::api();
if (!$cors->handle([])) {
    exit; // Preflight request handled
}

// Get current user ID if authenticated
$userId = null;
if (isLoggedIn()) {
    $userId = getCurrentUserId();
} else {
    // Check for API key authentication
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
        $token = $matches[1];
        // Validate API key
        $db = getDB();
        $stmt = $db->prepare("SELECT user_id FROM api_keys WHERE key_hash = :hash AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)");
        $stmt->execute([':hash' => hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $userId = $row['user_id'];
        }
    }
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
$result = GraphQL::execute($query, $variables, $userId);

// Return result
echo json_encode($result, JSON_PRETTY_PRINT);
