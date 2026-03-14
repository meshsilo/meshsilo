<?php
require_once 'includes/config.php';
require_once 'includes/features.php';

// Require feature to be enabled
requireFeature('collections');

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

// Get a thumbnail for each collection from its first model
foreach ($collections as &$col) {
    $col['thumbnail'] = null;
    $thumbStmt = $db->prepare('SELECT thumbnail_path FROM models WHERE collection = :name AND parent_id IS NULL AND thumbnail_path IS NOT NULL AND thumbnail_path != "" LIMIT 1');
    $thumbStmt->bindValue(':name', $col['name'], PDO::PARAM_STR);
    $thumbResult = $thumbStmt->execute();
    $thumbRow = $thumbResult->fetchArray(PDO::FETCH_ASSOC);
    if ($thumbRow) {
        $col['thumbnail'] = $thumbRow['thumbnail_path'];
    }
}
unset($col);

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
                <a href="<?= route('browse', [], ['collection' => $collection['name']]) ?>" class="collection-card" tabindex="0">
                    <?php if (!empty($collection['thumbnail'])): ?>
                    <img src="/assets/<?= htmlspecialchars($collection['thumbnail']) ?>" alt="" class="collection-thumb" loading="lazy">
                    <?php endif; ?>
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
