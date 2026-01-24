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
$stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
$result = $stmt->execute();
$model = $result->fetchArray(SQLITE3_ASSOC);

if (!$model) {
    echo json_encode(['success' => false, 'error' => 'Model not found']);
    exit;
}

// Only STL files can be analyzed/repaired
if (strtolower($model['file_type']) !== 'stl') {
    echo json_encode(['success' => false, 'error' => 'Only STL files can be analyzed']);
    exit;
}

$filePath = getAbsoluteFilePath($model);
if (!$filePath || !file_exists($filePath)) {
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

        // Update model status in database
        MeshAnalyzer::updateModelMeshStatus($modelId, $analysis);

        echo json_encode([
            'success' => true,
            'model_id' => $modelId,
            'analysis' => $analysis
        ]);
        break;

    case 'repair':
        // Check permission
        if (!canEdit()) {
            echo json_encode(['success' => false, 'error' => 'Edit permission required']);
            exit;
        }

        // Check if admesh is available
        if (!MeshAnalyzer::isAdmeshAvailable()) {
            echo json_encode([
                'success' => false,
                'error' => 'admesh is not installed. Please install it with: apt-get install admesh'
            ]);
            exit;
        }

        // Attempt repair
        $repairResult = MeshAnalyzer::repair($filePath);

        if (!$repairResult['success']) {
            echo json_encode([
                'success' => false,
                'error' => $repairResult['error'],
                'details' => $repairResult['output'] ?? null
            ]);
            exit;
        }

        // Re-analyze after repair
        $analysis = MeshAnalyzer::analyze($filePath);
        MeshAnalyzer::updateModelMeshStatus($modelId, $analysis);

        // Log the repair
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
        // Just return current stored status
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
