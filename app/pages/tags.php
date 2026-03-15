<?php
require_once 'includes/config.php';
require_once 'includes/features.php';

$pageTitle = 'Tags';
$activePage = 'tags';

// Require feature to be enabled
requireFeature('tags');

$db = getDB();

// Get all tags with model counts
$result = $db->query('
    SELECT t.*, COUNT(mt.model_id) as model_count
    FROM tags t
    LEFT JOIN model_tags mt ON t.id = mt.tag_id
    GROUP BY t.id
    ORDER BY model_count DESC, t.name ASC
');

$tags = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $tags[] = $row;
}

require_once 'includes/header.php';
?>

        <div class="page-container-wide">
            <div class="page-header">
                <h1>Tags</h1>
                <p><?= count($tags) ?> tag<?= count($tags) !== 1 ? 's' : '' ?></p>
            </div>

            <?php if (empty($tags)): ?>
                <p class="text-muted" style="text-align: center; padding: 3rem;">No tags yet. Tags can be added when uploading or editing models.</p>
            <?php else: ?>
                <div class="categories-grid" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));">
                    <?php foreach ($tags as $tag): ?>
                    <a href="<?= route('browse', [], ['tag' => $tag['id']]) ?>" class="category-card" tabindex="0" style="border-left: 4px solid <?= htmlspecialchars($tag['color']) ?>;">
                        <span class="category-name"><?= htmlspecialchars($tag['name']) ?></span>
                        <span class="category-count"><?= $tag['model_count'] ?> model<?= $tag['model_count'] !== 1 ? 's' : '' ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

<?php require_once 'includes/footer.php'; ?>
