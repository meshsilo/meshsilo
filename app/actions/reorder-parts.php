<?php
require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// CSRF validation
if (!Csrf::check()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid request token']);
    exit;
}

$parentId = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : 0;
$partIds = isset($_POST['part_ids']) ? array_map('intval', (array)$_POST['part_ids']) : [];

if (!$parentId || empty($partIds)) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Verify user has permission to edit this model
$db = getDB();
$stmt = $db->prepare('SELECT user_id FROM models WHERE id = :id');
$stmt->bindValue(':id', $parentId, PDO::PARAM_INT);
$result = $stmt->execute();
$model = $result->fetchArray(PDO::FETCH_ASSOC);

$user = getCurrentUser();
if (!$model || ($model['user_id'] != $user['id'] && !$user['is_admin'])) {
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// Reorder parts
$result = reorderParts($parentId, $partIds);

if ($result) {
    logActivity($user['id'], 'reorder_parts', 'model', $parentId);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to reorder parts']);
}
