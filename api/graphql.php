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

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
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

// Execute GraphQL query
$result = GraphQL::execute($query, $variables, $userId);

// Return result
echo json_encode($result, JSON_PRETTY_PRINT);
