<?php
/**
 * Search Suggestions Endpoint
 * Returns matching model names for autocomplete dropdown.
 * GET /actions/search-suggest?q=<query>
 */

ob_start();
require_once __DIR__ . '/../../includes/config.php';
ob_end_clean();

header('Content-Type: application/json');

// On login-required installs, return empty for unauthenticated users to avoid
// leaking model names. On open installs, serve suggestions to everyone.
if (!isLoggedIn() && getSetting('require_login', '0')) {
    echo json_encode(['suggestions' => []]);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) {
    echo json_encode(['suggestions' => []]);
    exit;
}

$db = getDB();
$searchTerm = '%' . $q . '%';

// Search parent models by name, description, creator, or notes
$stmt = $db->prepare(
    "SELECT id, name FROM models
     WHERE parent_id IS NULL AND (name LIKE :q1 OR description LIKE :q2 OR creator LIKE :q3 OR notes LIKE :q4)
     ORDER BY CASE WHEN name LIKE :q5 THEN 0 ELSE 1 END, name
     LIMIT 8"
);
$stmt->bindValue(':q1', $searchTerm);
$stmt->bindValue(':q2', $searchTerm);
$stmt->bindValue(':q3', $searchTerm);
$stmt->bindValue(':q4', $searchTerm);
$stmt->bindValue(':q5', $searchTerm);
$stmt->execute();

$suggestions = [];
$seenParentIds = [];
while ($row = $stmt->fetch()) {
    $suggestions[] = [
        'id'   => (int)$row['id'],
        'name' => $row['name'],
    ];
    $seenParentIds[] = (int)$row['id'];
}

// Search part names and return their parent models
if (count($suggestions) < 8) {
    $remaining = 8 - count($suggestions);
    $excludeClause = '';
    $excludeParams = [];
    if (!empty($seenParentIds)) {
        $placeholders = implode(',', array_map(fn($i) => ':excl_' . $i, array_keys($seenParentIds)));
        $excludeClause = "AND m.id NOT IN ($placeholders)";
        foreach ($seenParentIds as $i => $pid) {
            $excludeParams[':excl_' . $i] = $pid;
        }
    }

    $partStmt = $db->prepare(
        "SELECT DISTINCT m.id, m.name FROM models p
         JOIN models m ON p.parent_id = m.id
         WHERE p.parent_id IS NOT NULL AND p.name LIKE :q $excludeClause
         ORDER BY m.name
         LIMIT :lim"
    );
    $partStmt->bindValue(':q', $searchTerm);
    foreach ($excludeParams as $k => $v) {
        $partStmt->bindValue($k, $v, PDO::PARAM_INT);
    }
    $partStmt->bindValue(':lim', $remaining, PDO::PARAM_INT);
    $partStmt->execute();

    while ($row = $partStmt->fetch()) {
        $suggestions[] = [
            'id'   => (int)$row['id'],
            'name' => $row['name'],
            'match' => 'part',
        ];
    }
}

// Search tags by name
$tagStmt = $db->prepare(
    "SELECT id, name FROM tags WHERE name LIKE :q ORDER BY name LIMIT 3"
);
$tagStmt->bindValue(':q', $searchTerm);
$tagStmt->execute();
while ($row = $tagStmt->fetch()) {
    $suggestions[] = [
        'name' => $row['name'],
        'type' => 'tag',
        'url'  => '/browse?tags[]=' . (int)$row['id'],
    ];
}

// Search categories by name
$catStmt = $db->prepare(
    "SELECT id, name FROM categories WHERE name LIKE :q ORDER BY name LIMIT 3"
);
$catStmt->bindValue(':q', $searchTerm);
$catStmt->execute();
while ($row = $catStmt->fetch()) {
    $suggestions[] = [
        'name' => $row['name'],
        'type' => 'category',
        'url'  => '/browse?category=' . (int)$row['id'],
    ];
}

echo json_encode(['suggestions' => $suggestions]);
