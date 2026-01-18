<?php
require_once 'includes/config.php';

// Require edit permission
requirePermission(PERM_EDIT);

header('Content-Type: application/json');

$db = getDB();

// Get parameters
$partId = isset($_POST['part_id']) ? (int)$_POST['part_id'] : 0;
$printType = isset($_POST['print_type']) ? trim($_POST['print_type']) : null;

if (!$partId) {
    echo json_encode(['success' => false, 'error' => 'Part ID required']);
    exit;
}

// Validate print type
if ($printType !== null && !in_array($printType, ['fdm', 'sla', ''])) {
    echo json_encode(['success' => false, 'error' => 'Invalid print type']);
    exit;
}

// Convert empty string to null
if ($printType === '') {
    $printType = null;
}

// Get part to verify it exists
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
$stmt->bindValue(':id', $partId, SQLITE3_INTEGER);
$result = $stmt->execute();
$part = $result->fetchArray(SQLITE3_ASSOC);

if (!$part) {
    echo json_encode(['success' => false, 'error' => 'Part not found']);
    exit;
}

try {
    $stmt = $db->prepare('UPDATE models SET print_type = :print_type WHERE id = :id');
    $stmt->bindValue(':print_type', $printType, $printType === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':id', $partId, SQLITE3_INTEGER);
    $stmt->execute();

    logInfo('Part print type updated', [
        'part_id' => $partId,
        'print_type' => $printType,
        'user_id' => $_SESSION['user_id'] ?? null
    ]);

    echo json_encode(['success' => true, 'print_type' => $printType]);
} catch (Exception $e) {
    logException($e, ['action' => 'update_print_type', 'part_id' => $partId]);
    echo json_encode(['success' => false, 'error' => 'Failed to update']);
}
