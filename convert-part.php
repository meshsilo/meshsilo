<?php
/**
 * AJAX endpoint for converting STL parts to 3MF
 */
require_once 'includes/config.php';
require_once 'includes/converter.php';

header('Content-Type: application/json');

// Require edit permission
if (!canEdit()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$partId = (int)($_POST['part_id'] ?? $_GET['part_id'] ?? 0);

if (!$partId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Part ID required']);
    exit;
}

if ($action === 'estimate') {
    // Estimate conversion savings without converting
    $result = estimatePartConversion($partId);
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
        echo json_encode(['success' => false, 'error' => 'Cannot estimate conversion for this file']);
    }
} elseif ($action === 'convert') {
    // Actually convert the file
    $result = convertPartTo3MF($partId);
    echo json_encode($result);
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action. Use "estimate" or "convert"']);
}
