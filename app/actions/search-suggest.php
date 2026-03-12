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

$stmt = $db->prepare(
    "SELECT id, name FROM models
     WHERE parent_id IS NULL AND name LIKE :q
     ORDER BY name
     LIMIT 8"
);
$stmt->bindValue(':q', $searchTerm);
$stmt->execute();

$suggestions = [];
while ($row = $stmt->fetch()) {
    $suggestions[] = [
        'id'   => (int)$row['id'],
        'name' => $row['name'],
    ];
}

echo json_encode(['suggestions' => $suggestions]);
