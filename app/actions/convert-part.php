<?php
/**
 * AJAX endpoint for converting STL parts to 3MF
 */

// Suppress HTML error output for JSON endpoint
ini_set('display_errors', '0');
ini_set('html_errors', '0');

// Catch any stray output (warnings, notices) that would corrupt JSON
ob_start();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/converter.php';

header('Content-Type: application/json');

// Require edit permission
if (!canEdit()) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF validation for state-changing actions
if ($action === 'convert') {
    if (!Csrf::check()) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid request token']);
        exit;
    }
}
$partId = (int)($_POST['part_id'] ?? $_GET['part_id'] ?? 0);

if (!$partId) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Part ID required']);
    exit;
}

if ($action === 'estimate') {
    $result = estimatePartConversion($partId);
    $strayOutput = ob_get_clean();
    if ($result) {
        echo json_encode([
            'success' => true,
            'original_size' => $result['original_size'],
            'estimated_size' => $result['estimated_size'],
            'estimated_savings' => $result['estimated_savings'],
            'estimated_savings_percent' => $result['estimated_savings_percent'],
            'worth_converting' => $result['worth_converting'],
            'vertices' => $result['vertices'],
            'triangles' => $result['triangles']
        ]);
    } else {
        $error = 'Cannot estimate conversion for this file';
        if ($strayOutput) {
            logWarning('Conversion estimate produced unexpected output', ['output' => substr($strayOutput, 0, 500), 'part_id' => $partId]);
        }
        echo json_encode(['success' => false, 'error' => $error]);
    }
} elseif ($action === 'convert') {
    $result = convertPartTo3MF($partId);
    $strayOutput = ob_get_clean();
    if ($strayOutput) {
        logWarning('Conversion produced unexpected output', ['output' => substr($strayOutput, 0, 500), 'part_id' => $partId]);
    }
    echo json_encode($result);
} else {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action. Use "estimate" or "convert"']);
}
