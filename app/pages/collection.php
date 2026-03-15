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

// Get models in this collection (only parent/standalone models, not parts)
$stmt = $db->prepare('
    SELECT *
    FROM models
    WHERE collection = :collection
      AND parent_id IS NULL
    ORDER BY created_at DESC
');
$stmt->bindValue(':collection', $collectionName, PDO::PARAM_STR);
$result = $stmt->execute();

$models = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    // For multi-part models, get the first part for preview
    if ($row['part_count'] > 0) {
        $partStmt = $db->prepare('SELECT id, file_type FROM models WHERE parent_id = :parent_id ORDER BY original_path ASC LIMIT 1');
        $partStmt->bindValue(':parent_id', $row['id'], PDO::PARAM_INT);
        $partResult = $partStmt->execute();
        $firstPart = $partResult->fetchArray(PDO::FETCH_ASSOC);
        if ($firstPart) {
            $row['preview_path'] = '/preview?id=' . $firstPart['id'];
            $row['preview_type'] = $firstPart['file_type'];
        }

    } else {
        $row['preview_path'] = '/preview?id=' . $row['id'];
        $row['preview_type'] = $row['file_type'];
    }
    $models[] = $row;
}

// If collection doesn't exist in either table or models, redirect
if (empty($models) && !$collectionInfo) {
    header('Location: ' . route('collections'));
    exit;
}

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
                <p class="text-muted"><?= count($models) ?> model<?= count($models) !== 1 ? 's' : '' ?> in this collection</p>
            </div>

            <div class="models-grid">
                <?php foreach ($models as $model): ?>
                <article class="model-card" data-model-id="<?= $model['id'] ?>" onclick="window.location='<?= route('model.show', ['id' => $model['id']]) ?>'" tabindex="0" role="link" aria-label="<?= htmlspecialchars($model['name']) ?>" onkeydown="if(event.key==='Enter')this.click()">
                    <div class="model-thumbnail"
                        <?php if (empty($model['thumbnail_path']) && !empty($model['preview_path'])): ?>
                        data-model-url="<?= htmlspecialchars($model['preview_path']) ?>"
                        data-file-type="<?= htmlspecialchars($model['preview_type']) ?>"
                        <?php endif; ?>>
                        <?php if (!empty($model['thumbnail_path'])): ?>
                        <img src="/assets/<?= htmlspecialchars($model['thumbnail_path']) ?>" alt="<?= htmlspecialchars($model['name']) ?>" class="model-thumbnail-image" loading="lazy" decoding="async">
                        <?php endif; ?>
                        <?php if ($model['part_count'] > 0): ?>
                        <span class="part-count-badge"><?= $model['part_count'] ?> <?= $model['part_count'] === 1 ? 'part' : 'parts' ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="model-info">
                        <h3 class="model-title"><?= htmlspecialchars($model['name']) ?></h3>
                        <p class="model-creator"><?= $model['creator'] ? 'by ' . htmlspecialchars($model['creator']) : '' ?></p>
                        <?php if (!empty($model['created_at'])): ?>
                        <time class="model-date" datetime="<?= htmlspecialchars(date('c', strtotime($model['created_at']))) ?>" data-timestamp="<?= htmlspecialchars($model['created_at']) ?>"><?= date('M j, Y', strtotime($model['created_at'])) ?></time>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </div>

<?php require_once 'includes/footer.php'; ?>
