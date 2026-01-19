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
// This includes both collections from the collections table and any collection names used in models
$result = $db->query('
    SELECT
        COALESCE(c.name, m.collection) as name,
        c.description,
        COUNT(DISTINCT m.id) as count
    FROM models m
    LEFT JOIN collections c ON c.name = m.collection
    WHERE m.collection IS NOT NULL
      AND m.collection != ""
      AND m.parent_id IS NULL
    GROUP BY COALESCE(c.name, m.collection)
    ORDER BY count DESC, name
');

$collections = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
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
            <p class="text-muted">No collections yet. Collections are created when you assign models to a collection during upload.</p>
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
