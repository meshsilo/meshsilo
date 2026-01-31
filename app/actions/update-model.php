<?php
/**
 * Update model properties
 */
require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!canEdit()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$modelId = (int)($_POST['model_id'] ?? 0);

if (!$modelId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid model ID']);
    exit;
}

$db = getDB();

// Verify model exists
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
$stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
$result = $stmt->execute();
$model = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$model) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Model not found']);
    exit;
}

// Build update query based on provided fields
$updates = [];
$params = [':id' => $modelId];
$logDetails = [];

// Handle is_archived
if (isset($_POST['is_archived'])) {
    $updates[] = 'is_archived = :is_archived';
    $params[':is_archived'] = $_POST['is_archived'] === '1' ? 1 : 0;
    $logDetails['is_archived'] = $params[':is_archived'];
}

// Handle license
if (isset($_POST['license'])) {
    $updates[] = 'license = :license';
    $params[':license'] = $_POST['license'];
    $logDetails['license'] = $_POST['license'];
}

// Handle name
if (isset($_POST['name']) && trim($_POST['name']) !== '') {
    $updates[] = 'name = :name';
    $params[':name'] = trim($_POST['name']);
    $logDetails['name'] = $params[':name'];
}

// Handle description
if (isset($_POST['description'])) {
    $updates[] = 'description = :description';
    $params[':description'] = trim($_POST['description']);
    $logDetails['description'] = 'updated';
}

// Handle creator
if (isset($_POST['creator'])) {
    $updates[] = 'creator = :creator';
    $params[':creator'] = trim($_POST['creator']);
    $logDetails['creator'] = $params[':creator'];
}

// Handle source_url
if (isset($_POST['source_url'])) {
    $updates[] = 'source_url = :source_url';
    $params[':source_url'] = trim($_POST['source_url']);
    $logDetails['source_url'] = 'updated';
}

// Handle notes (for parts)
if (isset($_POST['notes'])) {
    $updates[] = 'notes = :notes';
    $params[':notes'] = trim($_POST['notes']);
    $logDetails['notes'] = 'updated';
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
    $logDetails['is_printed'] = $isPrinted;
}

if (empty($updates)) {
    echo json_encode(['success' => false, 'error' => 'No fields to update']);
    exit;
}

$sql = 'UPDATE models SET ' . implode(', ', $updates) . ' WHERE id = :id';
$stmt = $db->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

if ($stmt->execute()) {
    // Log the activity
    $action = isset($_POST['is_archived']) ? ($params[':is_archived'] ? 'archive' : 'unarchive') : 'edit';
    logActivity($action, 'model', $modelId, $model['name'], $logDetails);

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database update failed']);
}
