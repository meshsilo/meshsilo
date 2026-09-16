<?php
require_once 'includes/config.php';
require_once 'includes/features.php';
require_once 'includes/dedup.php';

// Require feature to be enabled
requireFeature('categories');

$db = getDB();

// Get category ID from URL
$categoryId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$categoryId) {
    header('Location: ' . route('categories'));
    exit;
}

// Get category info
$stmt = $db->prepare('SELECT * FROM categories WHERE id = :id');
$stmt->bindValue(':id', $categoryId, PDO::PARAM_INT);
$result = $stmt->execute();
$category = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$category) {
    header('Location: ' . route('categories'));
    exit;
}

$pageTitle = $category['name'] . ' - Categories';
$activePage = 'categories';

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, (int)getSetting('models_per_page', 20));
$offset = ($page - 1) * $perPage;
$paginationBaseUrl = route('category.show', ['id' => $categoryId]);

// Total count for this category (parent/standalone models only)
$countStmt = $db->prepare('
    SELECT COUNT(*) AS c
    FROM models m
    INNER JOIN model_categories mc ON m.id = mc.model_id
    WHERE mc.category_id = :category_id
      AND m.parent_id IS NULL
');
$countStmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
$totalModels = (int)($countStmt->execute()->fetchArray(PDO::FETCH_ASSOC)['c'] ?? 0);
$totalPages = (int)ceil($totalModels / $perPage);

// Get this page of models (only parent/standalone models, not parts)
$stmt = $db->prepare('
    SELECT m.*
    FROM models m
    INNER JOIN model_categories mc ON m.id = mc.model_id
    WHERE mc.category_id = :category_id
      AND m.parent_id IS NULL
    ORDER BY m.created_at DESC
    LIMIT :limit OFFSET :offset
');
$stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$result = $stmt->execute();

$models = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $models[] = $row;
}

// Resolve viewer previews (a multi-part model previews its first part); the
// helper batches every multi-part lookup into one query.
attachPreviewData($models);

// Per-page meta description
$metaDescription = mb_substr($category['name'] . ' — ' . $totalModels . ' 3D models in this category on ' . SITE_NAME, 0, 160);

$needsViewer = true;
require_once 'includes/header.php';
?>

        <div class="page-container-wide">
            <div class="page-header">
                <div class="breadcrumb">
                    <a href="<?= route('categories') ?>">Categories</a> &raquo; <?= htmlspecialchars($category['name']) ?>
                </div>
                <h1><?= htmlspecialchars($category['name']) ?></h1>
                <p><?= $totalModels ?> model<?= $totalModels !== 1 ? 's' : '' ?> in this category</p>
            </div>

            <div class="models-grid">
                <?php if (empty($models)): ?>
                    <p class="text-muted">No models in this category yet.</p>
                <?php else: ?>
                    <?php foreach ($models as $model): ?>
                    <?php $cardOptions = []; include 'includes/partials/model-card.php'; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php
            $paginationUrl = fn (int $p): string => htmlspecialchars($paginationBaseUrl) . '?page=' . $p;
            include __DIR__ . '/../../includes/partials/pagination.php';
            ?>
        </div>

<?php require_once 'includes/footer.php'; ?>
