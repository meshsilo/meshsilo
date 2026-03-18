<?php
/**
 * Toggle favorite status for a model
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/features.php';

header('Content-Type: application/json');

// Check if favorites feature is enabled
if (!isFeatureEnabled('favorites')) {
    jsonError('Favorites feature is disabled', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

if (!isLoggedIn()) {
    jsonError('Not logged in', 401);
}

requireCsrfJson();

$modelId = (int)($_POST['model_id'] ?? 0);

if (!$modelId) {
    jsonError('Invalid model ID');
}

// Verify model exists
$db = getDB();
$stmt = $db->prepare('SELECT id, name FROM models WHERE id = :id AND parent_id IS NULL');
$stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
$result = $stmt->execute();
$model = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$model) {
    jsonError('Model not found', 404);
}

$result = toggleFavorite($modelId);

if ($result['success']) {
    // Log the activity
    $action = $result['favorited'] ? 'favorite' : 'unfavorite';
    logActivity($action, 'model', $modelId, $model['name']);
}

echo json_encode($result);
