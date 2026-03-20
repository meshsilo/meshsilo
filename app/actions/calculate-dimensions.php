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
    jsonError('No model specified');
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
        jsonError('Model not found in database');
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
            jsonError('File not found on disk');
        }

        $dimensions = parseModelDimensions($filePath, $model['file_type']);
        if (!$dimensions) {
            jsonError('Could not parse dimensions from file');
        }

        $stored = updateModelDimensions($modelId, $dimensions['dim_x'], $dimensions['dim_y'], $dimensions['dim_z'], $dimensions['dim_unit']);
        if (!$stored) {
            jsonError('Failed to save dimensions to database');
        }
    }

    $savedDimensions = getModelDimensions($modelId);
    if (!$savedDimensions || $savedDimensions['dim_x'] === null) {
        jsonError('No dimensions could be calculated from any part');
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
    jsonError('Exception: ' . $e->getMessage());
}
