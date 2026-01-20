<?php
require_once 'includes/config.php';
require_once 'includes/dedup.php';

// Check if collections are enabled
if (getSetting('enable_collections', '1') !== '1') {
    header('Location: index.php');
    exit;
}

$db = getDB();

// Get collection name from URL
$collectionName = isset($_GET['name']) ? trim($_GET['name']) : '';

if (empty($collectionName)) {
    header('Location: collections.php');
    exit;
}

// Get collection info from collections table (if exists)
$stmt = $db->prepare('SELECT * FROM collections WHERE name = :name');
$stmt->bindValue(':name', $collectionName, SQLITE3_TEXT);
$result = $stmt->execute();
$collectionInfo = $result->fetchArray(SQLITE3_ASSOC);

$pageTitle = $collectionName . ' - Collections';
$activePage = 'collections';

// Get models in this collection (only parent/standalone models, not parts)
$stmt = $db->prepare('
    SELECT *
    FROM models
    WHERE collection = :collection
      AND parent_id IS NULL
    ORDER BY created_at DESC
');
$stmt->bindValue(':collection', $collectionName, SQLITE3_TEXT);
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

// If collection doesn't exist in either table or models, redirect
if (empty($models) && !$collectionInfo) {
    header('Location: collections.php');
    exit;
}

require_once 'includes/header.php';
?>

        <div class="page-container-wide">
            <div class="page-header">
                <div class="breadcrumb">
                    <a href="collections.php">Collections</a> &raquo; <?= htmlspecialchars($collectionName) ?>
                </div>
                <h1><?= htmlspecialchars($collectionName) ?></h1>
                <?php if (!empty($collectionInfo['description'])): ?>
                <p><?= htmlspecialchars($collectionInfo['description']) ?></p>
                <?php endif; ?>
                <p class="text-muted"><?= count($models) ?> model<?= count($models) !== 1 ? 's' : '' ?> in this collection</p>
            </div>

            <div class="models-grid">
                <?php foreach ($models as $model): ?>
                <article class="model-card" onclick="window.location='model.php?id=<?= $model['id'] ?>'">
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
            </div>
        </div>

<?php require_once 'includes/footer.php'; ?>
