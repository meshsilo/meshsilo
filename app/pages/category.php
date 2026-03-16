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

// Get models in this category (only parent/standalone models, not parts)
$stmt = $db->prepare('
    SELECT m.*
    FROM models m
    INNER JOIN model_categories mc ON m.id = mc.model_id
    WHERE mc.category_id = :category_id
      AND m.parent_id IS NULL
    ORDER BY m.created_at DESC
');
$stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
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

// Per-page meta description
$metaDescription = mb_substr($category['name'] . ' — ' . count($models) . ' 3D models in this category on ' . SITE_NAME, 0, 160);

$needsViewer = true;
require_once 'includes/header.php';
?>

        <div class="page-container-wide">
            <div class="page-header">
                <div class="breadcrumb">
                    <a href="<?= route('categories') ?>">Categories</a> &raquo; <?= htmlspecialchars($category['name']) ?>
                </div>
                <h1><?= htmlspecialchars($category['name']) ?></h1>
                <p><?= count($models) ?> model<?= count($models) !== 1 ? 's' : '' ?> in this category</p>
            </div>

            <div class="models-grid">
                <?php if (empty($models)): ?>
                    <p class="text-muted">No models in this category yet.</p>
                <?php else: ?>
                    <?php foreach ($models as $model): ?>
                    <article class="model-card" data-model-id="<?= $model['id'] ?>" onclick="window.location='<?= route('model.show', ['id' => $model['id']]) ?>'" tabindex="0" role="link" aria-label="<?= htmlspecialchars($model['name']) ?>" onkeydown="if(event.key==='Enter')this.click()">
                        <div class="model-thumbnail"
                            <?php if (empty($model['thumbnail_path']) && !empty($model['preview_path'])): ?>
                            data-model-url="<?= htmlspecialchars($model['preview_path']) ?>"
                            data-file-type="<?= htmlspecialchars($model['preview_type']) ?>"
                            <?php endif; ?>>
                            <?php if (!empty($model['thumbnail_path'])): ?>
                            <?php $thumbSrcset = function_exists('image_srcset') ? image_srcset('storage/assets/' . $model['thumbnail_path'], [280, 560]) : ''; ?>
                            <img src="/assets/<?= htmlspecialchars($model['thumbnail_path']) ?>" alt="<?= htmlspecialchars($model['name']) ?>" class="model-thumbnail-image" loading="lazy" decoding="async"<?= $thumbSrcset ? ' srcset="' . htmlspecialchars($thumbSrcset) . '" sizes="(min-width: 280px) 280px, 100vw"' : '' ?>>
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
                <?php endif; ?>
            </div>
        </div>

<?php require_once 'includes/footer.php'; ?>
