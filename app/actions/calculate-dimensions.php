<?php
require_once '../includes/config.php';
require_once '../includes/dimensions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$modelId = isset($_POST['model_id']) ? (int)$_POST['model_id'] : 0;

if (!$modelId) {
    echo json_encode(['success' => false, 'error' => 'No model specified']);
    exit;
}

// Calculate dimensions
$result = calculateAndStoreDimensions($modelId);

if ($result) {
    $dimensions = getModelDimensions($modelId);
    echo json_encode([
        'success' => true,
        'dimensions' => $dimensions,
        'formatted' => formatDimensions($dimensions['dim_x'], $dimensions['dim_y'], $dimensions['dim_z'], $dimensions['dim_unit'])
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to calculate dimensions']);
}
