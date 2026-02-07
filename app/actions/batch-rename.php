<?php
/**
 * Batch Rename Parts
 *
 * Renames multiple parts using a pattern with placeholders.
 * Placeholders: {name} = current name, {index} = sequential number, {ext} = extension
 */
require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if (!canEdit()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$parentId = (int)($input['parent_id'] ?? 0);
$partIds = $input['part_ids'] ?? [];
$pattern = trim($input['pattern'] ?? '');
$prefix = trim($input['prefix'] ?? '');
$suffix = trim($input['suffix'] ?? '');

if (!$parentId || empty($partIds)) {
    echo json_encode(['success' => false, 'error' => 'Parent ID and part IDs required']);
    exit;
}

if (!$pattern && !$prefix && !$suffix) {
    echo json_encode(['success' => false, 'error' => 'Pattern, prefix, or suffix required']);
    exit;
}

// Verify user has permission
$db = getDB();
$user = getCurrentUser();

$stmt = $db->prepare('SELECT user_id FROM models WHERE id = :id AND parent_id IS NULL');
$stmt->bindValue(':id', $parentId, PDO::PARAM_INT);
$result = $stmt->execute();
$model = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$model) {
    echo json_encode(['success' => false, 'error' => 'Model not found']);
    exit;
}

// NULL user_id = accessible to all authenticated users (backward compatibility)
// Cast to int to handle PDO returning strings
if ($model['user_id'] !== null && (int)$model['user_id'] !== (int)$user['id'] && !$user['is_admin']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// Get current part info
$partIds = array_map('intval', (array)$partIds);
$placeholders = implode(',', array_fill(0, count($partIds), '?'));

$stmt = $db->prepare("SELECT id, name, file_type FROM models WHERE id IN ($placeholders) AND parent_id = ?");
$paramIndex = 1;
foreach ($partIds as $id) {
    $stmt->bindValue($paramIndex++, $id, PDO::PARAM_INT);
}
$stmt->bindValue($paramIndex, $parentId, PDO::PARAM_INT);
$result = $stmt->execute();

$parts = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $parts[$row['id']] = $row;
}

if (empty($parts)) {
    echo json_encode(['success' => false, 'error' => 'No valid parts found']);
    exit;
}

// Generate new names and update
$index = 1;
$renamed = [];

try {
    $db->beginTransaction();

    foreach ($partIds as $partId) {
        if (!isset($parts[$partId])) continue;

        $part = $parts[$partId];
        $currentName = $part['name'];
        $ext = $part['file_type'];

        // Apply pattern
        if ($pattern) {
            $newName = str_replace(
                ['{name}', '{index}', '{ext}'],
                [$currentName, $index, $ext],
                $pattern
            );
        } else {
            $newName = $currentName;
        }

        // Apply prefix and suffix
        $newName = $prefix . $newName . $suffix;

        // Sanitize name
        $newName = preg_replace('/[<>:"\/\\|?*]/', '', $newName);
        $newName = trim($newName);

        if (!$newName) {
            $newName = 'Part_' . $index;
        }

        // Update
        $stmt = $db->prepare('UPDATE models SET name = :name WHERE id = :id AND parent_id = :parent_id');
        $stmt->bindValue(':name', $newName, PDO::PARAM_STR);
        $stmt->bindValue(':id', $partId, PDO::PARAM_INT);
        $stmt->bindValue(':parent_id', $parentId, PDO::PARAM_INT);
        $stmt->execute();

        $renamed[] = [
            'id' => $partId,
            'old_name' => $currentName,
            'new_name' => $newName
        ];

        $index++;
    }

    $db->commit();

    logActivity($user['id'], 'batch_rename', 'model', $parentId, count($renamed) . ' parts renamed');

    echo json_encode([
        'success' => true,
        'renamed' => $renamed,
        'count' => count($renamed)
    ]);

} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
