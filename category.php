<?php
require_once 'includes/config.php';
require_once 'includes/dedup.php';

// Check if categories are enabled
if (getSetting('enable_categories', '1') !== '1') {
    header('Location: index.php');
    exit;
}

$db = getDB();

// Get category ID from URL
$categoryId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$categoryId) {
    header('Location: categories.php');
    exit;
}

// Get category info
$stmt = $db->prepare('SELECT * FROM categories WHERE id = :id');
$stmt->bindValue(':id', $categoryId, SQLITE3_INTEGER);
$result = $stmt->execute();
$category = $result->fetchArray(SQLITE3_ASSOC);

if (!$category) {
    header('Location: categories.php');
    exit;
}

$pageTitle = $category['name'] . ' - Categories';
$activePage = 'categories';

// Get models in this category (only parent/standalone models, not parts)
$stmt = $db->prepare('
    SELECT m.*
    FROM models m
    INNER JOIN model_categories mc ON m.id = mc.model_id
    WHERE mc.category_id = :category_id
      AND m.parent_id IS NULL
    ORDER BY m.created_at DESC
');
$stmt->bindValue(':category_id', $categoryId, SQLITE3_INTEGER);
$result = $stmt->execute();

$models = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    // For multi-part models, get the first part for preview
    if ($row['part_count'] > 0) {
        $partStmt = $db->prepare('SELECT file_path, file_type, file_size, dedup_path FROM models WHERE parent_id = :parent_id ORDER BY original_path ASC LIMIT 1');
        $partStmt->bindValue(':parent_id', $row['id'], SQLITE3_INTEGER);
        $partResult = $partStmt->execute();
        $firstPart = $partResult->fetchArray(SQLITE3_ASSOC);
        if ($firstPart) {
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
        $row['preview_path'] = getRealFilePath($row) . '?v=' . ($row['file_size'] ?? time());
        $row['preview_type'] = $row['file_type'];
        $row['print_types'] = $row['print_type'] ? [$row['print_type']] : [];
    }
    $models[] = $row;
}

require_once 'includes/header.php';
?>

        <div class="page-container-wide">
            <div class="page-header">
                <div class="breadcrumb">
                    <a href="categories.php">Categories</a> &raquo; <?= htmlspecialchars($category['name']) ?>
                </div>
                <h1><?= htmlspecialchars($category['name']) ?></h1>
                <p><?= count($models) ?> model<?= count($models) !== 1 ? 's' : '' ?> in this category</p>
            </div>

            <div class="models-grid">
                <?php if (empty($models)): ?>
                    <p class="text-muted">No models in this category yet.</p>
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
        </div>

<?php require_once 'includes/footer.php'; ?>
