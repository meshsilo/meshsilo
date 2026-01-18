<?php
require_once 'includes/config.php';
$pageTitle = 'Home';
$activePage = 'browse';

$db = getDB();

// Get recent models (only parent/standalone models, not parts)
$result = $db->query('SELECT * FROM models WHERE parent_id IS NULL ORDER BY created_at DESC LIMIT 8');
$models = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    // For multi-part models, get the first part for preview
    if ($row['part_count'] > 0) {
        $partStmt = $db->prepare('SELECT file_path, file_type FROM models WHERE parent_id = :parent_id ORDER BY original_path ASC LIMIT 1');
        $partStmt->bindValue(':parent_id', $row['id'], SQLITE3_INTEGER);
        $partResult = $partStmt->execute();
        $firstPart = $partResult->fetchArray(SQLITE3_ASSOC);
        if ($firstPart) {
            $row['preview_path'] = $firstPart['file_path'];
            $row['preview_type'] = $firstPart['file_type'];
        }
    } else {
        $row['preview_path'] = $row['file_path'];
        $row['preview_type'] = $row['file_type'];
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
if (isset($_GET['uploaded'])) {
    $count = (int)$_GET['uploaded'];
    if ($count === 1) {
        $message = 'Model uploaded successfully!';
    } else {
        $message = $count . ' models uploaded successfully!';
    }
}

require_once 'includes/header.php';
?>

        <?php if ($message): ?>
        <div class="alert alert-success" style="max-width: 1400px; margin: 1rem auto;"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <section class="hero">
            <div class="hero-content">
                <h1>Your 3D Model Library</h1>
                <p>Store, organize, and share your 3D print files in one place.</p>
            </div>
        </section>

        <section class="models-section">
            <div class="section-header">
                <h2>Recent Models</h2>
                <?php if (count($models) > 4): ?>
                <a href="browse.php" class="view-all">View All</a>
                <?php endif; ?>
            </div>
            <div class="models-grid">
                <?php if (empty($models)): ?>
                    <p class="text-muted">No models yet. <a href="upload.php">Upload your first model!</a></p>
                <?php else: ?>
                    <?php foreach ($models as $model): ?>
                    <article class="model-card" onclick="window.location='model.php?id=<?= $model['id'] ?>'">
                        <div class="model-thumbnail"
                            <?php if (!empty($model['preview_path'])): ?>
                            data-model-url="<?= htmlspecialchars($model['preview_path']) ?>"
                            data-file-type="<?= htmlspecialchars($model['preview_type']) ?>"
                            <?php endif; ?>>
                            <?php if ($model['part_count'] > 0): ?>
                            <span class="part-count-badge"><?= $model['part_count'] ?> parts</span>
                            <?php endif; ?>
                            <span class="file-type-badge">.<?= htmlspecialchars($model['file_type'] ?? 'zip') ?></span>
                        </div>
                        <div class="model-info">
                            <h3 class="model-title"><?= htmlspecialchars($model['name']) ?></h3>
                            <p class="model-author"><?= $model['author'] ? 'by ' . htmlspecialchars($model['author']) : '' ?></p>
                        </div>
                    </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="categories-section">
            <div class="section-header">
                <h2>Categories</h2>
            </div>
            <div class="categories-grid">
                <?php foreach ($categories as $category): ?>
                <a href="category.php?id=<?= $category['id'] ?>" class="category-card">
                    <span class="category-name"><?= htmlspecialchars($category['name']) ?></span>
                    <span class="category-count"><?= $category['model_count'] ?> models</span>
                </a>
                <?php endforeach; ?>
            </div>
        </section>

<?php require_once 'includes/footer.php'; ?>
