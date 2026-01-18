<?php
require_once 'includes/config.php';

$db = getDB();

// Get model ID from URL
$modelId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$modelId) {
    header('Location: index.php');
    exit;
}

// Get model details
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
$stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
$result = $stmt->execute();
$model = $result->fetchArray(SQLITE3_ASSOC);

if (!$model) {
    header('Location: index.php');
    exit;
}

// If this is a child model, redirect to its parent
if ($model['parent_id']) {
    header('Location: model.php?id=' . $model['parent_id']);
    exit;
}

$pageTitle = $model['name'];
$activePage = 'browse';

// Get categories for this model
$stmt = $db->prepare('
    SELECT c.* FROM categories c
    JOIN model_categories mc ON c.id = mc.category_id
    WHERE mc.model_id = :model_id
    ORDER BY c.name
');
$stmt->bindValue(':model_id', $modelId, SQLITE3_INTEGER);
$result = $stmt->execute();
$categories = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $categories[] = $row;
}

// Get parts if this is a multi-part model
$parts = [];
$previewPath = null;
$previewType = null;

if ($model['part_count'] > 0) {
    $stmt = $db->prepare('
        SELECT * FROM models
        WHERE parent_id = :parent_id
        ORDER BY original_path ASC
    ');
    $stmt->bindValue(':parent_id', $modelId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $parts[] = $row;
    }
    // Use first part for preview
    if (!empty($parts)) {
        $previewPath = $parts[0]['file_path'];
        $previewType = $parts[0]['file_type'];
    }
} else {
    // Single model
    $previewPath = $model['file_path'];
    $previewType = $model['file_type'];
}

// Helper function to format bytes
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Group parts by directory
function groupPartsByDirectory($parts) {
    $grouped = [];
    foreach ($parts as $part) {
        $path = $part['original_path'] ?? $part['name'];
        $dir = dirname($path);
        if ($dir === '.') {
            $dir = 'Root';
        }
        if (!isset($grouped[$dir])) {
            $grouped[$dir] = [];
        }
        $grouped[$dir][] = $part;
    }
    return $grouped;
}

$groupedParts = groupPartsByDirectory($parts);

// Check for session messages
$message = '';
$messageType = 'success';
if (isset($_SESSION['success'])) {
    $message = $_SESSION['success'];
    $messageType = 'success';
    unset($_SESSION['success']);
} elseif (isset($_SESSION['error'])) {
    $message = $_SESSION['error'];
    $messageType = 'error';
    unset($_SESSION['error']);
}

require_once 'includes/header.php';
?>

        <div class="page-container">
            <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>" style="margin-bottom: 1.5rem;"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="model-detail">
                <div class="model-detail-header">
                    <div class="model-detail-thumbnail <?= ($previewPath && in_array($previewType, ['stl', '3mf'])) ? 'has-viewer' : '' ?>"
                        <?php if ($previewPath && in_array($previewType, ['stl', '3mf'])): ?>
                        data-model-url="<?= htmlspecialchars($previewPath) ?>"
                        data-file-type="<?= htmlspecialchars($previewType) ?>"
                        <?php endif; ?>>
                        <?php if ($model['part_count'] > 0): ?>
                        <span class="part-count-badge"><?= $model['part_count'] ?> parts</span>
                        <?php endif; ?>
                        <span class="file-type-badge file-type-badge-large">.<?= htmlspecialchars($model['file_type'] ?? 'zip') ?></span>
                    </div>
                    <div class="model-detail-info">
                        <h1><?= htmlspecialchars($model['name']) ?></h1>

                        <?php if ($model['creator']): ?>
                        <p class="model-creator">by <?= htmlspecialchars($model['creator']) ?></p>
                        <?php endif; ?>

                        <div class="model-meta">
                            <span class="meta-item">
                                <strong>Size:</strong> <?= formatBytes($model['file_size'] ?? 0) ?>
                            </span>
                            <?php if ($model['collection']): ?>
                            <span class="meta-item">
                                <strong>Collection:</strong> <?= htmlspecialchars($model['collection']) ?>
                            </span>
                            <?php endif; ?>
                            <span class="meta-item">
                                <strong>Added:</strong> <?= date('M j, Y', strtotime($model['created_at'])) ?>
                            </span>
                        </div>

                        <?php if (!empty($categories)): ?>
                        <div class="model-categories">
                            <?php foreach ($categories as $cat): ?>
                            <a href="category.php?id=<?= $cat['id'] ?>" class="category-tag"><?= htmlspecialchars($cat['name']) ?></a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($model['source_url']): ?>
                        <p class="model-source">
                            <a href="<?= htmlspecialchars($model['source_url']) ?>" target="_blank" rel="noopener">View Original Source</a>
                        </p>
                        <?php endif; ?>

                        <?php if (canDelete()): ?>
                        <div class="model-actions">
                            <a href="delete.php?id=<?= $model['id'] ?>" class="btn btn-danger btn-small">Delete Model</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($model['description']): ?>
                <div class="model-description">
                    <h2>Description</h2>
                    <p><?= nl2br(htmlspecialchars($model['description'])) ?></p>
                </div>
                <?php endif; ?>

                <?php if (!empty($parts)): ?>
                <div class="model-parts">
                    <h2>Parts (<?= count($parts) ?>)</h2>

                    <?php foreach ($groupedParts as $dir => $dirParts): ?>
                    <div class="parts-group">
                        <?php if ($dir !== 'Root' && count($groupedParts) > 1): ?>
                        <h3 class="parts-group-header"><?= htmlspecialchars($dir) ?></h3>
                        <?php endif; ?>
                        <div class="parts-list">
                            <?php foreach ($dirParts as $part): ?>
                            <div class="part-item">
                                <div class="part-info">
                                    <span class="file-type-badge">.<?= htmlspecialchars($part['file_type']) ?></span>
                                    <span class="part-name"><?= htmlspecialchars($part['name']) ?></span>
                                    <?php if ($part['print_type']): ?>
                                    <span class="print-type-badge print-type-<?= htmlspecialchars($part['print_type']) ?>"><?= strtoupper($part['print_type']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="part-actions">
                                    <?php if (canEdit()): ?>
                                    <select class="print-type-select" data-part-id="<?= $part['id'] ?>" title="Print type">
                                        <option value="" <?= !$part['print_type'] ? 'selected' : '' ?>>--</option>
                                        <option value="fdm" <?= $part['print_type'] === 'fdm' ? 'selected' : '' ?>>FDM</option>
                                        <option value="sla" <?= $part['print_type'] === 'sla' ? 'selected' : '' ?>>SLA</option>
                                    </select>
                                    <?php endif; ?>
                                    <span class="part-size"><?= formatBytes($part['file_size'] ?? 0) ?></span>
                                    <a href="<?= htmlspecialchars($part['file_path']) ?>" class="btn btn-small btn-primary" download>Download</a>
                                    <?php if (canDelete()): ?>
                                    <a href="delete.php?id=<?= $model['id'] ?>&part_id=<?= $part['id'] ?>" class="btn btn-small btn-danger">Delete</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="parts-actions">
                        <a href="download-all.php?id=<?= $model['id'] ?>" class="btn btn-primary">Download All Parts</a>
                    </div>
                </div>
                <?php else: ?>
                <div class="model-download">
                    <a href="<?= htmlspecialchars($model['file_path']) ?>" class="btn btn-primary btn-large" download>Download Model</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <script>
        // Handle print type selection changes
        document.querySelectorAll('.print-type-select').forEach(select => {
            select.addEventListener('change', function() {
                const partId = this.dataset.partId;
                const printType = this.value;
                const partItem = this.closest('.part-item');

                // Create form data
                const formData = new FormData();
                formData.append('part_id', partId);
                formData.append('print_type', printType);

                // Send AJAX request
                fetch('update-part.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update the badge
                        let badge = partItem.querySelector('.print-type-badge');
                        if (printType) {
                            if (!badge) {
                                badge = document.createElement('span');
                                badge.className = 'print-type-badge';
                                partItem.querySelector('.part-name').after(badge);
                            }
                            badge.className = 'print-type-badge print-type-' + printType;
                            badge.textContent = printType.toUpperCase();
                        } else if (badge) {
                            badge.remove();
                        }
                    } else {
                        alert('Failed to update: ' + (data.error || 'Unknown error'));
                        // Reset select to previous value
                        location.reload();
                    }
                })
                .catch(err => {
                    console.error('Error updating print type:', err);
                    alert('Failed to update print type');
                });
            });
        });
        </script>

<?php require_once 'includes/footer.php'; ?>
