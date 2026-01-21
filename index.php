<?php
require_once 'includes/config.php';
require_once 'includes/dedup.php';
$pageTitle = 'Home';
$activePage = 'browse';

$db = getDB();

// Get recent models (only parent/standalone models, not parts)
$result = $db->query('SELECT * FROM models WHERE parent_id IS NULL ORDER BY created_at DESC LIMIT 8');
$models = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    // For multi-part models, get the first part for preview and print types
    if ($row['part_count'] > 0) {
        $partStmt = $db->prepare('SELECT file_path, file_type, file_size, dedup_path FROM models WHERE parent_id = :parent_id ORDER BY original_path ASC LIMIT 1');
        $partStmt->bindValue(':parent_id', $row['id'], SQLITE3_INTEGER);
        $partResult = $partStmt->execute();
        $firstPart = $partResult->fetchArray(SQLITE3_ASSOC);
        if ($firstPart) {
            // Add cache buster to prevent stale model files after conversion
            $row['preview_path'] = getRealFilePath($firstPart) . '?v=' . ($firstPart['file_size'] ?? time());
            $row['preview_type'] = $firstPart['file_type'];
        }

        // Get distinct print types for this model's parts
        $printStmt = $db->prepare('SELECT DISTINCT print_type FROM models WHERE parent_id = :parent_id AND print_type IS NOT NULL');
        $printStmt->bindValue(':parent_id', $row['id'], SQLITE3_INTEGER);
        $printResult = $printStmt->execute();
        $printTypes = [];
        while ($printRow = $printResult->fetchArray(SQLITE3_ASSOC)) {
            $printTypes[] = $printRow['print_type'];
        }
        $row['print_types'] = $printTypes;
    } else {
        // Add cache buster for single models
        $row['preview_path'] = getRealFilePath($row) . '?v=' . ($row['file_size'] ?? time());
        $row['preview_type'] = $row['file_type'];
        $row['print_types'] = $row['print_type'] ? [$row['print_type']] : [];
    }
    $models[] = $row;
}

// Get categories with model counts
$result = $db->query('SELECT c.*, COUNT(mc.model_id) as model_count FROM categories c LEFT JOIN model_categories mc ON c.id = mc.category_id GROUP BY c.id ORDER BY c.name');
$categories = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $categories[] = $row;
}

$message = '';
$messageType = 'success';

if (isset($_GET['uploaded'])) {
    $count = (int)$_GET['uploaded'];
    if ($count === 1) {
        $message = 'Model uploaded successfully!';
    } else {
        $message = $count . ' models uploaded successfully!';
    }
}

// Check for session messages
if (isset($_SESSION['success'])) {
    $message = $_SESSION['success'];
    $messageType = 'success';
    unset($_SESSION['success']);
} elseif (isset($_SESSION['error'])) {
    $message = $_SESSION['error'];
    $messageType = 'error';
    unset($_SESSION['error']);
}

// Get recently viewed models
$recentlyViewed = getRecentlyViewed(6);
// Enhance with preview data
foreach ($recentlyViewed as &$rv) {
    if ($rv['part_count'] > 0) {
        $partStmt = $db->prepare('SELECT file_path, file_type, file_size, dedup_path FROM models WHERE parent_id = :parent_id ORDER BY original_path ASC LIMIT 1');
        $partStmt->bindValue(':parent_id', $rv['id'], SQLITE3_INTEGER);
        $partResult = $partStmt->execute();
        $firstPart = $partResult->fetchArray(SQLITE3_ASSOC);
        if ($firstPart) {
            $rv['preview_path'] = getRealFilePath($firstPart) . '?v=' . ($firstPart['file_size'] ?? time());
            $rv['preview_type'] = $firstPart['file_type'];
        }
    } else {
        $rv['preview_path'] = getRealFilePath($rv) . '?v=' . ($rv['file_size'] ?? time());
        $rv['preview_type'] = $rv['file_type'];
    }
}
unset($rv);

// Get popular tags
$popularTags = [];
if (getSetting('enable_tags', '1') === '1') {
    $tagResult = $db->query('SELECT t.*, COUNT(mt.model_id) as model_count FROM tags t JOIN model_tags mt ON t.id = mt.tag_id GROUP BY t.id ORDER BY model_count DESC LIMIT 10');
    while ($row = $tagResult->fetchArray(SQLITE3_ASSOC)) {
        $popularTags[] = $row;
    }
}

require_once 'includes/header.php';
?>

        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>" style="max-width: 1400px; margin: 1rem auto;"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <section class="hero">
            <div class="hero-content">
                <h1>Your 3D Model Library</h1>
                <p>Store, organize, and share your 3D print files in one place.</p>
            </div>
        </section>

        <?php if (!empty($recentlyViewed)): ?>
        <section class="models-section recently-viewed-section">
            <div class="section-header">
                <h2>Recently Viewed</h2>
            </div>
            <div class="recently-viewed-grid">
                <?php foreach ($recentlyViewed as $rv): ?>
                <article class="model-card recently-viewed-card" onclick="window.location='model.php?id=<?= $rv['id'] ?>'">
                    <div class="model-thumbnail"
                        <?php if (!empty($rv['preview_path'])): ?>
                        data-model-url="<?= htmlspecialchars($rv['preview_path']) ?>"
                        data-file-type="<?= htmlspecialchars($rv['preview_type']) ?>"
                        <?php endif; ?>>
                        <span class="file-type-badge">.<?= htmlspecialchars($rv['preview_type'] ?? $rv['file_type'] ?? 'stl') ?></span>
                    </div>
                    <div class="model-info">
                        <h3 class="model-title"><?= htmlspecialchars($rv['name']) ?></h3>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="models-section">
            <div class="section-header">
                <h2>Recent Models</h2>
                <a href="browse.php" class="btn btn-secondary btn-small">View All</a>
            </div>
            <div class="models-grid">
                <?php if (empty($models)): ?>
                    <p class="text-muted">No models yet. <a href="upload.php">Upload your first model!</a></p>
                <?php else: ?>
                    <?php foreach ($models as $model): ?>
                    <article class="model-card <?= $model['is_archived'] ? 'archived' : '' ?>" onclick="window.location='model.php?id=<?= $model['id'] ?>'">
                        <div class="model-thumbnail"
                            <?php if (!empty($model['preview_path'])): ?>
                            data-model-url="<?= htmlspecialchars($model['preview_path']) ?>"
                            data-file-type="<?= htmlspecialchars($model['preview_type']) ?>"
                            <?php endif; ?>>
                            <?php if ($model['part_count'] > 0): ?>
                            <span class="part-count-badge"><?= $model['part_count'] ?> <?= $model['part_count'] === 1 ? 'part' : 'parts' ?></span>
                            <?php endif; ?>
                            <span class="file-type-badge">.<?= htmlspecialchars($model['preview_type'] ?? $model['file_type'] ?? 'stl') ?></span>
                            <?php if (!empty($model['print_types'])): ?>
                            <div class="print-type-indicators">
                                <?php if (in_array('fdm', $model['print_types'])): ?>
                                <span class="print-type-badge print-type-fdm">FDM</span>
                                <?php endif; ?>
                                <?php if (in_array('sla', $model['print_types'])): ?>
                                <span class="print-type-badge print-type-sla">SLA</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="model-info">
                            <h3 class="model-title"><?= htmlspecialchars($model['name']) ?></h3>
                            <p class="model-creator"><?= $model['creator'] ? 'by ' . htmlspecialchars($model['creator']) : '' ?></p>
                        </div>
                    </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <?php if (!empty($popularTags)): ?>
        <section class="models-section">
            <div class="section-header">
                <h2>Popular Tags</h2>
                <a href="tags.php" class="btn btn-secondary btn-small">View All</a>
            </div>
            <div class="model-tags" style="padding: 0 1rem;">
                <?php foreach ($popularTags as $tag): ?>
                <a href="browse.php?tag=<?= $tag['id'] ?>" class="model-tag" style="--tag-color: <?= htmlspecialchars($tag['color']) ?>; text-decoration: none; font-size: 0.875rem; padding: 0.5rem 1rem;">
                    <?= htmlspecialchars($tag['name']) ?>
                    <span style="opacity: 0.8; margin-left: 0.25rem;">(<?= $tag['model_count'] ?>)</span>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="categories-section">
            <div class="section-header">
                <h2>Categories</h2>
                <a href="categories.php" class="btn btn-secondary btn-small">View All</a>
            </div>
            <div class="categories-grid">
                <?php foreach ($categories as $category): ?>
                <a href="browse.php?category=<?= $category['id'] ?>" class="category-card">
                    <span class="category-name"><?= htmlspecialchars($category['name']) ?></span>
                    <span class="category-count"><?= $category['model_count'] ?> models</span>
                </a>
                <?php endforeach; ?>
            </div>
        </section>

<?php require_once 'includes/footer.php'; ?>
