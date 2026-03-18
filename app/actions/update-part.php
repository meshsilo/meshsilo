<?php
require_once __DIR__ . '/../../includes/config.php';

// Require edit permission
requirePermission(PERM_EDIT);

header('Content-Type: application/json');

// CSRF validation
requireCsrfJson();

$db = getDB();

// Get parameters
$partId = isset($_POST['part_id']) ? (int)$_POST['part_id'] : 0;

if (!$partId) {
    echo json_encode(['success' => false, 'error' => 'Part ID required']);
    exit;
}

// Get part to verify it exists
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
$stmt->bindValue(':id', $partId, PDO::PARAM_INT);
$result = $stmt->execute();
$part = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$part) {
    echo json_encode(['success' => false, 'error' => 'Part not found']);
    exit;
}

// Build update query based on provided fields
$updates = [];
$params = [':id' => $partId];
$logData = ['part_id' => $partId];

// Handle print_type
if (isset($_POST['print_type'])) {
    $printType = trim($_POST['print_type']);
    if ($printType !== '' && !in_array($printType, ['fdm', 'sla'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid print type']);
        exit;
    }
    $updates[] = 'print_type = :print_type';
    $params[':print_type'] = $printType === '' ? null : $printType;
    $logData['print_type'] = $params[':print_type'];
}

// Handle is_printed
if (isset($_POST['is_printed'])) {
    $isPrinted = $_POST['is_printed'] === '1' ? 1 : 0;
    $updates[] = 'is_printed = :is_printed';
    $params[':is_printed'] = $isPrinted;
    if ($isPrinted) {
        $updates[] = 'printed_at = CURRENT_TIMESTAMP';
    } else {
        $updates[] = 'printed_at = NULL';
    }
    $logData['is_printed'] = $isPrinted;
}

// Handle notes
if (isset($_POST['notes'])) {
    $updates[] = 'notes = :notes';
    $params[':notes'] = trim($_POST['notes']);
    $logData['notes'] = 'updated';
}

if (empty($updates)) {
    echo json_encode(['success' => false, 'error' => 'No fields to update']);
    exit;
}

try {
    $sql = 'UPDATE models SET ' . implode(', ', $updates) . ' WHERE id = :id';
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
        } elseif (is_int($value)) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
    }
    $stmt->execute();

    logInfo('Part updated', $logData);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    logException($e, ['action' => 'update_part', 'part_id' => $partId]);
    echo json_encode(['success' => false, 'error' => 'Failed to update']);
}
