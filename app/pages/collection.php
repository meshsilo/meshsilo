<?php
require_once 'includes/config.php';
require_once 'includes/features.php';
require_once 'includes/dedup.php';

// Require feature to be enabled
requireFeature('collections');

$db = getDB();

// Get collection name from URL
$collectionName = isset($_GET['name']) ? trim($_GET['name']) : '';

if (empty($collectionName)) {
    header('Location: ' . route('collections'));
    exit;
}

// Get collection info from collections table (if exists)
$stmt = $db->prepare('SELECT * FROM collections WHERE name = :name');
$stmt->bindValue(':name', $collectionName, PDO::PARAM_STR);
$result = $stmt->execute();
$collectionInfo = $result->fetchArray(PDO::FETCH_ASSOC);

$pageTitle = $collectionName . ' - Collections';
$activePage = 'collections';

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, (int)getSetting('models_per_page', 20));
$offset = ($page - 1) * $perPage;
$paginationBaseUrl = route('collection.show', ['name' => $collectionName]);

// Total count for this collection (parent/standalone models only)
$countStmt = $db->prepare('
    SELECT COUNT(*) AS c
    FROM models
    WHERE collection = :collection
      AND parent_id IS NULL
');
$countStmt->bindValue(':collection', $collectionName, PDO::PARAM_STR);
$totalModels = (int)($countStmt->execute()->fetchArray(PDO::FETCH_ASSOC)['c'] ?? 0);
$totalPages = (int)ceil($totalModels / $perPage);

// Get this page of models (only parent/standalone models, not parts)
$stmt = $db->prepare('
    SELECT *
    FROM models
    WHERE collection = :collection
      AND parent_id IS NULL
    ORDER BY created_at DESC
    LIMIT :limit OFFSET :offset
');
$stmt->bindValue(':collection', $collectionName, PDO::PARAM_STR);
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

// If collection doesn't exist in either table or models, redirect
if ($totalModels === 0 && !$collectionInfo) {
    header('Location: ' . route('collections'));
    exit;
}

// Per-page meta description and OG image
if (!empty($collectionInfo['description'])) {
    $metaDescription = mb_substr($collectionInfo['description'], 0, 160);
} else {
    $metaDescription = $collectionName . ' collection — ' . $totalModels . ' 3D models on ' . SITE_NAME;
    $metaDescription = mb_substr($metaDescription, 0, 160);
}
// Use first model's thumbnail as OG image if available
foreach ($models as $m) {
    if (!empty($m['thumbnail_path'])) {
        $ogImage = '/assets/' . $m['thumbnail_path'];
        break;
    }
}

$needsViewer = true;
require_once 'includes/header.php';
?>

        <div class="page-container-wide">
            <div class="page-header">
                <div class="breadcrumb">
                    <a href="<?= route('collections') ?>">Collections</a> &raquo; <?= htmlspecialchars($collectionName) ?>
                </div>
                <h1><?= htmlspecialchars($collectionName) ?></h1>
                <?php if (!empty($collectionInfo['description'])): ?>
                <p><?= htmlspecialchars($collectionInfo['description']) ?></p>
                <?php endif; ?>
                <p class="text-muted"><?= $totalModels ?> model<?= $totalModels !== 1 ? 's' : '' ?> in this collection</p>
            </div>

            <div class="models-grid">
                <?php foreach ($models as $model): ?>
                <?php $cardOptions = []; include 'includes/partials/model-card.php'; ?>
                <?php endforeach; ?>
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
