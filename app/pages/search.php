<?php
/**
 * Search API endpoint for AJAX search
 * Uses full-text search when available, falls back to LIKE queries
 */
require_once 'includes/config.php';
require_once 'includes/Search.php';

header('Content-Type: application/json');

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 20) : 10;

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

// Try full-text search first
$search = Search::getInstance();
$searchResult = $search->search($query, ['limit' => $limit]);

if (!empty($searchResult['results'])) {
    // Full-text search returned results
    $models = array_map(function($row) {
        return [
            'id' => (int)$row['model_id'],
            'name' => $row['name'],
            'creator' => $row['creator'] ?? '',
            'collection' => $row['collection'] ?? ''
        ];
    }, $searchResult['results']);

    echo json_encode($models);
    exit;
}

// Fallback to LIKE query if FTS not available or no results
$db = getDB();

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

$stmt->bindValue(':query', '%' . $query . '%', PDO::PARAM_STR);
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
