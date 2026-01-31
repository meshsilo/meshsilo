<?php
/**
 * Mesh Analysis and Repair API Endpoint
 *
 * Actions:
 * - analyze: Analyze STL file for mesh issues
 * - repair: Attempt to repair mesh (requires admesh)
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/MeshAnalyzer.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'analyze';
$modelId = (int)($_POST['model_id'] ?? $_GET['model_id'] ?? 0);

if (!$modelId) {
    echo json_encode(['success' => false, 'error' => 'Model ID required']);
    exit;
}

$db = getDB();

// Get model info
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
$stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
$result = $stmt->execute();
$model = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$model) {
    echo json_encode(['success' => false, 'error' => 'Model not found']);
    exit;
}

// Check if this is a multi-part parent model
$isMultiPart = ($model['part_count'] ?? 0) > 0;

if ($isMultiPart) {
    // Multi-part model: process all STL parts
    $partStmt = $db->prepare('SELECT * FROM models WHERE parent_id = :pid ORDER BY id ASC');
    $partStmt->bindValue(':pid', $modelId, PDO::PARAM_INT);
    $partResult = $partStmt->execute();
    $parts = [];
    while ($row = $partResult->fetchArray(PDO::FETCH_ASSOC)) {
        $parts[] = $row;
    }

    $partResults = [];
    $anySuccess = false;

    foreach ($parts as $part) {
        if (strtolower($part['file_type']) !== 'stl') {
            $partResults[] = ['id' => $part['id'], 'name' => $part['name'], 'status' => 'skipped', 'reason' => 'Not STL'];
            continue;
        }
        $partPath = getAbsoluteFilePath($part);
        if (!$partPath || !is_file($partPath)) {
            $partResults[] = ['id' => $part['id'], 'name' => $part['name'], 'status' => 'skipped', 'reason' => 'File not found'];
            continue;
        }

        switch ($action) {
            case 'analyze':
                $analysis = MeshAnalyzer::analyze($partPath);
                if (!isset($analysis['error'])) {
                    MeshAnalyzer::updateModelMeshStatus($part['id'], $analysis);
                    $partResults[] = ['id' => $part['id'], 'name' => $part['name'], 'status' => 'ok', 'analysis' => $analysis];
                    $anySuccess = true;
                } else {
                    $partResults[] = ['id' => $part['id'], 'name' => $part['name'], 'status' => 'error', 'error' => $analysis['error']];
                }
                break;

            case 'repair':
                if (!canEdit()) {
                    $partResults[] = ['id' => $part['id'], 'name' => $part['name'], 'status' => 'error', 'error' => 'No edit permission'];
                    continue 2;
                }
                if (!MeshAnalyzer::isAdmeshAvailable()) {
                    echo json_encode(['success' => false, 'error' => 'admesh is not installed']);
                    exit;
                }
                $repairResult = MeshAnalyzer::repair($partPath);
                if ($repairResult['success']) {
                    $analysis = MeshAnalyzer::analyze($partPath);
                    MeshAnalyzer::updateModelMeshStatus($part['id'], $analysis);
                    $partResults[] = ['id' => $part['id'], 'name' => $part['name'], 'status' => 'ok'];
                    $anySuccess = true;
                } else {
                    $partResults[] = ['id' => $part['id'], 'name' => $part['name'], 'status' => 'error', 'error' => $repairResult['error']];
                }
                break;
        }
    }

    if ($action === 'status') {
        echo json_encode(['success' => true, 'model_id' => $modelId, 'multi_part' => true, 'parts' => count($parts)]);
    } else {
        echo json_encode([
            'success' => $anySuccess,
            'model_id' => $modelId,
            'multi_part' => true,
            'parts_processed' => count($partResults),
            'part_results' => $partResults
        ]);
    }
    exit;
}

// Single model / individual part
if (strtolower($model['file_type']) !== 'stl') {
    echo json_encode(['success' => false, 'error' => 'Only STL files can be analyzed']);
    exit;
}

$filePath = getAbsoluteFilePath($model);
if (!$filePath || !is_file($filePath)) {
    echo json_encode(['success' => false, 'error' => 'Model file not found']);
    exit;
}

switch ($action) {
    case 'analyze':
        $analysis = MeshAnalyzer::analyze($filePath);

        if (isset($analysis['error'])) {
            echo json_encode(['success' => false, 'error' => $analysis['error']]);
            exit;
        }

        MeshAnalyzer::updateModelMeshStatus($modelId, $analysis);

        echo json_encode([
            'success' => true,
            'model_id' => $modelId,
            'analysis' => $analysis
        ]);
        break;

    case 'repair':
        if (!canEdit()) {
            echo json_encode(['success' => false, 'error' => 'Edit permission required']);
            exit;
        }

        if (!MeshAnalyzer::isAdmeshAvailable()) {
            echo json_encode([
                'success' => false,
                'error' => 'admesh is not installed. Please install it with: apt-get install admesh'
            ]);
            exit;
        }

        $repairResult = MeshAnalyzer::repair($filePath);

        if (!$repairResult['success']) {
            echo json_encode([
                'success' => false,
                'error' => $repairResult['error'],
                'details' => $repairResult['output'] ?? null
            ]);
            exit;
        }

        $analysis = MeshAnalyzer::analyze($filePath);
        MeshAnalyzer::updateModelMeshStatus($modelId, $analysis);

        if (function_exists('logActivity')) {
            logActivity(
                getCurrentUser()['id'],
                'mesh_repair',
                'model',
                $modelId,
                $model['name']
            );
        }

        echo json_encode([
            'success' => true,
            'model_id' => $modelId,
            'message' => 'Mesh repaired successfully',
            'analysis' => $analysis,
            'repair_output' => $repairResult['output'] ?? ''
        ]);
        break;

    case 'status':
        $status = MeshAnalyzer::getMeshStatus($model);

        echo json_encode([
            'success' => true,
            'model_id' => $modelId,
            'status' => $status,
            'admesh_available' => MeshAnalyzer::isAdmeshAvailable()
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
