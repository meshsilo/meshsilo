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
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $models[] = $row;
}

// Resolve viewer previews (a multi-part model previews its first part); the
// helper batches every multi-part lookup into one query.
attachPreviewData($models);

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

            <?php
            $paginationUrl = fn (int $p): string => htmlspecialchars($paginationBaseUrl) . '?page=' . $p;
            include __DIR__ . '/../../includes/partials/pagination.php';
            ?>
        </div>

<?php require_once 'includes/footer.php'; ?>
