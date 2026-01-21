<?php
require_once 'includes/config.php';
require_once 'includes/dedup.php';
require_once 'includes/slicers.php';
require_once 'includes/dimensions.php';

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

// Record this view for recently viewed
recordModelView($modelId);

$pageTitle = $model['name'];
$activePage = 'browse';

// Get tags for this model
$modelTags = getModelTags($modelId);

// Check if favorited
$isFavorited = isModelFavorited($modelId);
$favoriteCount = getModelFavoriteCount($modelId);

// Check if in print queue
$inPrintQueue = false;
if (isLoggedIn()) {
    $user = getCurrentUser();
    $inPrintQueue = isInPrintQueue($user['id'], $modelId);
}

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

// Get model dimensions
$dimensions = getModelDimensions($modelId);

// Get related models
$relatedModels = getRelatedModels($modelId);

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
        // Add cache buster to prevent stale model files after conversion
        // Use getRealFilePath to handle deduplicated files
        $previewPath = getRealFilePath($parts[0]) . '?v=' . ($parts[0]['file_size'] ?? time());
        $previewType = $parts[0]['file_type'];
    }
} else {
    // Single model - add cache buster
    $previewPath = getRealFilePath($model) . '?v=' . ($model['file_size'] ?? time());
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
                        <span class="part-count-badge"><?= $model['part_count'] ?> <?= $model['part_count'] === 1 ? 'part' : 'parts' ?></span>
                        <?php endif; ?>
                        <span class="file-type-badge file-type-badge-large">.<?= htmlspecialchars($previewType ?? $model['file_type'] ?? 'stl') ?></span>
                    </div>
                    <div class="model-detail-info">
                        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;">
                            <h1><?= htmlspecialchars($model['name']) ?></h1>
                            <?php if (isLoggedIn()): ?>
                            <div style="display: flex; gap: 0.5rem;">
                                <button type="button" class="queue-btn <?= $inPrintQueue ? 'in-queue' : '' ?>" onclick="togglePrintQueue(<?= $model['id'] ?>, this)" title="<?= $inPrintQueue ? 'Remove from print queue' : 'Add to print queue' ?>">
                                    &#128424;
                                </button>
                                <button type="button" class="favorite-btn <?= $isFavorited ? 'favorited' : '' ?>" onclick="toggleFavorite(<?= $model['id'] ?>, this)" title="<?= $isFavorited ? 'Remove from favorites' : 'Add to favorites' ?>">
                                    <?= $isFavorited ? '&#9829;' : '&#9825;' ?>
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($model['is_archived']): ?>
                        <span class="archived-badge" style="margin-bottom: 0.5rem;">Archived</span>
                        <?php endif; ?>

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
                            <?php if (($model['download_count'] ?? 0) > 0): ?>
                            <span class="meta-item download-count">
                                <strong>Downloads:</strong> <?= number_format($model['download_count']) ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($dimensions): ?>
                            <span class="meta-item dimensions">
                                <strong>Dimensions:</strong> <?= htmlspecialchars(formatDimensions($dimensions['dim_x'], $dimensions['dim_y'], $dimensions['dim_z'], $dimensions['dim_unit'])) ?>
                            </span>
                            <?php elseif (canEdit()): ?>
                            <span class="meta-item">
                                <button type="button" class="btn btn-small" onclick="calculateDimensions(<?= $model['id'] ?>)" id="calc-dim-btn">Calculate Dimensions</button>
                            </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($model['license']): ?>
                        <div style="margin-top: 0.5rem;">
                            <span class="license-badge"><?= htmlspecialchars(getLicenseName($model['license'])) ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($categories)): ?>
                        <div class="model-categories">
                            <?php foreach ($categories as $cat): ?>
                            <a href="browse.php?category=<?= $cat['id'] ?>" class="category-tag"><?= htmlspecialchars($cat['name']) ?></a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($modelTags)): ?>
                        <div class="model-tags">
                            <?php foreach ($modelTags as $tag): ?>
                            <a href="browse.php?tag=<?= $tag['id'] ?>" class="model-tag" style="--tag-color: <?= htmlspecialchars($tag['color']) ?>; text-decoration: none;">
                                <?= htmlspecialchars($tag['name']) ?>
                                <?php if (canEdit()): ?>
                                <button type="button" class="model-tag-remove" onclick="event.preventDefault(); event.stopPropagation(); removeTag(<?= $model['id'] ?>, <?= $tag['id'] ?>, this.parentElement)" title="Remove tag">&times;</button>
                                <?php endif; ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (canEdit()): ?>
                        <div class="tag-input-wrapper" style="position: relative; margin-top: 0.5rem;">
                            <input type="text" id="tag-input" class="form-input" placeholder="Add tag..." style="width: 150px;">
                            <div id="tag-suggestions" class="tag-suggestions" style="display: none;"></div>
                        </div>
                        <?php endif; ?>

                        <?php if ($model['source_url']): ?>
                        <p class="model-source">
                            <a href="<?= htmlspecialchars($model['source_url']) ?>" target="_blank" rel="noopener">View Original Source</a>
                        </p>
                        <?php endif; ?>

                        <?php if (canEdit() || canDelete()): ?>
                        <div class="model-actions" style="margin-top: 1rem;">
                            <?php if (canEdit()): ?>
                            <a href="edit-model.php?id=<?= $model['id'] ?>" class="btn btn-secondary btn-small">Edit Model</a>
                            <?php if ($model['is_archived']): ?>
                            <button type="button" class="btn btn-secondary btn-small" onclick="toggleArchive(<?= $model['id'] ?>, false)">Unarchive</button>
                            <?php else: ?>
                            <button type="button" class="btn btn-secondary btn-small" onclick="toggleArchive(<?= $model['id'] ?>, true)">Archive</button>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if (canDelete()): ?>
                            <a href="actions/delete.php?id=<?= $model['id'] ?>" class="btn btn-danger btn-small">Delete Model</a>
                            <?php endif; ?>
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
                    <div class="parts-header">
                        <h2>Parts (<?= count($parts) ?>)</h2>
                        <?php if (canEdit() || canDelete()): ?>
                        <div class="mass-actions" id="parts-mass-actions" style="display: none;">
                            <span class="mass-selection-count"><span id="selected-count">0</span> selected</span>
                            <?php if (canEdit()): ?>
                            <select id="mass-print-type" class="form-input form-input-small">
                                <option value="">Set Print Type...</option>
                                <option value="fdm">FDM</option>
                                <option value="sla">SLA</option>
                                <option value="">Clear</option>
                            </select>
                            <?php endif; ?>
                            <?php if (canDelete()): ?>
                            <button type="button" class="btn btn-danger btn-small" id="mass-delete-parts">Delete Selected</button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php foreach ($groupedParts as $dir => $dirParts): ?>
                    <div class="parts-group">
                        <?php if ($dir !== 'Root' && count($groupedParts) > 1): ?>
                        <h3 class="parts-group-header"><?= htmlspecialchars($dir) ?></h3>
                        <?php endif; ?>
                        <div class="parts-list">
                            <?php foreach ($dirParts as $part): ?>
                            <div class="part-item" data-part-id="<?= $part['id'] ?>" data-part-path="<?= htmlspecialchars(getRealFilePath($part)) ?>?v=<?= $part['file_size'] ?? time() ?>" data-part-type="<?= htmlspecialchars($part['file_type']) ?>" data-part-name="<?= htmlspecialchars($part['name']) ?>">
                                <?php if (canEdit() || canDelete()): ?>
                                <input type="checkbox" class="part-checkbox" value="<?= $part['id'] ?>">
                                <?php endif; ?>
                                <div class="part-info part-preview-trigger" title="Click to preview">
                                    <span class="file-type-badge">.<?= htmlspecialchars($part['file_type']) ?></span>
                                    <span class="part-name"><?= htmlspecialchars($part['name']) ?></span>
                                    <?php if ($part['print_type']): ?>
                                    <span class="print-type-badge print-type-<?= htmlspecialchars($part['print_type']) ?>"><?= strtoupper($part['print_type']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($part['is_printed']): ?>
                                    <span class="printed-badge">Printed</span>
                                    <?php endif; ?>
                                    <?php if ($part['notes']): ?>
                                    <span class="part-notes"><?= htmlspecialchars($part['notes']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="part-actions">
                                    <?php if (canEdit()): ?>
                                    <label class="printed-checkbox" title="Mark as printed">
                                        <input type="checkbox" class="printed-toggle" data-part-id="<?= $part['id'] ?>" <?= $part['is_printed'] ? 'checked' : '' ?>>
                                        <span>Printed</span>
                                    </label>
                                    <select class="print-type-select" data-part-id="<?= $part['id'] ?>" title="Print type">
                                        <option value="" <?= !$part['print_type'] ? 'selected' : '' ?>>--</option>
                                        <option value="fdm" <?= $part['print_type'] === 'fdm' ? 'selected' : '' ?>>FDM</option>
                                        <option value="sla" <?= $part['print_type'] === 'sla' ? 'selected' : '' ?>>SLA</option>
                                    </select>
                                    <?php if ($part['file_type'] === 'stl'): ?>
                                    <button type="button" class="btn btn-small btn-secondary convert-btn" data-part-id="<?= $part['id'] ?>" title="Convert to 3MF for better compression">Convert</button>
                                    <?php endif; ?>
                                    <?php if ($part['original_size'] && $part['file_type'] === '3mf'): ?>
                                    <span class="conversion-savings" title="Saved by converting to 3MF">-<?= round((1 - $part['file_size'] / $part['original_size']) * 100) ?>%</span>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                    <span class="part-size"><?= formatBytes($part['file_size'] ?? 0) ?></span>
                                    <?php
                                    $partSlicers = getSlicersForFormat($part['file_type']);
                                    if (!empty($partSlicers)):
                                    ?>
                                    <div class="dropdown open-in-dropdown">
                                        <button type="button" class="btn btn-small btn-secondary dropdown-toggle" title="Open in slicer software">
                                            Open in <span class="dropdown-arrow">&#9662;</span>
                                        </button>
                                        <div class="dropdown-menu">
                                            <?php foreach ($partSlicers as $slicerKey => $slicer): ?>
                                            <a href="#" class="dropdown-item slicer-link"
                                               data-slicer="<?= htmlspecialchars($slicerKey) ?>"
                                               data-part-id="<?= $part['id'] ?>"
                                               data-has-protocol="<?= !empty($slicer['protocol']) ? '1' : '0' ?>"
                                               title="<?= htmlspecialchars($slicer['description']) ?>">
                                                <?= htmlspecialchars($slicer['name']) ?>
                                                <?php if (empty($slicer['protocol'])): ?>
                                                <span class="slicer-download-hint">(download)</span>
                                                <?php endif; ?>
                                            </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <a href="actions/download.php?id=<?= $part['id'] ?>" class="btn btn-small btn-primary">Download</a>
                                    <?php if (canDelete()): ?>
                                    <a href="actions/delete.php?id=<?= $model['id'] ?>&part_id=<?= $part['id'] ?>" class="btn btn-small btn-danger">Delete</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="parts-actions">
                        <a href="actions/download-all.php?id=<?= $model['id'] ?>" class="btn btn-primary">Download All Parts</a>
                        <?php if (canUpload()): ?>
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('add-part-file').click()">Add Parts</button>
                        <input type="file" id="add-part-file" accept=".stl,.3mf" multiple hidden onchange="uploadParts(this.files)">
                        <?php endif; ?>
                    </div>
                </div>
                <?php elseif (canUpload()): ?>
                <div class="model-download">
                    <?php
                    $modelSlicers = getSlicersForFormat($model['file_type'] ?? 'stl');
                    if (!empty($modelSlicers)):
                    ?>
                    <div class="dropdown open-in-dropdown open-in-dropdown-large">
                        <button type="button" class="btn btn-secondary btn-large dropdown-toggle" title="Open in slicer software">
                            Open in <span class="dropdown-arrow">&#9662;</span>
                        </button>
                        <div class="dropdown-menu">
                            <?php foreach ($modelSlicers as $slicerKey => $slicer): ?>
                            <a href="#" class="dropdown-item slicer-link"
                               data-slicer="<?= htmlspecialchars($slicerKey) ?>"
                               data-part-id="<?= $model['id'] ?>"
                               data-has-protocol="<?= !empty($slicer['protocol']) ? '1' : '0' ?>"
                               title="<?= htmlspecialchars($slicer['description']) ?>">
                                <?= htmlspecialchars($slicer['name']) ?>
                                <?php if (empty($slicer['protocol'])): ?>
                                <span class="slicer-download-hint">(download)</span>
                                <?php endif; ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <a href="actions/download.php?id=<?= $model['id'] ?>" class="btn btn-primary btn-large">Download Model</a>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('add-part-file').click()">Add Parts</button>
                    <input type="file" id="add-part-file" accept=".stl,.3mf" multiple hidden onchange="uploadParts(this.files)">
                </div>
                <?php else: ?>
                <div class="model-download">
                    <?php
                    $modelSlicers = getSlicersForFormat($model['file_type'] ?? 'stl');
                    if (!empty($modelSlicers)):
                    ?>
                    <div class="dropdown open-in-dropdown open-in-dropdown-large">
                        <button type="button" class="btn btn-secondary btn-large dropdown-toggle" title="Open in slicer software">
                            Open in <span class="dropdown-arrow">&#9662;</span>
                        </button>
                        <div class="dropdown-menu">
                            <?php foreach ($modelSlicers as $slicerKey => $slicer): ?>
                            <a href="#" class="dropdown-item slicer-link"
                               data-slicer="<?= htmlspecialchars($slicerKey) ?>"
                               data-part-id="<?= $model['id'] ?>"
                               data-has-protocol="<?= !empty($slicer['protocol']) ? '1' : '0' ?>"
                               title="<?= htmlspecialchars($slicer['description']) ?>">
                                <?= htmlspecialchars($slicer['name']) ?>
                                <?php if (empty($slicer['protocol'])): ?>
                                <span class="slicer-download-hint">(download)</span>
                                <?php endif; ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <a href="actions/download.php?id=<?= $model['id'] ?>" class="btn btn-primary btn-large">Download Model</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Part Preview Modal -->
        <div id="part-preview-modal" class="modal-overlay" style="display: none;">
            <div class="modal-content modal-large">
                <div class="modal-header">
                    <h3 id="preview-part-name">Part Preview</h3>
                    <button type="button" class="modal-close" onclick="closePartPreview()">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="part-preview-container" style="width: 100%; height: 400px;"></div>
                </div>
            </div>
        </div>

        <script>
        // Part preview modal
        let partPreviewViewer = null;

        function openPartPreview(path, type, name) {
            const modal = document.getElementById('part-preview-modal');
            const container = document.getElementById('part-preview-container');
            const nameEl = document.getElementById('preview-part-name');

            nameEl.textContent = name;
            modal.style.display = 'flex';

            // Clear previous viewer
            if (partPreviewViewer) {
                partPreviewViewer.dispose();
                partPreviewViewer = null;
            }
            container.innerHTML = '';

            // Create new viewer
            partPreviewViewer = new ModelViewer(container, {
                autoRotate: false,
                interactive: true,
                backgroundColor: 0x1e293b
            });

            partPreviewViewer.loadModel(path, type).catch(err => {
                console.error('Failed to load part:', err);
                container.innerHTML = '<p style="text-align: center; padding: 2rem; color: var(--color-text-muted);">Failed to load 3D preview</p>';
            });
        }

        function closePartPreview() {
            const modal = document.getElementById('part-preview-modal');
            modal.style.display = 'none';

            if (partPreviewViewer) {
                partPreviewViewer.dispose();
                partPreviewViewer = null;
            }
        }

        // Close modal on background click
        document.getElementById('part-preview-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closePartPreview();
            }
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePartPreview();
            }
        });

        // Add click handlers to part items
        document.querySelectorAll('.part-preview-trigger').forEach(trigger => {
            trigger.addEventListener('click', function(e) {
                const partItem = this.closest('.part-item');
                const path = partItem.dataset.partPath;
                const type = partItem.dataset.partType;
                const name = partItem.dataset.partName;

                if (path && type) {
                    openPartPreview(path, type, name);
                }
            });
        });

        // Handle conversion button clicks
        document.querySelectorAll('.convert-btn').forEach(btn => {
            btn.addEventListener('click', async function() {
                const partId = this.dataset.partId;
                const partItem = this.closest('.part-item');
                const originalText = this.textContent;

                // First, estimate the savings
                this.textContent = 'Checking...';
                this.disabled = true;

                try {
                    const estimateResponse = await fetch(`actions/convert-part.php?action=estimate&part_id=${partId}`);
                    const estimate = await estimateResponse.json();

                    if (!estimate.success) {
                        alert('Cannot estimate conversion: ' + (estimate.error || 'Unknown error'));
                        this.textContent = originalText;
                        this.disabled = false;
                        return;
                    }

                    if (!estimate.worth_converting) {
                        alert('Converting this file would not save space. Keeping original STL.');
                        this.textContent = originalText;
                        this.disabled = false;
                        return;
                    }

                    // Format sizes for display
                    const formatBytes = (bytes) => {
                        if (bytes < 1024) return bytes + ' B';
                        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
                    };

                    const confirmed = confirm(
                        `Convert to 3MF?\n\n` +
                        `Current size: ${formatBytes(estimate.original_size)}\n` +
                        `Estimated new size: ${formatBytes(estimate.estimated_size)}\n` +
                        `Estimated savings: ${formatBytes(estimate.estimated_savings)} (${estimate.estimated_savings_percent}%)\n\n` +
                        `This will replace the STL file with a 3MF file.`
                    );

                    if (!confirmed) {
                        this.textContent = originalText;
                        this.disabled = false;
                        return;
                    }

                    // Perform the conversion
                    this.textContent = 'Converting...';

                    const formData = new FormData();
                    formData.append('action', 'convert');
                    formData.append('part_id', partId);

                    const convertResponse = await fetch('actions/convert-part.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await convertResponse.json();

                    if (result.success) {
                        // Reload the page to show updated file info
                        location.reload();
                    } else {
                        alert('Conversion failed: ' + (result.error || 'Unknown error'));
                        this.textContent = originalText;
                        this.disabled = false;
                    }
                } catch (err) {
                    console.error('Conversion error:', err);
                    alert('Failed to convert file');
                    this.textContent = originalText;
                    this.disabled = false;
                }
            });
        });

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
                fetch('actions/update-part.php', {
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

        // Handle printed status toggle
        document.querySelectorAll('.printed-toggle').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const partId = this.dataset.partId;
                const isPrinted = this.checked ? '1' : '0';
                const partItem = this.closest('.part-item');

                fetch('actions/update-part.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'part_id=' + partId + '&is_printed=' + isPrinted
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update the badge
                        let badge = partItem.querySelector('.printed-badge');
                        if (this.checked) {
                            if (!badge) {
                                badge = document.createElement('span');
                                badge.className = 'printed-badge';
                                badge.textContent = 'Printed';
                                const printTypeBadge = partItem.querySelector('.print-type-badge');
                                if (printTypeBadge) {
                                    printTypeBadge.after(badge);
                                } else {
                                    partItem.querySelector('.part-name').after(badge);
                                }
                            }
                        } else if (badge) {
                            badge.remove();
                        }
                    } else {
                        alert('Failed to update: ' + (data.error || 'Unknown error'));
                        this.checked = !this.checked;
                    }
                })
                .catch(err => {
                    console.error('Error updating printed status:', err);
                    alert('Failed to update printed status');
                    this.checked = !this.checked;
                });
            });
        });

        // Mass action handling
        const partCheckboxes = document.querySelectorAll('.part-checkbox');
        const massActionsBar = document.getElementById('parts-mass-actions');
        const selectedCountEl = document.getElementById('selected-count');
        const massPrintType = document.getElementById('mass-print-type');
        const massDeleteBtn = document.getElementById('mass-delete-parts');

        function updateMassActionsVisibility() {
            const checkedBoxes = document.querySelectorAll('.part-checkbox:checked');
            const count = checkedBoxes.length;

            if (massActionsBar) {
                massActionsBar.style.display = count > 0 ? 'flex' : 'none';
            }
            if (selectedCountEl) {
                selectedCountEl.textContent = count;
            }
        }

        function getSelectedPartIds() {
            return Array.from(document.querySelectorAll('.part-checkbox:checked')).map(cb => cb.value);
        }

        partCheckboxes.forEach(cb => {
            cb.addEventListener('change', updateMassActionsVisibility);
        });

        if (massPrintType) {
            massPrintType.addEventListener('change', async function() {
                const printType = this.value;
                if (printType === '') return;

                const ids = getSelectedPartIds();
                if (ids.length === 0) return;

                const formData = new FormData();
                formData.append('action', 'set_print_type');
                formData.append('print_type', printType === 'Clear' ? '' : printType);
                ids.forEach(id => formData.append('ids[]', id));

                try {
                    const response = await fetch('actions/mass-action.php', { method: 'POST', body: formData });
                    const result = await response.json();

                    if (result.success) {
                        location.reload();
                    } else {
                        alert('Failed: ' + (result.error || 'Unknown error'));
                    }
                } catch (err) {
                    console.error('Mass action error:', err);
                    alert('Failed to perform mass action');
                }

                this.value = '';
            });
        }

        if (massDeleteBtn) {
            massDeleteBtn.addEventListener('click', async function() {
                const ids = getSelectedPartIds();
                if (ids.length === 0) return;

                if (!confirm(`Delete ${ids.length} selected parts? This cannot be undone.`)) {
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'delete_parts');
                ids.forEach(id => formData.append('ids[]', id));

                try {
                    const response = await fetch('actions/mass-action.php', { method: 'POST', body: formData });
                    const result = await response.json();

                    if (result.success) {
                        location.reload();
                    } else {
                        alert('Failed: ' + (result.error || 'Unknown error'));
                    }
                } catch (err) {
                    console.error('Mass delete error:', err);
                    alert('Failed to delete parts');
                }
            });
        }

        // Upload parts function
        async function uploadParts(files) {
            if (!files || files.length === 0) return;

            const modelId = <?= $model['id'] ?>;
            let successCount = 0;
            let errorCount = 0;

            // Show loading indicator
            const addBtn = document.querySelector('button[onclick*="add-part-file"]');
            const originalText = addBtn ? addBtn.textContent : '';
            if (addBtn) {
                addBtn.textContent = 'Uploading...';
                addBtn.disabled = true;
            }

            for (const file of files) {
                const formData = new FormData();
                formData.append('model_id', modelId);
                formData.append('part_file', file);

                try {
                    const response = await fetch('actions/add-part.php', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const result = await response.json();

                    if (result.success) {
                        successCount++;
                    } else {
                        errorCount++;
                        console.error('Upload failed for', file.name, ':', result.error);
                    }
                } catch (err) {
                    errorCount++;
                    console.error('Upload error for', file.name, ':', err);
                }
            }

            // Reset file input
            document.getElementById('add-part-file').value = '';

            // Restore button
            if (addBtn) {
                addBtn.textContent = originalText;
                addBtn.disabled = false;
            }

            // Show result and reload
            if (successCount > 0) {
                if (errorCount > 0) {
                    alert(`Uploaded ${successCount} files. ${errorCount} files failed.`);
                }
                location.reload();
            } else if (errorCount > 0) {
                alert(`Upload failed. ${errorCount} files could not be uploaded.`);
            }
        }

        // Slicer protocol definitions (must match includes/slicers.php)
        const slicerProtocols = {
            'bambustudio': 'bambustudio://open?file={url}',
            'orcaslicer': 'orcaslicer://open?file={url}',
            'prusaslicer': 'prusaslicer://open?file={url}',
            'cura': 'cura://open?file={url}',
            'superslicer': 'superslicer://open?file={url}'
        };

        // Dropdown toggle handling
        document.querySelectorAll('.dropdown-toggle').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const dropdown = this.closest('.dropdown');
                const wasOpen = dropdown.classList.contains('open');

                // Close all other dropdowns
                document.querySelectorAll('.dropdown.open').forEach(d => {
                    d.classList.remove('open');
                });

                // Toggle this dropdown
                if (!wasOpen) {
                    dropdown.classList.add('open');
                }
            });
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.dropdown')) {
                document.querySelectorAll('.dropdown.open').forEach(d => {
                    d.classList.remove('open');
                });
            }
        });

        // Handle slicer link clicks
        document.querySelectorAll('.slicer-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();

                const slicer = this.dataset.slicer;
                const partId = this.dataset.partId;
                const hasProtocol = this.dataset.hasProtocol === '1';

                // Build the download URL
                const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
                const downloadUrl = `${baseUrl}/actions/download.php?id=${partId}`;

                if (hasProtocol && slicerProtocols[slicer]) {
                    // Open using slicer's URL protocol
                    const slicerUrl = slicerProtocols[slicer].replace('{url}', encodeURIComponent(downloadUrl));
                    window.location.href = slicerUrl;
                } else {
                    // No protocol support - just download the file
                    // User will need to open it manually in their slicer
                    window.location.href = downloadUrl;
                }

                // Close the dropdown
                this.closest('.dropdown').classList.remove('open');
            });
        });

        // Favorite toggle
        async function toggleFavorite(modelId, btn) {
            try {
                const response = await fetch('actions/favorite.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'model_id=' + modelId
                });
                const data = await response.json();
                if (data.success) {
                    btn.classList.toggle('favorited', data.favorited);
                    btn.innerHTML = data.favorited ? '&#9829;' : '&#9825;';
                    btn.title = data.favorited ? 'Remove from favorites' : 'Add to favorites';
                }
            } catch (err) {
                console.error('Failed to toggle favorite:', err);
            }
        }

        // Print queue toggle
        async function togglePrintQueue(modelId, btn) {
            try {
                const response = await fetch('actions/print-queue.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=toggle&model_id=' + modelId
                });
                const data = await response.json();
                if (data.success) {
                    btn.classList.toggle('in-queue', data.in_queue);
                    btn.title = data.in_queue ? 'Remove from print queue' : 'Add to print queue';
                }
            } catch (err) {
                console.error('Failed to toggle print queue:', err);
            }
        }

        // Calculate dimensions
        async function calculateDimensions(modelId) {
            const btn = document.getElementById('calc-dim-btn');
            if (btn) {
                btn.textContent = 'Calculating...';
                btn.disabled = true;
            }

            try {
                const formData = new FormData();
                formData.append('model_id', modelId);

                const response = await fetch('actions/calculate-dimensions.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.success && data.formatted) {
                    // Replace button with dimensions text
                    const container = btn.parentElement;
                    container.innerHTML = '<strong>Dimensions:</strong> ' + data.formatted;
                } else {
                    alert('Could not calculate dimensions: ' + (data.error || 'Unknown error'));
                    if (btn) {
                        btn.textContent = 'Calculate Dimensions';
                        btn.disabled = false;
                    }
                }
            } catch (err) {
                console.error('Failed to calculate dimensions:', err);
                alert('Failed to calculate dimensions');
                if (btn) {
                    btn.textContent = 'Calculate Dimensions';
                    btn.disabled = false;
                }
            }
        }

        // Archive toggle
        async function toggleArchive(modelId, archive) {
            try {
                const response = await fetch('actions/update-model.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'model_id=' + modelId + '&is_archived=' + (archive ? '1' : '0')
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to update: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                console.error('Failed to toggle archive:', err);
                alert('Failed to update model');
            }
        }

        // Tag management
        const allTags = <?= json_encode(getAllTags()) ?>;
        const tagInput = document.getElementById('tag-input');
        const tagSuggestions = document.getElementById('tag-suggestions');

        if (tagInput) {
            tagInput.addEventListener('input', function() {
                const value = this.value.toLowerCase().trim();
                if (value.length < 1) {
                    tagSuggestions.style.display = 'none';
                    return;
                }

                const matching = allTags.filter(t => t.name.toLowerCase().includes(value));
                if (matching.length === 0 && value.length > 0) {
                    // Show option to create new tag
                    tagSuggestions.innerHTML = `
                        <div class="tag-suggestion" onclick="addTag('${value.replace(/'/g, "\\'")}')">
                            <span class="tag-color-dot" style="background-color: #6366f1;"></span>
                            <span>Create "${value}"</span>
                        </div>
                    `;
                } else {
                    tagSuggestions.innerHTML = matching.map(t => `
                        <div class="tag-suggestion" onclick="addTagById(${t.id}, '${t.name.replace(/'/g, "\\'")}')">
                            <span class="tag-color-dot" style="background-color: ${t.color};"></span>
                            <span>${t.name}</span>
                        </div>
                    `).join('');
                }
                tagSuggestions.style.display = matching.length > 0 || value.length > 0 ? 'block' : 'none';
            });

            tagInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const value = this.value.trim();
                    if (value) {
                        addTag(value);
                    }
                }
            });

            tagInput.addEventListener('blur', function() {
                setTimeout(() => {
                    tagSuggestions.style.display = 'none';
                }, 200);
            });
        }

        async function addTag(tagName) {
            try {
                const response = await fetch('actions/tag.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=add&model_id=<?= $model['id'] ?>&tag_name=' + encodeURIComponent(tagName)
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to add tag: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                console.error('Failed to add tag:', err);
            }
        }

        async function addTagById(tagId, tagName) {
            await addTag(tagName);
        }

        async function removeTag(modelId, tagId, element) {
            try {
                const response = await fetch('actions/tag.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=remove&model_id=' + modelId + '&tag_id=' + tagId
                });
                const data = await response.json();
                if (data.success) {
                    element.remove();
                } else {
                    alert('Failed to remove tag: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                console.error('Failed to remove tag:', err);
            }
        }
        </script>

<?php require_once 'includes/footer.php'; ?>
