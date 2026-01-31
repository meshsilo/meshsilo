<?php
require_once 'includes/config.php';

// Check if collections are enabled
if (getSetting('enable_collections', '1') !== '1') {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Collections';
$activePage = 'collections';

$db = getDB();

// Get all collections with model counts
// This includes collections from the collections table AND any ad-hoc collection names used in models
$result = $db->query('
    SELECT c.name, c.description, COUNT(m.id) as count
    FROM collections c
    LEFT JOIN models m ON m.collection = c.name AND m.parent_id IS NULL
    GROUP BY c.id
    UNION
    SELECT m.collection as name, NULL as description, COUNT(*) as count
    FROM models m
    WHERE m.collection IS NOT NULL
      AND m.collection != ""
      AND m.parent_id IS NULL
      AND m.collection NOT IN (SELECT name FROM collections)
    GROUP BY m.collection
    ORDER BY count DESC, name
');

$collections = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $collections[] = $row;
}

require_once 'includes/header.php';
?>

        <div class="page-container-wide">
            <div class="page-header">
                <h1>Collections</h1>
                <p>Browse models by collection</p>
            </div>

            <?php if (empty($collections)): ?>
            <p class="text-muted">No collections yet. Create collections in the admin panel or assign models to a collection during upload.</p>
            <?php else: ?>
            <div class="collections-grid">
                <?php foreach ($collections as $collection): ?>
                <a href="collection.php?name=<?= urlencode($collection['name']) ?>" class="collection-card">
                    <h2 class="collection-name"><?= htmlspecialchars($collection['name']) ?></h2>
                    <?php if (!empty($collection['description'])): ?>
                    <p class="collection-description"><?= htmlspecialchars($collection['description']) ?></p>
                    <?php endif; ?>
                    <p class="collection-count"><?= $collection['count'] ?> model<?= $collection['count'] !== 1 ? 's' : '' ?></p>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

<?php require_once 'includes/footer.php'; ?>
