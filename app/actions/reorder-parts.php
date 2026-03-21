<?php
require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonError('Not authenticated', 401);
}

requireCsrfJson();

$parentId = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : 0;
$partIds = isset($_POST['part_ids']) ? array_map('intval', (array)$_POST['part_ids']) : [];

if (!$parentId || empty($partIds)) {
    jsonError('Invalid parameters');
}

// Verify user has permission to edit this model
$db = getDB();
$stmt = $db->prepare('SELECT user_id FROM models WHERE id = :id');
$stmt->bindValue(':id', $parentId, PDO::PARAM_INT);
$result = $stmt->execute();
$model = $result->fetchArray(PDO::FETCH_ASSOC);

$user = getCurrentUser();
if (!$model || ($model['user_id'] != $user['id'] && !$user['is_admin'])) {
    jsonError('Permission denied', 403);
}

// Reorder parts
$result = reorderParts($parentId, $partIds);

if ($result) {
    logActivity('reorder_parts', 'model', $parentId);
    jsonSuccess();
} else {
    jsonError('Failed to reorder parts');
}
