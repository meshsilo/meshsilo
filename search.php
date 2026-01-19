<?php
// Search API endpoint for AJAX search
require_once 'includes/config.php';

header('Content-Type: application/json');

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 20) : 10;

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

$db = getDB();

// Search models by name, description, creator, and collection
$stmt = $db->prepare('
    SELECT id, name, creator, collection, description
    FROM models
    WHERE parent_id IS NULL
      AND (
        name LIKE :query
        OR description LIKE :query
        OR creator LIKE :query
        OR collection LIKE :query
      )
    ORDER BY
        CASE WHEN name LIKE :exact THEN 0 ELSE 1 END,
        name
    LIMIT :limit
');

$stmt->bindValue(':query', '%' . $query . '%', SQLITE3_TEXT);
$stmt->bindValue(':exact', $query . '%', SQLITE3_TEXT);
$stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
$result = $stmt->execute();

$models = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $models[] = [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'creator' => $row['creator'],
        'collection' => $row['collection']
    ];
}

echo json_encode($models);
