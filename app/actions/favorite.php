<?php
/**
 * Toggle favorite status for a model
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/features.php';

header('Content-Type: application/json');

// Check if favorites feature is enabled
if (!isFeatureEnabled('favorites')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Favorites feature is disabled']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$modelId = (int)($_POST['model_id'] ?? 0);

if (!$modelId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid model ID']);
    exit;
}

// Verify model exists
$db = getDB();
$stmt = $db->prepare('SELECT id, name FROM models WHERE id = :id AND parent_id IS NULL');
$stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
$result = $stmt->execute();
$model = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$model) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Model not found']);
    exit;
}

$result = toggleFavorite($modelId);

if ($result['success']) {
    // Log the activity
    $action = $result['favorited'] ? 'favorite' : 'unfavorite';
    logActivity($action, 'model', $modelId, $model['name']);
}

echo json_encode($result);
