<?php
/**
 * Search API endpoint for AJAX search
 * Uses full-text search when available, falls back to LIKE queries
 */
require_once 'includes/config.php';

header('Content-Type: application/json');

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 20)) : 10;

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

// Suggestion search over the primary model fields (LIKE-based; the /browse
// listing has its own FTS path via BrowseQuery).
$db = getDB();

// Each named placeholder is used once: MySQL with emulated prepares off
// (server-side prepares) forbids reusing the same named marker, so :query is
// numbered :query1..:query4 rather than repeated.
$stmt = $db->prepare('
    SELECT id, name, creator, collection, description
    FROM models
    WHERE parent_id IS NULL
      AND (
        name LIKE :query1
        OR description LIKE :query2
        OR creator LIKE :query3
        OR collection LIKE :query4
      )
    ORDER BY
        CASE WHEN name LIKE :exact THEN 0 ELSE 1 END,
        name
    LIMIT :limit
');

$like = '%' . $query . '%';
$stmt->bindValue(':query1', $like, PDO::PARAM_STR);
$stmt->bindValue(':query2', $like, PDO::PARAM_STR);
$stmt->bindValue(':query3', $like, PDO::PARAM_STR);
$stmt->bindValue(':query4', $like, PDO::PARAM_STR);
$stmt->bindValue(':exact', $query . '%', PDO::PARAM_STR);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$result = $stmt->execute();

$models = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $models[] = [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'creator' => $row['creator'],
        'collection' => $row['collection']
    ];
}

echo json_encode($models);
