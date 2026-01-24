<?php
/**
 * Calculate Volume API Endpoint
 *
 * Calculates and stores volume for a model
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/VolumeCalculator.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$modelId = (int)($_POST['model_id'] ?? $_GET['model_id'] ?? 0);

if (!$modelId) {
    echo json_encode(['success' => false, 'error' => 'Model ID required']);
    exit;
}

$db = getDB();

// Get model info
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
$stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
$result = $stmt->execute();
$model = $result->fetchArray(SQLITE3_ASSOC);

if (!$model) {
    echo json_encode(['success' => false, 'error' => 'Model not found']);
    exit;
}

// If model has parts, calculate total volume of all parts
$totalVolume = 0;
$partVolumes = [];

if ($model['part_count'] > 0) {
    $stmt = $db->prepare('SELECT * FROM models WHERE parent_id = :id');
    $stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    while ($part = $result->fetchArray(SQLITE3_ASSOC)) {
        $filePath = getAbsoluteFilePath($part);
        if ($filePath && file_exists($filePath)) {
            $volume = VolumeCalculator::calculateVolume($filePath, $part['file_type']);
            if ($volume !== null) {
                $partVolumes[] = [
                    'id' => $part['id'],
                    'name' => $part['name'],
                    'volume_cm3' => round($volume, 2)
                ];
                VolumeCalculator::updateModelVolume($part['id'], $volume);
                $totalVolume += $volume;
            }
        }
    }
} else {
    // Single model
    $filePath = getAbsoluteFilePath($model);
    if ($filePath && file_exists($filePath)) {
        $totalVolume = VolumeCalculator::calculateVolume($filePath, $model['file_type']);
        if ($totalVolume !== null) {
            VolumeCalculator::updateModelVolume($modelId, $totalVolume);
        }
    }
}

if ($totalVolume === null || $totalVolume <= 0) {
    echo json_encode(['success' => false, 'error' => 'Could not calculate volume']);
    exit;
}

// Get cost estimate
$material = $_POST['material'] ?? getSetting('default_material', 'pla');
$costEstimate = VolumeCalculator::estimateCost($totalVolume, $material);

echo json_encode([
    'success' => true,
    'model_id' => $modelId,
    'volume_cm3' => round($totalVolume, 2),
    'part_volumes' => $partVolumes,
    'cost_estimate' => $costEstimate
]);
