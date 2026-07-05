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
$multiPartIds = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    if ($row['part_count'] > 0) {
        $multiPartIds[] = $row['id']; // previews resolved in one batched query below
    } else {
        $row['preview_path'] = '/preview?id=' . $row['id'];
        $row['preview_type'] = $row['file_type'];
    }
    $models[] = $row;
}

// Batch-load the first part of every multi-part model (eliminates the per-model N+1 query)
if (!empty($multiPartIds)) {
    $firstParts = getFirstPartsForModels($multiPartIds);
    foreach ($models as &$m) {
        if ($m['part_count'] > 0 && isset($firstParts[$m['id']])) {
            $m['preview_path'] = '/preview?id=' . $firstParts[$m['id']]['id'];
            $m['preview_type'] = $firstParts[$m['id']]['file_type'];
        }
    }
    unset($m);
}

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

            <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Pagination">
                <?php if ($page > 1): ?>
                <a href="<?= htmlspecialchars($paginationBaseUrl) ?>?page=<?= $page - 1 ?>" class="pagination-btn" aria-label="Previous page">&laquo; Prev</a>
                <?php endif; ?>
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                if ($startPage > 1): ?>
                <a href="<?= htmlspecialchars($paginationBaseUrl) ?>?page=1" class="pagination-btn" aria-label="Page 1">1</a>
                <?php if ($startPage > 2): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                <?php endif; ?>
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <a href="<?= htmlspecialchars($paginationBaseUrl) ?>?page=<?= $i ?>" class="pagination-btn <?= $i === $page ? 'active' : '' ?>" aria-label="Page <?= $i ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
                <?php endfor; ?>
                <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                <a href="<?= htmlspecialchars($paginationBaseUrl) ?>?page=<?= $totalPages ?>" class="pagination-btn" aria-label="Page <?= $totalPages ?>"><?= $totalPages ?></a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                <a href="<?= htmlspecialchars($paginationBaseUrl) ?>?page=<?= $page + 1 ?>" class="pagination-btn" aria-label="Next page">Next &raquo;</a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
        </div>

<?php require_once 'includes/footer.php'; ?>
