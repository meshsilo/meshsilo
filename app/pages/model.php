<?php
require_once 'includes/config.php';
require_once 'includes/features.php';

try {

require_once 'includes/dedup.php';
require_once 'includes/Markdown.php';

$db = getDB();

// Get model ID from URL
$modelId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$modelId) {
    header('Location: ' . route('home'));
    exit;
}

// Get model details
$stmt = $db->prepare('SELECT id, name, filename, file_path, file_size, file_type, description, creator, collection, source_url, parent_id, original_path, part_count, print_type, original_size, file_hash, dedup_path, created_at, updated_at, is_archived, thumbnail_path, dim_x, dim_y, dim_z, dim_unit, user_id, notes, license, download_count, current_version FROM models WHERE id = :id');
$stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
$result = $stmt->execute();
$model = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$model) {
    header('Location: ' . route('home'));
    exit;
}

// If this is a child model, redirect to its parent
if ($model['parent_id']) {
    header('Location: ' . route('model.show', ['id' => $model['parent_id']]));
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

// Get categories for this model
$categories = [];
try {
    $stmt = $db->prepare('
        SELECT c.* FROM categories c
        JOIN model_categories mc ON c.id = mc.category_id
        WHERE mc.model_id = :model_id
        ORDER BY c.name
    ');
    $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $categories[] = $row;
    }
} catch (Exception $e) {
    logError('Failed to load model categories', ['model_id' => $modelId, 'error' => $e->getMessage()]);
}

// Get related models
$relatedModels = getRelatedModels($modelId);

// Get parts if this is a multi-part model
$parts = [];
$previewPath = null;
$previewType = null;

if ($model['part_count'] > 0) {
    $stmt = $db->prepare('
        SELECT id, name, filename, file_path, file_size, file_type, print_type, original_size, file_hash, dedup_path, original_path, sort_order, notes, parent_id
        FROM models
        WHERE parent_id = :parent_id
        ORDER BY sort_order ASC, original_path ASC
    ');
    $stmt->bindValue(':parent_id', $modelId, PDO::PARAM_INT);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $parts[] = $row;
    }
    // Use first part for preview via preview endpoint
    if (!empty($parts)) {
        $previewPath = '/preview?id=' . $parts[0]['id'];
        $previewType = $parts[0]['file_type'];
    }
} else {
    // Single model - use preview endpoint
    $previewPath = '/preview?id=' . $model['id'];
    $previewType = $model['file_type'];
}

// Calculate total model size, conversion savings, and potential savings
$totalModelSize = 0;
$stlTotalSize = 0;
$actualSaved = 0;

if (!empty($parts)) {
    foreach ($parts as $part) {
        $totalModelSize += ($part['file_size'] ?? 0);
        if (($part['file_type'] ?? '') === 'stl') {
            $stlTotalSize += ($part['file_size'] ?? 0);
        }
        // Track actual savings from already-converted parts
        if (!empty($part['original_size']) && $part['file_type'] === '3mf') {
            $actualSaved += ($part['original_size'] - $part['file_size']);
        }
    }
} else {
    $totalModelSize = $model['file_size'] ?? 0;
    if (($model['file_type'] ?? '') === 'stl') {
        $stlTotalSize = $model['file_size'] ?? 0;
    }
    if (!empty($model['original_size']) && $model['file_type'] === '3mf') {
        $actualSaved = $model['original_size'] - $model['file_size'];
    }
}

// Estimate 3MF conversion savings (~65% compression on STL data)
$estimatedSavings = ($stlTotalSize > 0) ? (int)($stlTotalSize * 0.65) : 0;

// formatBytes is defined in includes/helpers.php

// Group parts by directory with relative display names
function groupPartsByDirectory($partsArray) {
    $grouped = [];
    $dirs = [];

    // First pass: collect all directories
    foreach ($partsArray as $part) {
        $path = $part['original_path'] ?? $part['name'];
        // Normalize path separators
        $path = str_replace('\\', '/', $path);
        $dir = dirname($path);
        if ($dir === '.') {
            $dir = 'Root';
        }
        $dirs[$dir] = true;
    }

    // Find common base path (excluding 'Root')
    $realDirs = [];
    foreach (array_keys($dirs) as $d) {
        if ($d !== 'Root') {
            $realDirs[] = $d;
        }
    }

    $basePath = '';
    if (count($realDirs) > 0) {
        $pathSegments = [];
        foreach ($realDirs as $d) {
            $pathSegments[] = explode('/', $d);
        }
        $baseParts = [];
        if (count($pathSegments) > 0) {
            $lengths = array_map('count', $pathSegments);
            $minLen = min($lengths);
            for ($i = 0; $i < $minLen; $i++) {
                $segment = $pathSegments[0][$i];
                $allMatch = true;
                foreach ($pathSegments as $ps) {
                    if ($ps[$i] !== $segment) {
                        $allMatch = false;
                        break;
                    }
                }
                if ($allMatch) {
                    $baseParts[] = $segment;
                } else {
                    break;
                }
            }
        }
        $basePath = implode('/', $baseParts);
    }

    // Second pass: group with relative names
    foreach ($partsArray as $part) {
        $path = $part['original_path'] ?? $part['name'];
        $path = str_replace('\\', '/', $path);
        $dir = dirname($path);
        if ($dir === '.') {
            $dir = 'Root';
            $displayDir = 'Root';
        } else {
            // Strip common base path for display
            $displayDir = $dir;
            if ($basePath && strpos($dir, $basePath) === 0) {
                $displayDir = substr($dir, strlen($basePath));
                $displayDir = ltrim($displayDir, '/');
                if ($displayDir === '') {
                    $displayDir = basename($dir);
                }
            }
        }

        if (!isset($grouped[$dir])) {
            $grouped[$dir] = ['display' => $displayDir, 'parts' => []];
        }
        $grouped[$dir]['parts'][] = $part;
    }

    return $grouped;
}

$groupedParts = groupPartsByDirectory($parts);

// Get version history
$versions = [];
$versionCount = 0;
try {
    $stmt = $db->prepare('
        SELECT mv.*, u.username as created_by_name
        FROM model_versions mv
        LEFT JOIN users u ON mv.created_by = u.id
        WHERE mv.model_id = :model_id
        ORDER BY mv.version_number DESC
    ');
    $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $versions[] = $row;
    }
    $versionCount = count($versions);
} catch (Throwable $e) {
    // model_versions table may not exist yet
}

$canManageVersions = false;
if (isLoggedIn()) {
    $vUser = getCurrentUser();
    $canManageVersions = (!empty($model['user_id']) && $model['user_id'] == $vUser['id']) || !empty($vUser['is_admin']) || canEdit();
}

// Get external links
$modelLinks = [];
try {
    $stmt = $db->prepare('SELECT id, model_id, title, url, link_type, sort_order, created_at FROM model_links WHERE model_id = :model_id ORDER BY sort_order, created_at');
    $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $modelLinks[] = $row;
    }
} catch (Throwable $e) {
    // model_links table may not exist yet
}

// Get model attachments (images, PDFs, text files)
$attachments = ['images' => [], 'documents' => []];
try {
    $stmt = $db->prepare('SELECT id, model_id, filename, original_filename, file_path, file_type, file_size, display_order, created_at FROM model_attachments WHERE model_id = :model_id ORDER BY display_order, created_at');
    $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        if ($row['file_type'] === 'image') {
            $attachments['images'][] = $row;
        } else {
            $attachments['documents'][] = $row;
        }
    }
} catch (Throwable $e) {
    logError('Failed to load attachments', ['model_id' => $modelId, 'error' => $e->getMessage()]);
}

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

// Per-page meta description and OG image
if (!empty($model['description'])) {
    $metaDescription = mb_substr(trim(strip_tags(Markdown::render($model['description']))), 0, 160);
}
if (empty($metaDescription)) {
    $parts_label = $model['part_count'] > 0 ? ' — ' . $model['part_count'] . ' parts' : '';
    $metaDescription = $model['name'] . $parts_label . ' — 3D model on ' . SITE_NAME;
    $metaDescription = mb_substr($metaDescription, 0, 160);
}
if (!empty($model['thumbnail_path'])) {
    $ogImage = '/assets/' . $model['thumbnail_path'];
}
$ogType = 'article';

$needsViewer = true;
$needsModelPageJs = true;
require_once 'includes/header.php';
?>

        <div class="page-container-wide model-page">
            <nav class="model-breadcrumb" aria-label="Breadcrumb">
                <a href="<?= route('home') ?>">Home</a>
                <span class="breadcrumb-sep">/</span>
                <a href="<?= route('browse') ?>">Browse</a>
                <?php if (!empty($categories)): ?>
                <span class="breadcrumb-sep">/</span>
                <a href="<?= route('browse', [], ['category' => $categories[0]['id']]) ?>"><?= htmlspecialchars($categories[0]['name']) ?></a>
                <?php endif; ?>
                <?php if (!empty($model['collection'])): ?>
                <span class="breadcrumb-sep">/</span>
                <a href="<?= route('browse', [], ['collection' => $model['collection']]) ?>"><?= htmlspecialchars($model['collection']) ?></a>
                <?php endif; ?>
                <span class="breadcrumb-sep">/</span>
                <span class="breadcrumb-current"><?= htmlspecialchars($model['name']) ?></span>
            </nav>

            <?php if ($message): ?>
            <div role="<?= $messageType === 'success' ? 'status' : 'alert' ?>" class="alert alert-<?= $messageType ?> mb-4"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="model-detail">
                <div class="model-detail-header">
                    <?php $thumbnailUrl = !empty($model['thumbnail_path']) ? '/assets/' . $model['thumbnail_path'] : null; ?>
                    <div class="model-detail-thumbnail <?= (!$thumbnailUrl && $previewPath && in_array($previewType, ['stl', '3mf', 'gcode'])) ? 'has-viewer' : '' ?>"
                        <?php if (!$thumbnailUrl && $previewPath && in_array($previewType, ['stl', '3mf', 'gcode'])): ?>
                        data-model-url="<?= htmlspecialchars($previewPath) ?>"
                        data-file-type="<?= htmlspecialchars($previewType) ?>"
                        <?php endif; ?>>
                        <?php if ($thumbnailUrl): ?>
                        <img src="<?= htmlspecialchars($thumbnailUrl) ?>" alt="<?= htmlspecialchars($model['name']) ?>" class="model-thumbnail-image" fetchpriority="high">
                        <?php endif; ?>
                        <?php if ($model['part_count'] > 0): ?>
                        <span class="part-count-badge"><?= $model['part_count'] ?> <?= $model['part_count'] === 1 ? 'part' : 'parts' ?></span>
                        <?php endif; ?>

                    </div>
                    <div class="model-detail-info">
                        <div class="flex-between">
                            <h1><?= htmlspecialchars($model['name']) ?></h1>
                            <?php if (isLoggedIn()): ?>
                            <div class="flex-gap-sm">
                                <?php if (class_exists('PluginManager')): ?>
                                <?= PluginManager::applyFilter('model_header_actions', '', $model) ?>
                                <?php endif; ?>
                                <?php if (isFeatureEnabled('favorites')): ?>
                                <button type="button" class="favorite-btn <?= $isFavorited ? 'favorited' : '' ?>" title="<?= $isFavorited ? 'Remove from favorites' : 'Add to favorites' ?>" aria-label="<?= $isFavorited ? 'Remove from favorites' : 'Add to favorites' ?>">
                                    <span aria-hidden="true"><?= $isFavorited ? '&#9829;' : '&#9825;' ?></span>
                                </button>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($model['is_archived'])): ?>
                        <span class="archived-badge mb-2">Archived</span>
                        <?php endif; ?>

                        <?php if (!empty($model['creator'])): ?>
                        <p class="model-creator">by <?= htmlspecialchars($model['creator']) ?></p>
                        <?php endif; ?>

                        <div class="model-meta">
                            <span class="meta-item"<?php if ($estimatedSavings > 0): ?> title="<?= formatBytes($stlTotalSize) ?> in STL files — converting to 3MF could save ~<?= formatBytes($estimatedSavings) ?>"<?php endif; ?>>
                                <strong>Size:</strong> <?= formatBytes($totalModelSize) ?>
                            </span>
                            <?php if ($actualSaved > 0): ?>
                            <span class="meta-item conversion-savings-total" title="<?= formatBytes($actualSaved) ?> saved by converting STL to 3MF (original: <?= formatBytes($totalModelSize + $actualSaved) ?>)">
                                <strong>Saved:</strong> <?= formatBytes($actualSaved) ?> (<?= round($actualSaved / ($totalModelSize + $actualSaved) * 100) ?>%)
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($model['collection'])): ?>
                            <span class="meta-item">
                                <strong>Collection:</strong> <?= htmlspecialchars($model['collection']) ?>
                            </span>
                            <?php endif; ?>
                            <time class="meta-item" datetime="<?= htmlspecialchars(date('c', strtotime($model['created_at']))) ?>" data-timestamp="<?= htmlspecialchars($model['created_at']) ?>">
                                <strong>Added:</strong> <?= date('M j, Y', strtotime($model['created_at'])) ?>
                            </time>
                            <?php if (isFeatureEnabled('download_tracking') && ($model['download_count'] ?? 0) > 0): ?>
                            <span class="meta-item download-count">
                                <strong>Downloads:</strong> <?= number_format($model['download_count']) ?>
                            </span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($model['license'])): ?>
                        <div class="mt-2">
                            <span class="license-badge"><?= htmlspecialchars(getLicenseName($model['license'])) ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($categories)): ?>
                        <div class="model-categories">
                            <?php foreach ($categories as $cat): ?>
                            <a href="<?= route('browse', [], ['category' => $cat['id']]) ?>" class="category-tag"><?= htmlspecialchars($cat['name']) ?></a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (isFeatureEnabled('tags')): ?>
                        <?php if (!empty($modelTags)): ?>
                        <div class="model-tags">
                            <?php foreach ($modelTags as $tag): ?>
                            <a href="<?= route('browse', [], ['tag' => $tag['id']]) ?>" class="model-tag" style="--tag-color: <?= htmlspecialchars($tag['color']) ?>; text-decoration: none;">
                                <?= htmlspecialchars($tag['name']) ?>
                                <?php if (canEdit()): ?>
                                <button type="button" class="model-tag-remove" aria-label="Remove tag" data-tag-id="<?= $tag['id'] ?>" title="Remove tag">&times;</button>
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
                        <?php endif; ?>

                        <?php if (!empty($model['source_url'])): ?>
                        <p class="model-source">
                            <a href="<?= htmlspecialchars($model['source_url']) ?>" target="_blank" rel="noopener noreferrer">View Original Source</a>
                        </p>
                        <?php endif; ?>

                        <?php if (isFeatureEnabled('external_links') && (!empty($modelLinks) || canEdit())): ?>
                        <div class="model-links">
                            <h3>External Links</h3>
                            <div class="model-links-list" id="model-links-list">
                                <?php foreach ($modelLinks as $link): ?>
                                <div class="model-link-item" data-link-id="<?= $link['id'] ?>">
                                    <span class="model-link-type type-<?= htmlspecialchars($link['link_type']) ?>"><?= htmlspecialchars($link['link_type']) ?></span>
                                    <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank" rel="noopener noreferrer" class="model-link-title"><?= htmlspecialchars($link['title']) ?></a>
                                    <?php if (canEdit()): ?>
                                    <button type="button" class="model-link-delete" aria-label="Remove link" title="Remove link">&times;</button>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                                <?php if (empty($modelLinks)): ?>
                                <p class="model-links-empty" id="model-links-empty">No external links yet.</p>
                                <?php endif; ?>
                            </div>
                            <?php if (canEdit()): ?>
                            <button type="button" class="btn btn-small btn-secondary" id="add-link-toggle">Add Link</button>
                            <div class="model-link-add-form" id="add-link-form" style="display:none;">
                                <div class="link-form-row">
                                    <input type="text" id="link-title" class="form-input" placeholder="Link title" required>
                                    <input type="url" id="link-url" class="form-input" placeholder="https://..." required>
                                    <select id="link-type" class="form-input" aria-label="Link type">
                                        <option value="other">Other</option>
                                        <option value="documentation">Documentation</option>
                                        <option value="video">Video</option>
                                        <option value="forum">Forum</option>
                                        <option value="repository">Repository</option>
                                        <option value="source">Source</option>
                                        <option value="store">Store</option>
                                    </select>
                                </div>
                                <div class="link-form-actions">
                                    <button type="button" class="btn btn-small btn-secondary cancel-link-form">Cancel</button>
                                    <button type="button" class="btn btn-small btn-primary submit-link-form">Add</button>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (class_exists('PluginManager')): ?>
                        <?= PluginManager::applyFilter('model_detail_sidebar', '', $model) ?>
                        <?php endif; ?>

                        <div class="model-actions mt-3">
                            <?php if (isLoggedIn() && isFeatureEnabled('share_links')): ?>
                            <button type="button" class="btn btn-secondary btn-small open-share-modal">Share</button>
                            <?php endif; ?>
                            <?php if (isFeatureEnabled('version_history')): ?>
                            <a href="<?= route('model.versions', ['id' => $model['id']]) ?>" class="btn btn-secondary btn-small">Version History<?php if ($versionCount > 0): ?> (<?= $versionCount ?>)<?php endif; ?></a>
                            <?php if ($canManageVersions): ?>
                            <button type="button" class="btn btn-secondary btn-small show-upload-version">Upload New Version</button>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if (canEdit()): ?>
                            <button type="button" class="btn btn-secondary btn-small navigate-edit-model" data-href="<?= route('model.edit', ['id' => $model['id']]) ?>">Edit Model</button>
                            <button type="button" class="btn btn-secondary btn-small show-create-folder">New Folder</button>
                            <?php if (!empty($model['is_archived'])): ?>
                            <button type="button" class="btn btn-secondary btn-small toggle-archive" data-archive-value="false">Unarchive</button>
                            <?php else: ?>
                            <button type="button" class="btn btn-secondary btn-small toggle-archive" data-archive-value="true">Archive</button>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if (canDelete()): ?>
                            <a href="<?= route('actions.delete', [], ['id' => $model['id']]) ?>" class="btn btn-danger btn-small">Delete Model</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (!empty($model['description'])): ?>
                <div class="model-description">
                    <h2>Description</h2>
                    <div class="markdown-content"><?= Markdown::render($model['description']) ?></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($relatedModels)): ?>
                <div class="related-models">
                    <h2>Related Models</h2>
                    <div class="related-models-grid">
                        <?php foreach ($relatedModels as $rm): ?>
                        <a href="<?= route('model.show', ['id' => $rm['related_model_id']]) ?>" class="related-model-card">
                            <h4><?= htmlspecialchars($rm['name']) ?></h4>
                            <time class="related-meta" datetime="<?= htmlspecialchars(date('c', strtotime($rm['created_at']))) ?>" data-timestamp="<?= htmlspecialchars($rm['created_at']) ?>"><?= date('M j, Y', strtotime($rm['created_at'])) ?></time>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (class_exists('PluginManager')):
                    $pluginTabs = PluginManager::applyFilter('model_detail_tabs', [], $model);
                    if (!empty($pluginTabs)): ?>
                    <div class="plugin-tabs">
                    <?php foreach ($pluginTabs as $tab): ?>
                        <div class="model-section">
                            <h2><?= htmlspecialchars($tab['label'] ?? '') ?></h2>
                            <?= $tab['content'] ?? '' ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; endif; ?>

                <?php if (isFeatureEnabled('version_history') && $versionCount > 0): ?>
                <div class="model-version-history" id="upload-version">
                    <div class="parts-header">
                        <h2>Version History</h2>
                        <a href="<?= route('model.versions', ['id' => $model['id']]) ?>" class="btn btn-secondary btn-small">View Full History</a>
                    </div>
                    <div class="version-timeline-compact">
                        <?php foreach (array_slice($versions, 0, 3) as $v): ?>
                        <div class="version-entry <?= ($v['version_number'] == ($model['current_version'] ?? 0)) ? 'version-current' : '' ?>">
                            <div class="version-entry-header">
                                <span class="version-entry-number">v<?= $v['version_number'] ?></span>
                                <?php if ($v['version_number'] == ($model['current_version'] ?? 0)): ?>
                                <span class="version-badge-current">Current</span>
                                <?php endif; ?>
                                <span class="version-entry-meta">
                                    <time datetime="<?= htmlspecialchars(date('c', strtotime($v['created_at']))) ?>" data-timestamp="<?= htmlspecialchars($v['created_at']) ?>"><?= date('M j, Y', strtotime($v['created_at'])) ?></time>
                                    <?php if ($v['created_by_name']): ?>
                                    &middot; <?= htmlspecialchars($v['created_by_name']) ?>
                                    <?php endif; ?>
                                    &middot; <?= formatBytes($v['file_size']) ?>
                                </span>
                            </div>
                            <?php if ($v['changelog']): ?>
                            <div class="version-entry-changelog"><?= htmlspecialchars($v['changelog']) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php if ($versionCount > 3): ?>
                        <div class="version-entry-more">
                            <a href="<?= route('model.versions', ['id' => $model['id']]) ?>"><?= $versionCount - 3 ?> more version<?= ($versionCount - 3) !== 1 ? 's' : '' ?></a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="model-content-layout">
                <div class="model-content-main">
                <?php if (!empty($parts)): ?>
                <div class="model-parts">
                    <div class="parts-header">
                        <?php if (count($groupedParts) > 1): ?>
                        <button type="button" class="collapse-all-toggle" title="Collapse/expand all groups" aria-label="Collapse/expand all groups">&#9660;</button>
                        <?php endif; ?>
                        <?php if (canEdit() || canDelete()): ?>
                        <input type="checkbox" class="select-all-checkbox" id="select-all-parts" title="Select all parts" aria-label="Select all parts">
                        <?php endif; ?>
                        <h2>Parts (<?= count($parts) ?>)</h2>
                        <?php if (canEdit() || canDelete()): ?>
                        <div class="mass-actions" id="parts-mass-actions" style="display: none;">
                            <span class="mass-selection-count"><span id="selected-count">0</span> selected</span>
                            <?php if (canEdit()): ?>
                            <button type="button" class="btn btn-secondary btn-small show-move-folder-modal">Move to Folder</button>
                            <button type="button" class="btn btn-secondary btn-small show-batch-rename-modal">Rename</button>
                            <select class="print-type-select" id="mass-print-type" title="Set print type for selected parts" aria-label="Set print type for selected parts">
                                <option value="">Print Type</option>
                                <option value="fdm">FDM</option>
                                <option value="sla">SLA</option>
                                <option value="clear">Clear</option>
                            </select>
                            <button type="button" class="btn btn-secondary btn-small" id="mass-convert-3mf">Convert to 3MF</button>
                            <?php endif; ?>
                            <?php if (canDelete()): ?>
                            <button type="button" class="btn btn-danger btn-small" id="mass-delete-parts">Delete Selected</button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php $autoCollapse = count($parts) > 50 && count($groupedParts) > 1; ?>
                    <?php foreach ($groupedParts as $dir => $dirData): ?>
                    <?php $displayName = $dirData['display']; $dirParts = $dirData['parts']; ?>
                    <div class="parts-group<?= $autoCollapse ? ' collapsed' : '' ?>" data-folder="<?= htmlspecialchars($dir) ?>">
                        <?php $multiFolder = count($groupedParts) > 1; ?>
                        <?php if ($multiFolder): ?>
                        <h3 class="parts-group-header" tabindex="0" role="button" aria-expanded="<?= $autoCollapse ? 'false' : 'true' ?>">
                            <span class="folder-toggle" aria-hidden="true"><?= $autoCollapse ? '&#9654;' : '&#9660;' ?></span>
                            <?php if (canEdit() || canDelete()): ?>
                            <input type="checkbox" class="folder-checkbox" title="Select all parts in this folder" aria-label="Select all parts in this folder">
                            <?php endif; ?>
                            <?= htmlspecialchars($displayName) ?>
                            <span class="folder-part-count">(<?= count($dirParts) ?>)</span>
                            <?php if (canEdit()): ?>
                            <span class="folder-actions">
                                <select class="print-type-select folder-print-type" aria-label="Set print type for all parts in this folder" data-folder="<?= htmlspecialchars($dir) ?>" title="Set print type for all parts in this folder">
                                    <option value="">--</option>
                                    <option value="fdm">FDM</option>
                                    <option value="sla">SLA</option>
                                </select>
                                <?php if ($dir !== 'Root'): ?>
                                <button type="button" data-folder="<?= htmlspecialchars($dir) ?>" title="Rename folder">Rename</button>
                                <button type="button" data-folder="<?= htmlspecialchars($dir) ?>" title="Delete folder (moves parts to root)">Delete</button>
                                <?php endif; ?>
                            </span>
                            <?php endif; ?>
                        </h3>
                        <?php elseif (canEdit() && count($dirParts) > 1): ?>
                        <div class="parts-group-actions">
                            <span class="text-muted"><?= count($dirParts) ?> parts</span>
                            <select class="print-type-select folder-print-type" aria-label="Set print type for all parts" data-folder="<?= htmlspecialchars($dir) ?>" title="Set print type for all parts">
                                <option value="">Set All Print Type</option>
                                <option value="fdm">FDM</option>
                                <option value="sla">SLA</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <?php $partLimit = 50; $partIndex = 0; ?>
                        <div class="parts-list">
                            <?php foreach ($dirParts as $part): $partIndex++; ?>
                            <div class="part-item<?= ($partIndex > $partLimit && count($dirParts) > $partLimit) ? ' part-hidden' : '' ?>" data-part-id="<?= $part['id'] ?>" data-part-path="/preview?id=<?= $part['id'] ?>" data-part-type="<?= htmlspecialchars($part['file_type']) ?>" data-part-name="<?= htmlspecialchars($part['name']) ?>">
                                <?php if (canEdit()): ?>
                                <span class="drag-handle" title="Drag to reorder" aria-hidden="true">&#8942;&#8942;</span>
                                <?php endif; ?>
                                <?php if (canEdit() || canDelete()): ?>
                                <input type="checkbox" class="part-checkbox" value="<?= $part['id'] ?>" aria-label="Select <?= htmlspecialchars($part['name']) ?>">
                                <?php endif; ?>
                                <div class="part-info part-preview-trigger">
                                    <span class="part-name" title="Click to preview"><?= htmlspecialchars($part['name']) ?><?= !empty($part['file_type']) ? '.' . htmlspecialchars($part['file_type']) : '' ?></span>
                                    <?php if (isFeatureEnabled('model_notes') && !empty($part['notes'])): ?>
                                    <span class="part-notes"><?= htmlspecialchars($part['notes']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="part-actions">
                                    <?php if (canEdit()): ?>
                                    <select class="print-type-select" data-part-id="<?= $part['id'] ?>" title="Print type" aria-label="Print type">
                                        <option value="">--</option>
                                        <option value="fdm"<?= ($part['print_type'] ?? '') === 'fdm' ? ' selected' : '' ?>>FDM</option>
                                        <option value="sla"<?= ($part['print_type'] ?? '') === 'sla' ? ' selected' : '' ?>>SLA</option>
                                    </select>
                                    <?php elseif (!empty($part['print_type'])): ?>
                                    <span class="print-type-badge"><?= htmlspecialchars(strtoupper($part['print_type'])) ?></span>
                                    <?php endif; ?>
                                    <span class="part-size"<?php if (($part['file_type'] ?? '') === 'stl'): ?> title="STL file — converting to 3MF could save ~<?= formatBytes(($part['file_size'] ?? 0) * 0.65) ?>"<?php endif; ?>><?= formatBytes($part['file_size'] ?? 0) ?></span>
                                    <?php if (!empty($part['original_size']) && $part['file_type'] === '3mf'): ?>
                                    <span class="conversion-savings" title="Saved by converting to 3MF">-<?= round((1 - $part['file_size'] / $part['original_size']) * 100) ?>%</span>
                                    <?php endif; ?>
                                    <a href="<?= route('actions.download', [], ['id' => $part['id']]) ?>" class="btn btn-small btn-primary">Download</a>
                                    <?php if (canDelete()): ?>
                                    <a href="<?= route('actions.delete', [], ['id' => $model['id'], 'part_id' => $part['id']]) ?>" class="btn btn-small btn-danger" title="Delete part">Delete</a>
                                    <?php endif; ?>
                                    <div class="dropdown part-actions-dropdown">
                                        <button type="button" class="btn btn-small btn-secondary dropdown-toggle" title="More actions" aria-haspopup="true" aria-expanded="false">
                                            &#8943;
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-right" role="menu">
                                            <button type="button" class="dropdown-item calc-dimensions-btn" role="menuitem" data-part-id="<?= $part['id'] ?>">Calculate Dimensions</button>
                                            <button type="button" class="dropdown-item calc-volume-btn" role="menuitem" data-part-id="<?= $part['id'] ?>">Calculate Volume</button>
                                            <?php if (strtolower($part['file_type']) === 'stl'): ?>
                                            <button type="button" class="dropdown-item analyze-mesh-btn" role="menuitem" data-part-id="<?= $part['id'] ?>">Analyze Mesh</button>
                                            <?php endif; ?>
                                            <?php if (canEdit()): ?>
                                            <div class="dropdown-divider" role="separator"></div>
                                            <button type="button" class="dropdown-item show-move-folder-single" role="menuitem" data-part-id="<?= $part['id'] ?>">Move to Folder</button>
                                            <?php endif; ?>
                                            <?php if (canEdit() && $part['file_type'] === 'stl'): ?>
                                            <button type="button" class="dropdown-item convert-btn" role="menuitem" data-part-id="<?= $part['id'] ?>">Convert to 3MF</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if (class_exists('PluginManager')): ?>
                                    <?= PluginManager::applyFilter('part_row_actions', '', $part) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (count($dirParts) > $partLimit): ?>
                            <button type="button" class="btn btn-secondary btn-small show-more-parts" style="margin: 0.5rem auto; display: block;">
                                Show <?= count($dirParts) - $partLimit ?> more parts
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="parts-actions">
                        <a href="<?= route('actions.download.all', [], ['id' => $model['id']]) ?>" class="btn btn-primary">Download All Parts</a>
                        <?php if (canUpload()): ?>
                        <button type="button" class="btn btn-secondary trigger-add-parts">Add Parts</button>
                        <input type="file" id="add-part-file" accept=".stl,.3mf,.obj,.ply,.amf,.gcode,.glb,.gltf,.fbx,.dae,.blend,.step,.stp,.iges,.igs,.3ds,.dxf,.off,.x3d,.lys,.ctb,.pwmo,.sl1" multiple hidden aria-label="Add model parts">
                        <?php endif; ?>
                    </div>
                </div>
                <?php elseif (canUpload()): ?>
                <div class="model-download">
                    <a href="<?= route('actions.download', [], ['id' => $model['id']]) ?>" class="btn btn-primary btn-large">Download Model</a>
                    <button type="button" class="btn btn-secondary trigger-add-parts">Add Parts</button>
                    <input type="file" id="add-part-file" accept=".stl,.3mf,.obj,.ply,.amf,.gcode,.glb,.gltf,.fbx,.dae,.blend,.step,.stp,.iges,.igs,.3ds,.dxf,.off,.x3d,.lys,.ctb,.pwmo,.sl1" multiple hidden aria-label="Add model parts">
                </div>
                <?php else: ?>
                <div class="model-download">
                    <a href="<?= route('actions.download', [], ['id' => $model['id']]) ?>" class="btn btn-primary btn-large">Download Model</a>
                </div>
                <?php endif; ?>
                </div>

                <div class="model-content-sidebar">
                    <?php if (isFeatureEnabled('attachments') && (!empty($attachments['images']) || !empty($attachments['documents']) || canEdit())): ?>
                    <div class="model-attachments collapsible-section">
                        <h3 class="collapsible-header" tabindex="0" role="button" aria-expanded="true">
                            <span class="folder-toggle" aria-hidden="true">&#9660;</span>
                            Attachments
                        </h3>
                        <div class="collapsible-body">

                        <?php if (!empty($attachments['images'])): ?>
                        <div class="attachment-section">
                            <h4>Images</h4>
                            <div class="attachment-grid" id="attachment-images">
                                <?php foreach ($attachments['images'] as $att): ?>
                                <div class="attachment-image" data-attachment-id="<?= $att['id'] ?>">
                                    <img src="/assets/<?= htmlspecialchars($att['file_path']) ?>"
                                         alt="<?= htmlspecialchars($att['original_filename']) ?>"
                                         loading="lazy" decoding="async"
                                         tabindex="0" role="button" class="attachment-image-trigger"
                                         data-lightbox-src="<?= htmlspecialchars('/assets/' . $att['file_path']) ?>" data-lightbox-alt="<?= htmlspecialchars($att['original_filename']) ?>"
                                        >
                                    <?php if (canEdit()): ?>
                                    <button type="button" class="attachment-set-thumb" aria-label="Set as model thumbnail" title="Set as model thumbnail">&#128247;</button>
                                    <button type="button" class="attachment-delete" aria-label="Delete attachment" title="Delete">&times;</button>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($attachments['documents'])): ?>
                        <div class="attachment-section">
                            <h4>Documents</h4>
                            <div class="attachment-documents" id="attachment-documents">
                                <?php foreach ($attachments['documents'] as $att): ?>
                                <?php $docExt = strtolower(pathinfo($att['original_filename'], PATHINFO_EXTENSION) ?: $att['file_type']); ?>
                                <div class="attachment-document" data-attachment-id="<?= $att['id'] ?>">
                                    <span class="file-type-badge">.<?= htmlspecialchars($docExt) ?></span>
                                    <a href="/assets/<?= htmlspecialchars($att['file_path']) ?>" class="attachment-doc-name attachment-preview-trigger" data-preview-src="/assets/<?= htmlspecialchars($att['file_path']) ?>" data-preview-type="<?= htmlspecialchars($docExt) ?>" data-preview-name="<?= htmlspecialchars($att['original_filename']) ?>">
                                        <?= htmlspecialchars($att['original_filename']) ?>
                                    </a>
                                    <span class="attachment-doc-size"><?= formatBytes($att['file_size']) ?></span>
                                    <?php if (canEdit()): ?>
                                    <button type="button" class="attachment-delete" aria-label="Delete attachment" title="Delete">&times;</button>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (empty($attachments['images']) && empty($attachments['documents'])): ?>
                        <p class="attachments-empty" id="attachments-empty">No attachments yet.</p>
                        <?php endif; ?>

                        <?php if (canEdit()): ?>
                        <div class="attachment-upload">
                            <input type="file" id="attachment-file-input" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.md" multiple style="display:none" aria-label="Upload attachments">
                            <button type="button" class="btn btn-secondary btn-small trigger-attachment-upload">Add Attachment</button>
                            <span class="attachment-hint">Images, PDFs &amp; Text Files</span>
                        </div>
                        <?php endif; ?>

                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                </div>
            </div>
        </div>

        <!-- Part Preview Modal -->
        <div id="part-preview-modal" class="modal modal-overlay" role="dialog" aria-modal="true" aria-labelledby="preview-part-name" style="display: none;">
            <div class="modal-content modal-large">
                <div class="modal-header">
                    <h3 id="preview-part-name">Part Preview</h3>
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="part-preview-container" style="width: 100%; height: 400px;"></div>
                </div>
            </div>
        </div>

        <?php if (isLoggedIn() && isFeatureEnabled('share_links')): ?>
        <!-- Share Modal -->
        <div id="share-modal" class="modal modal-overlay" role="dialog" aria-modal="true" aria-labelledby="share-modal-title" style="display: none;">
            <div class="modal-content modal-large">
                <div class="modal-header">
                    <h3 id="share-modal-title">Share "<?= htmlspecialchars($model['name']) ?>"</h3>
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <!-- Create New Share Link -->
                    <div class="share-create-section">
                        <h4>Create Share Link</h4>
                        <form id="share-link-form" method="post" class="share-form">
                            <div class="share-form-row">
                                <div class="form-group">
                                    <label for="share-expires">Expires In</label>
                                    <select id="share-expires" class="form-input">
                                        <option value="">Never</option>
                                        <option value="1 hour">1 Hour</option>
                                        <option value="24 hours">24 Hours</option>
                                        <option value="7 days">7 Days</option>
                                        <option value="30 days">30 Days</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="share-max-downloads">Max Downloads</label>
                                    <input type="number" id="share-max-downloads" class="form-input" min="0" placeholder="Unlimited">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="share-password">Password (optional)</label>
                                <div class="password-wrapper">
                                    <input type="password" id="share-password" class="form-input" placeholder="Leave empty for no password" autocomplete="off">
                                    <button type="button" class="password-toggle" aria-label="Show password" title="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">Create Share Link</button>
                        </form>
                    </div>

                    <!-- Existing Share Links -->
                    <div class="share-links-section">
                        <h4>Active Share Links</h4>
                        <div id="share-links-list" class="share-links-list">
                            <p class="text-muted">Loading...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (canEdit() && !empty($parts)): ?>
        <!-- Create Folder Modal -->
        <div id="create-folder-modal" class="modal modal-overlay" role="dialog" aria-modal="true" aria-labelledby="create-folder-title" style="display: none;">
            <div class="modal-content" style="max-width: 400px;">
                <div class="modal-header">
                    <h3 id="create-folder-title">Create Folder</h3>
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="create-folder-form" method="post">
                        <div class="form-group">
                            <label for="new-folder-name">Folder Name</label>
                            <input type="text" id="new-folder-name" class="form-input" placeholder="Enter folder name (e.g. Parts/Screws)" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Create</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Move to Folder Modal -->
        <div id="move-folder-modal" class="modal modal-overlay" role="dialog" aria-modal="true" aria-labelledby="move-folder-title" style="display: none;">
            <div class="modal-content" style="max-width: 400px;">
                <div class="modal-header">
                    <h3 id="move-folder-title">Move to Folder</h3>
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="move-folder-list" class="folder-picker">
                        <label class="folder-picker-option">
                            <input type="radio" name="target-folder" value="Root">
                            <span>Root (no folder)</span>
                        </label>
                        <?php foreach ($groupedParts as $fpath => $fdata): ?>
                        <?php if ($fpath !== 'Root'): ?>
                        <label class="folder-picker-option">
                            <input type="radio" name="target-folder" value="<?= htmlspecialchars($fpath) ?>">
                            <span><?= htmlspecialchars($fdata['display']) ?></span>
                        </label>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" id="move-part-ids" value="">
                    <button type="button" class="btn btn-primary submit-move-folder" style="margin-top: 1rem;">Move</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canManageVersions): ?>
        <!-- Upload New Version Modal -->
        <div id="upload-version-modal" class="modal modal-overlay" role="dialog" aria-modal="true" aria-labelledby="upload-version-title" style="display: none;">
            <div class="modal-content" style="max-width: 480px;">
                <div class="modal-header">
                    <h3 id="upload-version-title">Upload New Version</h3>
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="upload-version-form" method="post">
                        <div class="form-group">
                            <label for="version-file">File</label>
                            <input type="file" id="version-file" class="form-input" accept=".stl,.3mf,.gcode" required aria-label="Select version file">
                        </div>
                        <div class="form-group">
                            <label for="version-changelog">Changelog (optional)</label>
                            <textarea id="version-changelog" class="form-input" rows="3" placeholder="Describe what changed in this version..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary" id="version-submit-btn">Upload Version</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Batch Rename Modal -->
        <div id="batch-rename-modal" class="modal modal-overlay" role="dialog" aria-modal="true" aria-labelledby="batch-rename-title" style="display: none;">
            <div class="modal-content" style="max-width: 480px;">
                <div class="modal-header">
                    <h3 id="batch-rename-title">Batch Rename Parts</h3>
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="rename-pattern">Pattern</label>
                        <input type="text" id="rename-pattern" class="form-input" placeholder="{name}">
                        <small class="form-hint">Placeholders: {name} = current name, {index} = number (1,2,3...), {ext} = extension</small>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="rename-prefix">Prefix</label>
                            <input type="text" id="rename-prefix" class="form-input" placeholder="(optional)">
                        </div>
                        <div class="form-group">
                            <label for="rename-suffix">Suffix</label>
                            <input type="text" id="rename-suffix" class="form-input" placeholder="(optional)">
                        </div>
                    </div>
                    <div class="rename-preview">
                        <strong>Preview:</strong>
                        <ul id="rename-preview-list"></ul>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary cancel-batch-rename">Cancel</button>
                        <button type="button" class="btn btn-primary apply-batch-rename">Rename</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isset($model)): ?>
        <script>
        window.ModelPageConfig = {
            modelId: <?= (int)$model['id'] ?>,
            csrfToken: <?= json_encode(Csrf::getToken()) ?>,
            modelName: <?= json_encode($model['name']) ?>,
            allTags: <?= json_encode(getAllTags()) ?>,
            updatePartRoute: <?= json_encode(route('actions.update.part')) ?>
        };
        </script>
        <?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
<?php
} catch (Throwable $e) {
    $errorContext = [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'model_id' => $modelId ?? 'unknown',
        'exception' => get_class($e),
        'trace' => $e->getTraceAsString()
    ];
    logError('Model page error: ' . $e->getMessage(), $errorContext);

    // Write error details to a known location for easy debugging
    $debugFile = dirname(__DIR__, 2) . '/storage/logs/model-page-error.log';
    @file_put_contents($debugFile, date('[Y-m-d H:i:s] ') . $e->getMessage() . "\n" .
        "File: {$e->getFile()}:{$e->getLine()}\n" .
        "Model ID: " . ($modelId ?? 'unknown') . "\n" .
        "Exception: " . get_class($e) . "\n" .
        "Trace:\n{$e->getTraceAsString()}\n\n", FILE_APPEND | LOCK_EX);

    throw $e;
}
?>
