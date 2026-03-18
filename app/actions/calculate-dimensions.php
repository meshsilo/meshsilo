<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/dimensions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonError('Not authenticated', 401);
}

requireCsrfJson();

$modelId = isset($_POST['model_id']) ? (int)$_POST['model_id'] : 0;

if (!$modelId) {
    echo json_encode(['success' => false, 'error' => 'No model specified']);
    exit;
}

// Calculate dimensions with detailed error reporting
require_once __DIR__ . '/../../includes/dedup.php';

try {
    $db = getDB();
    $stmt = $db->prepare('SELECT id, file_path, file_type, dedup_path, part_count FROM models WHERE id = :id');
    $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $model = $result->fetchArray(PDO::FETCH_ASSOC);

    if (!$model) {
        echo json_encode(['success' => false, 'error' => 'Model not found in database']);
        exit;
    }

    $isMultiPart = ($model['part_count'] ?? 0) > 0;
    $partsCalculated = 0;
    $partResults = [];

    if ($isMultiPart) {
        // Calculate dimensions for every part
        $partStmt = $db->prepare('SELECT id, file_path, file_type, dedup_path FROM models WHERE parent_id = :pid ORDER BY id ASC');
        $partStmt->bindValue(':pid', $modelId, PDO::PARAM_INT);
        $partResult = $partStmt->execute();
        $parts = [];
        while ($row = $partResult->fetchArray(PDO::FETCH_ASSOC)) {
            $parts[] = $row;
        }

        // Track bounding box across all parts for the parent
        $overallMinX = $overallMinY = $overallMinZ = PHP_FLOAT_MAX;
        $overallMaxX = $overallMaxY = $overallMaxZ = -PHP_FLOAT_MAX;

        foreach ($parts as $part) {
            $partPath = getAbsoluteFilePath($part);
            if (!$partPath || !is_file($partPath)) {
                $partResults[] = ['id' => $part['id'], 'status' => 'file_missing'];
                continue;
            }

            $dims = parseModelDimensions($partPath, $part['file_type']);
            if (!$dims) {
                $partResults[] = ['id' => $part['id'], 'status' => 'parse_failed'];
                continue;
            }

            // Store dimensions on the part itself
            updateModelDimensions($part['id'], $dims['dim_x'], $dims['dim_y'], $dims['dim_z'], $dims['dim_unit']);
            $partsCalculated++;
            $partResults[] = ['id' => $part['id'], 'status' => 'ok', 'dims' => $dims];

            // Expand overall bounding box (treat each part's dims as its extent)
            $overallMaxX = max($overallMaxX, $dims['dim_x']);
            $overallMaxY = max($overallMaxY, $dims['dim_y']);
            $overallMaxZ = max($overallMaxZ, $dims['dim_z']);
        }

        // Store the largest part dimensions on the parent as a representative size
        if ($partsCalculated > 0) {
            updateModelDimensions($modelId, $overallMaxX, $overallMaxY, $overallMaxZ, 'mm');
        }
    } else {
        // Single model — calculate directly
        $filePath = getAbsoluteFilePath($model);
        if (!$filePath || !is_file($filePath)) {
            echo json_encode(['success' => false, 'error' => 'File not found on disk', 'debug' => ['resolved_path' => $filePath, 'db_path' => $model['file_path']]]);
            exit;
        }

        $dimensions = parseModelDimensions($filePath, $model['file_type']);
        if (!$dimensions) {
            echo json_encode(['success' => false, 'error' => 'Could not parse dimensions from file', 'debug' => ['file_type' => $model['file_type'], 'file_size' => filesize($filePath)]]);
            exit;
        }

        $stored = updateModelDimensions($modelId, $dimensions['dim_x'], $dimensions['dim_y'], $dimensions['dim_z'], $dimensions['dim_unit']);
        if (!$stored) {
            echo json_encode(['success' => false, 'error' => 'Failed to save dimensions to database']);
            exit;
        }
    }

    $savedDimensions = getModelDimensions($modelId);
    if (!$savedDimensions || $savedDimensions['dim_x'] === null) {
        echo json_encode(['success' => false, 'error' => 'No dimensions could be calculated from any part']);
        exit;
    }

    $response = [
        'success' => true,
        'dimensions' => $savedDimensions,
        'formatted' => formatDimensions($savedDimensions['dim_x'], $savedDimensions['dim_y'], $savedDimensions['dim_z'], $savedDimensions['dim_unit'])
    ];
    if ($isMultiPart) {
        $response['parts_calculated'] = $partsCalculated;
        $response['parts_total'] = count($parts);
    }
    echo json_encode($response);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Exception: ' . $e->getMessage(), 'debug' => ['file' => $e->getFile(), 'line' => $e->getLine()]]);
}
