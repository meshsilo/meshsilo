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
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
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
        SELECT * FROM models
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
    $stmt = $db->prepare('SELECT * FROM model_links WHERE model_id = :model_id ORDER BY sort_order, created_at');
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
    $stmt = $db->prepare('SELECT * FROM model_attachments WHERE model_id = :model_id ORDER BY display_order, created_at');
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
    // model_attachments table may not exist yet
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
            <div role="<?= $messageType === 'success' ? 'status' : 'alert' ?>" class="alert alert-<?= $messageType ?>" style="margin-bottom: 1.5rem;"><?= htmlspecialchars($message) ?></div>
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
                        <img src="<?= htmlspecialchars($thumbnailUrl) ?>" alt="<?= htmlspecialchars($model['name']) ?>" class="model-thumbnail-image">
                        <?php endif; ?>
                        <?php if ($model['part_count'] > 0): ?>
                        <span class="part-count-badge"><?= $model['part_count'] ?> <?= $model['part_count'] === 1 ? 'part' : 'parts' ?></span>
                        <?php endif; ?>

                    </div>
                    <div class="model-detail-info">
                        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;">
                            <h1><?= htmlspecialchars($model['name']) ?></h1>
                            <?php if (isLoggedIn()): ?>
                            <div style="display: flex; gap: 0.5rem;">
                                <?php if (class_exists('PluginManager')): ?>
                                <?= PluginManager::applyFilter('model_header_actions', '', $model) ?>
                                <?php endif; ?>
                                <?php if (isFeatureEnabled('favorites')): ?>
                                <button type="button" class="favorite-btn <?= $isFavorited ? 'favorited' : '' ?>" onclick="toggleFavorite(<?= $model['id'] ?>, this)" title="<?= $isFavorited ? 'Remove from favorites' : 'Add to favorites' ?>">
                                    <?= $isFavorited ? '&#9829;' : '&#9825;' ?>
                                </button>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($model['is_archived'])): ?>
                        <span class="archived-badge" style="margin-bottom: 0.5rem;">Archived</span>
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
                            <span class="meta-item" data-timestamp="<?= htmlspecialchars($model['created_at']) ?>">
                                <strong>Added:</strong> <?= date('M j, Y', strtotime($model['created_at'])) ?>
                            </span>
                            <?php if (isFeatureEnabled('download_tracking') && ($model['download_count'] ?? 0) > 0): ?>
                            <span class="meta-item download-count">
                                <strong>Downloads:</strong> <?= number_format($model['download_count']) ?>
                            </span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($model['license'])): ?>
                        <div style="margin-top: 0.5rem;">
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
                                <button type="button" class="model-tag-remove" aria-label="Remove tag" onclick="event.preventDefault(); event.stopPropagation(); removeTag(<?= $model['id'] ?>, <?= $tag['id'] ?>, this.parentElement)" title="Remove tag">&times;</button>
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
                            <a href="<?= htmlspecialchars($model['source_url']) ?>" target="_blank" rel="noopener">View Original Source</a>
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
                                    <button type="button" class="model-link-delete" aria-label="Remove link" onclick="deleteModelLink(<?= $link['id'] ?>)" title="Remove link">&times;</button>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                                <?php if (empty($modelLinks)): ?>
                                <p class="model-links-empty" id="model-links-empty">No external links yet.</p>
                                <?php endif; ?>
                            </div>
                            <?php if (canEdit()): ?>
                            <button type="button" class="btn btn-small btn-secondary" id="add-link-toggle" onclick="toggleAddLinkForm()">Add Link</button>
                            <div class="model-link-add-form" id="add-link-form" style="display:none;">
                                <div class="link-form-row">
                                    <input type="text" id="link-title" class="form-input" placeholder="Link title" required>
                                    <input type="url" id="link-url" class="form-input" placeholder="https://..." required>
                                    <select id="link-type" class="form-input">
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
                                    <button type="button" class="btn btn-small btn-secondary" onclick="toggleAddLinkForm()">Cancel</button>
                                    <button type="button" class="btn btn-small btn-primary" onclick="addModelLink()">Add</button>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (isFeatureEnabled('attachments') && (!empty($attachments['images']) || !empty($attachments['documents']) || canEdit())): ?>
                        <div class="model-attachments">
                            <h3>Attachments</h3>

                            <?php if (!empty($attachments['images'])): ?>
                            <div class="attachment-section">
                                <h4>Images</h4>
                                <div class="attachment-grid" id="attachment-images">
                                    <?php foreach ($attachments['images'] as $att): ?>
                                    <div class="attachment-image" data-attachment-id="<?= $att['id'] ?>">
                                        <img src="/assets/<?= htmlspecialchars($att['file_path']) ?>"
                                             alt="<?= htmlspecialchars($att['original_filename']) ?>"
                                             loading="lazy"
                                             onclick="openImageLightbox(<?= htmlspecialchars(json_encode('/assets/' . $att['file_path'])) ?>, <?= htmlspecialchars(json_encode($att['original_filename'])) ?>)">
                                        <?php if (canEdit()): ?>
                                        <button type="button" class="attachment-set-thumb" aria-label="Set as model thumbnail" onclick="setAttachmentAsThumbnail(<?= $att['id'] ?>)" title="Set as model thumbnail">&#128247;</button>
                                        <button type="button" class="attachment-delete" aria-label="Delete attachment" onclick="deleteAttachment(<?= $att['id'] ?>)" title="Delete">&times;</button>
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
                                    <div class="attachment-document" data-attachment-id="<?= $att['id'] ?>">
                                        <span class="file-type-badge">.<?= htmlspecialchars(pathinfo($att['original_filename'], PATHINFO_EXTENSION) ?: $att['file_type']) ?></span>
                                        <a href="/assets/<?= htmlspecialchars($att['file_path']) ?>" target="_blank" rel="noopener" class="attachment-doc-name">
                                            <?= htmlspecialchars($att['original_filename']) ?>
                                        </a>
                                        <span class="attachment-doc-size"><?= formatBytes($att['file_size']) ?></span>
                                        <?php if (canEdit()): ?>
                                        <button type="button" class="attachment-delete" aria-label="Delete attachment" onclick="deleteAttachment(<?= $att['id'] ?>)" title="Delete">&times;</button>
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
                                <input type="file" id="attachment-file-input" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.md" multiple style="display:none" onchange="uploadAttachments(this.files)">
                                <button type="button" class="btn btn-secondary btn-small" onclick="document.getElementById('attachment-file-input').click()">Add Attachment</button>
                                <span class="attachment-hint">Images, PDFs &amp; Text Files</span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (class_exists('PluginManager')): ?>
                        <?= PluginManager::applyFilter('model_detail_sidebar', '', $model) ?>
                        <?php endif; ?>

                        <div class="model-actions" style="margin-top: 1rem;">
                            <button type="button" class="copy-link-btn" onclick="copyPageUrl()" title="Copy link to clipboard">&#128279; Copy Link</button>
                            <?php if (isLoggedIn() && isFeatureEnabled('share_links')): ?>
                            <button type="button" class="btn btn-secondary btn-small" onclick="openShareModal()">Share</button>
                            <?php endif; ?>
                            <?php if (isFeatureEnabled('version_history')): ?>
                            <a href="<?= route('model.versions', ['id' => $model['id']]) ?>" class="btn btn-secondary btn-small">Version History<?php if ($versionCount > 0): ?> (<?= $versionCount ?>)<?php endif; ?></a>
                            <?php if ($canManageVersions): ?>
                            <button type="button" class="btn btn-secondary btn-small" onclick="showUploadVersionModal()">Upload New Version</button>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if (canEdit()): ?>
                            <button type="button" class="btn btn-secondary btn-small" onclick="window.location.href='<?= route('model.edit', ['id' => $model['id']]) ?>'">Edit Model</button>
                            <button type="button" class="btn btn-secondary btn-small" onclick="showCreateFolderModal()">New Folder</button>
                            <?php if (!empty($model['is_archived'])): ?>
                            <button type="button" class="btn btn-secondary btn-small" onclick="toggleArchive(<?= $model['id'] ?>, false)">Unarchive</button>
                            <?php else: ?>
                            <button type="button" class="btn btn-secondary btn-small" onclick="toggleArchive(<?= $model['id'] ?>, true)">Archive</button>
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
                            <span class="related-meta" data-timestamp="<?= htmlspecialchars($rm['created_at']) ?>"><?= date('M j, Y', strtotime($rm['created_at'])) ?></span>
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
                                    <span data-timestamp="<?= htmlspecialchars($v['created_at']) ?>"><?= date('M j, Y', strtotime($v['created_at'])) ?></span>
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

                <?php if (!empty($parts)): ?>
                <div class="model-parts">
                    <div class="parts-header">
                        <?php if (count($groupedParts) > 1): ?>
                        <span class="collapse-all-toggle" onclick="toggleCollapseAllGroups(this)" title="Collapse/expand all groups">&#9660;</span>
                        <?php endif; ?>
                        <?php if (canEdit() || canDelete()): ?>
                        <input type="checkbox" class="select-all-checkbox" id="select-all-parts" onclick="toggleSelectAllParts(this)" title="Select all parts">
                        <?php endif; ?>
                        <h2>Parts (<?= count($parts) ?>)</h2>
                        <?php if (canEdit() || canDelete()): ?>
                        <div class="mass-actions" id="parts-mass-actions" style="display: none;">
                            <span class="mass-selection-count"><span id="selected-count">0</span> selected</span>
                            <?php if (canEdit()): ?>
                            <button type="button" class="btn btn-secondary btn-small" onclick="showMoveFolderModal(getSelectedPartIds())">Move to Folder</button>
                            <button type="button" class="btn btn-secondary btn-small" onclick="showBatchRenameModal(getSelectedPartIds())">Rename</button>
                            <select class="print-type-select" id="mass-print-type" onchange="massUpdatePrintType(this)" title="Set print type for selected parts">
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
                        <h3 class="parts-group-header" onclick="toggleFolder(this.parentElement)">
                            <span class="folder-toggle"><?= $autoCollapse ? '&#9654;' : '&#9660;' ?></span>
                            <?php if (canEdit() || canDelete()): ?>
                            <input type="checkbox" class="folder-checkbox" onclick="event.stopPropagation(); selectFolderParts(this);" title="Select all parts in this folder">
                            <?php endif; ?>
                            <?= htmlspecialchars($displayName) ?>
                            <span class="folder-part-count">(<?= count($dirParts) ?>)</span>
                            <?php if (canEdit()): ?>
                            <span class="folder-actions" onclick="event.stopPropagation()">
                                <select class="print-type-select folder-print-type" aria-label="Set print type for all parts in this folder" onchange="updateFolderPrintType(this, '<?= htmlspecialchars(addslashes($dir)) ?>')" title="Set print type for all parts in this folder">
                                    <option value="">--</option>
                                    <option value="fdm">FDM</option>
                                    <option value="sla">SLA</option>
                                </select>
                                <?php if ($dir !== 'Root'): ?>
                                <button type="button" onclick="renameFolder('<?= htmlspecialchars(addslashes($dir)) ?>')" title="Rename folder">Rename</button>
                                <button type="button" onclick="deleteFolder('<?= htmlspecialchars(addslashes($dir)) ?>')" title="Delete folder (moves parts to root)">Delete</button>
                                <?php endif; ?>
                            </span>
                            <?php endif; ?>
                        </h3>
                        <?php elseif (canEdit() && count($dirParts) > 1): ?>
                        <div class="parts-group-actions">
                            <span class="text-muted"><?= count($dirParts) ?> parts</span>
                            <select class="print-type-select folder-print-type" aria-label="Set print type for all parts" onchange="updateFolderPrintType(this, '<?= htmlspecialchars(addslashes($dir)) ?>')" title="Set print type for all parts">
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
                                <span class="drag-handle" title="Drag to reorder">&#8942;&#8942;</span>
                                <?php endif; ?>
                                <?php if (canEdit() || canDelete()): ?>
                                <input type="checkbox" class="part-checkbox" value="<?= $part['id'] ?>">
                                <?php endif; ?>
                                <div class="part-info part-preview-trigger">
                                    <span class="part-name" title="Click to preview"><?= htmlspecialchars($part['name']) ?><?= !empty($part['file_type']) ? '.' . htmlspecialchars($part['file_type']) : '' ?></span>
                                    <?php if (isFeatureEnabled('model_notes') && !empty($part['notes'])): ?>
                                    <span class="part-notes"><?= htmlspecialchars($part['notes']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="part-actions">
                                    <?php if (canEdit()): ?>
                                    <select class="print-type-select" data-part-id="<?= $part['id'] ?>" onchange="updatePrintType(this)" title="Print type">
                                        <option value="">--</option>
                                        <option value="fdm"<?= ($part['print_type'] ?? '') === 'fdm' ? ' selected' : '' ?>>FDM</option>
                                        <option value="sla"<?= ($part['print_type'] ?? '') === 'sla' ? ' selected' : '' ?>>SLA</option>
                                    </select>
                                    <?php elseif (!empty($part['print_type'])): ?>
                                    <span class="print-type-badge"><?= strtoupper($part['print_type']) ?></span>
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
                                        <button type="button" class="btn btn-small btn-secondary dropdown-toggle" title="More actions">
                                            &#8943;
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-right">
                                            <a href="#" class="dropdown-item" onclick="calculatePartDimensions(<?= $part['id'] ?>, this); return false;">Calculate Dimensions</a>
                                            <a href="#" class="dropdown-item" onclick="calculatePartVolume(<?= $part['id'] ?>, this); return false;">Calculate Volume</a>
                                            <?php if (strtolower($part['file_type']) === 'stl'): ?>
                                            <a href="#" class="dropdown-item" onclick="analyzePartMesh(<?= $part['id'] ?>, this); return false;">Analyze Mesh</a>
                                            <?php endif; ?>
                                            <?php if (canEdit()): ?>
                                            <div class="dropdown-divider"></div>
                                            <a href="#" class="dropdown-item" onclick="showMoveFolderModal([<?= $part['id'] ?>]); return false;">Move to Folder</a>
                                            <?php endif; ?>
                                            <?php if (canEdit() && $part['file_type'] === 'stl'): ?>
                                            <a href="#" class="dropdown-item convert-btn" data-part-id="<?= $part['id'] ?>">Convert to 3MF</a>
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
                            <button type="button" class="btn btn-secondary btn-small show-more-parts" onclick="showMoreParts(this)" style="margin: 0.5rem auto; display: block;">
                                Show <?= count($dirParts) - $partLimit ?> more parts
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="parts-actions">
                        <a href="<?= route('actions.download.all', [], ['id' => $model['id']]) ?>" class="btn btn-primary">Download All Parts</a>
                        <?php if (canUpload()): ?>
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('add-part-file').click()">Add Parts</button>
                        <input type="file" id="add-part-file" accept=".stl,.3mf,.obj,.ply,.amf,.gcode,.glb,.gltf,.fbx,.dae,.blend,.step,.stp,.iges,.igs,.3ds,.dxf,.off,.x3d,.lys,.ctb,.pwmo,.sl1" multiple hidden onchange="uploadParts(this.files)">
                        <?php endif; ?>
                    </div>
                </div>
                <?php elseif (canUpload()): ?>
                <div class="model-download">
                    <a href="<?= route('actions.download', [], ['id' => $model['id']]) ?>" class="btn btn-primary btn-large">Download Model</a>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('add-part-file').click()">Add Parts</button>
                    <input type="file" id="add-part-file" accept=".stl,.3mf,.obj,.ply,.amf,.gcode,.glb,.gltf,.fbx,.dae,.blend,.step,.stp,.iges,.igs,.3ds,.dxf,.off,.x3d,.lys,.ctb,.pwmo,.sl1" multiple hidden onchange="uploadParts(this.files)">
                </div>
                <?php else: ?>
                <div class="model-download">
                    <a href="<?= route('actions.download', [], ['id' => $model['id']]) ?>" class="btn btn-primary btn-large">Download Model</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Part Preview Modal -->
        <div id="part-preview-modal" class="modal-overlay" role="dialog" aria-modal="true" style="display: none;">
            <div class="modal-content modal-large">
                <div class="modal-header">
                    <h3 id="preview-part-name">Part Preview</h3>
                    <button type="button" class="modal-close" aria-label="Close" onclick="closePartPreview()">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="part-preview-container" style="width: 100%; height: 400px;"></div>
                </div>
            </div>
        </div>

        <?php if (isLoggedIn() && isFeatureEnabled('share_links')): ?>
        <!-- Share Modal -->
        <div id="share-modal" class="modal-overlay" role="dialog" aria-modal="true" style="display: none;">
            <div class="modal-content modal-large">
                <div class="modal-header">
                    <h3>Share "<?= htmlspecialchars($model['name']) ?>"</h3>
                    <button type="button" class="modal-close" aria-label="Close" onclick="closeShareModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <!-- Create New Share Link -->
                    <div class="share-create-section">
                        <h4>Create Share Link</h4>
                        <form id="share-link-form" class="share-form">
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
                                    <input type="password" id="share-password" class="form-input" placeholder="Leave empty for no password">
                                    <button type="button" class="password-toggle" aria-label="Show password" onclick="togglePasswordVisibility(this)" title="Show password">&#9678;</button>
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
        <div id="create-folder-modal" class="modal-overlay" role="dialog" aria-modal="true" style="display: none;">
            <div class="modal-content" style="max-width: 400px;">
                <div class="modal-header">
                    <h3>Create Folder</h3>
                    <button type="button" class="modal-close" aria-label="Close" onclick="closeCreateFolderModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="create-folder-form" onsubmit="submitCreateFolder(event)">
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
        <div id="move-folder-modal" class="modal-overlay" role="dialog" aria-modal="true" style="display: none;">
            <div class="modal-content" style="max-width: 400px;">
                <div class="modal-header">
                    <h3>Move to Folder</h3>
                    <button type="button" class="modal-close" aria-label="Close" onclick="closeMoveFolderModal()">&times;</button>
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
                    <button type="button" class="btn btn-primary" onclick="submitMoveToFolder()" style="margin-top: 1rem;">Move</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canManageVersions): ?>
        <!-- Upload New Version Modal -->
        <div id="upload-version-modal" class="modal-overlay" role="dialog" aria-modal="true" style="display: none;">
            <div class="modal-content" style="max-width: 480px;">
                <div class="modal-header">
                    <h3>Upload New Version</h3>
                    <button type="button" class="modal-close" aria-label="Close" onclick="closeUploadVersionModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="upload-version-form" onsubmit="submitUploadVersion(event)">
                        <div class="form-group">
                            <label for="version-file">File</label>
                            <input type="file" id="version-file" class="form-input" accept=".stl,.3mf,.gcode" required>
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
        <div id="batch-rename-modal" class="modal-overlay" role="dialog" aria-modal="true" style="display: none;">
            <div class="modal-content" style="max-width: 480px;">
                <div class="modal-header">
                    <h3>Batch Rename Parts</h3>
                    <button type="button" class="modal-close" aria-label="Close" onclick="closeBatchRenameModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="rename-pattern">Pattern</label>
                        <input type="text" id="rename-pattern" class="form-input" placeholder="{name}" oninput="updateRenamePreview()">
                        <small class="form-hint">Placeholders: {name} = current name, {index} = number (1,2,3...), {ext} = extension</small>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="rename-prefix">Prefix</label>
                            <input type="text" id="rename-prefix" class="form-input" placeholder="(optional)" oninput="updateRenamePreview()">
                        </div>
                        <div class="form-group">
                            <label for="rename-suffix">Suffix</label>
                            <input type="text" id="rename-suffix" class="form-input" placeholder="(optional)" oninput="updateRenamePreview()">
                        </div>
                    </div>
                    <div class="rename-preview">
                        <strong>Preview:</strong>
                        <ul id="rename-preview-list"></ul>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeBatchRenameModal()">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="applyBatchRename()">Rename</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

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
                    const estimateResponse = await fetch(`/actions/convert-part?action=estimate&part_id=${partId}`);
                    const estimate = await estimateResponse.json();

                    if (!estimate.success) {
                        showToast('Cannot estimate conversion: ' + (estimate.error || 'Unknown error'), 'error');
                        this.textContent = originalText;
                        this.disabled = false;
                        return;
                    }

                    if (!estimate.worth_converting) {
                        showToast('Converting this file would not save space. Keeping original STL.', 'info');
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

                    const confirmed = await showConfirm(
                        'Convert to 3MF? Current size: ' + formatBytes(estimate.original_size) +
                        ', Estimated new size: ' + formatBytes(estimate.estimated_size) +
                        ', Estimated savings: ' + formatBytes(estimate.estimated_savings) + ' (' + estimate.estimated_savings_percent + '%). This will replace the STL file with a 3MF file.'
                    );

                    if (!confirmed) {
                        this.textContent = originalText;
                        this.disabled = false;
                        return;
                    }

                    // Queue the conversion as a background job
                    this.textContent = 'Queuing...';

                    const formData = new FormData();
                    formData.append('action', 'convert');
                    formData.append('part_id', partId);

                    const convertResponse = await fetch('/actions/convert-part', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await convertResponse.json();

                    if (result.success) {
                        this.textContent = 'Queued';
                        if (typeof refreshQueueStatus === 'function') refreshQueueStatus();
                    } else {
                        showToast('Failed to queue conversion: ' + (result.error || 'Unknown error'), 'error');
                        this.textContent = originalText;
                        this.disabled = false;
                    }
                } catch (err) {
                    console.error('Conversion error:', err);
                    showToast('Failed to queue conversion', 'error');
                    this.textContent = originalText;
                    this.disabled = false;
                }
            });
        });

        // Mass action handling
        const partCheckboxes = document.querySelectorAll('.part-checkbox');
        const massActionsBar = document.getElementById('parts-mass-actions');
        const selectedCountEl = document.getElementById('selected-count');
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

        async function massUpdatePrintType(selectEl) {
            const printType = selectEl.value;
            if (!printType) return;

            const ids = getSelectedPartIds();
            if (ids.length === 0) {
                showToast('No parts selected', 'error');
                selectEl.value = '';
                return;
            }

            const actualType = printType === 'clear' ? '' : printType;
            const label = printType === 'clear' ? 'none' : printType.toUpperCase();
            if (!await showConfirm('Set print type to ' + label + ' for ' + ids.length + ' selected part(s)?')) {
                selectEl.value = '';
                return;
            }

            let success = 0;
            for (const partId of ids) {
                const formData = new FormData();
                formData.append('part_id', partId);
                formData.append('print_type', actualType);
                formData.append('csrf_token', '<?= Csrf::getToken() ?>');
                try {
                    const resp = await fetch('<?= route('actions.update.part') ?>', { method: 'POST', body: formData });
                    const data = await resp.json();
                    if (data.success) {
                        success++;
                        // Update the individual dropdown
                        const partEl = document.querySelector('[data-part-id="' + partId + '"].print-type-select:not(.folder-print-type)');
                        if (partEl) {
                            partEl.value = actualType;
                            partEl.dataset.prev = actualType;
                        }
                    }
                } catch (e) {}
            }
            selectEl.value = '';
        }

        function toggleSelectAllParts(checkbox) {
            document.querySelectorAll('.part-checkbox').forEach(cb => cb.checked = checkbox.checked);
            updateMassActionsVisibility();
            updateAllCheckboxStates();
        }

        function selectFolderParts(checkbox) {
            const folder = checkbox.closest('.parts-group');
            if (!folder) return;
            const checkboxes = folder.querySelectorAll('.part-checkbox');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
            updateMassActionsVisibility();
            updateAllCheckboxStates();
        }

        function updateAllCheckboxStates() {
            const selectAllCheckbox = document.getElementById('select-all-parts');
            const allPartCheckboxes = document.querySelectorAll('.part-checkbox');
            const checkedCount = document.querySelectorAll('.part-checkbox:checked').length;

            if (selectAllCheckbox) {
                selectAllCheckbox.checked = checkedCount === allPartCheckboxes.length && allPartCheckboxes.length > 0;
                selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < allPartCheckboxes.length;
            }
            updateFolderCheckboxes();
        }

        function updateFolderCheckboxes() {
            document.querySelectorAll('.parts-group').forEach(folder => {
                const folderCheckbox = folder.querySelector('.folder-checkbox');
                if (!folderCheckbox) return;
                const partCheckboxes = folder.querySelectorAll('.part-checkbox');
                const checkedCount = folder.querySelectorAll('.part-checkbox:checked').length;
                folderCheckbox.checked = checkedCount === partCheckboxes.length && partCheckboxes.length > 0;
                folderCheckbox.indeterminate = checkedCount > 0 && checkedCount < partCheckboxes.length;
            });
        }

        partCheckboxes.forEach(cb => {
            cb.addEventListener('change', function() {
                updateMassActionsVisibility();
                updateAllCheckboxStates();
            });
        });

        if (massDeleteBtn) {
            massDeleteBtn.addEventListener('click', async function() {
                const ids = getSelectedPartIds();
                if (ids.length === 0) return;

                if (!await showConfirm(`Delete ${ids.length} selected parts? This cannot be undone.`)) {
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'delete_parts');
                ids.forEach(id => formData.append('ids[]', id));

                try {
                    const response = await fetch('/actions/mass-action', { method: 'POST', body: formData });
                    const result = await response.json();

                    if (result.success) {
                        location.reload();
                    } else {
                        showToast('Failed: ' + (result.error || 'Unknown error'), 'error');
                    }
                } catch (err) {
                    console.error('Mass delete error:', err);
                    showToast('Failed to delete parts', 'error');
                }
            });
        }

        // Mass convert to 3MF
        const massConvertBtn = document.getElementById('mass-convert-3mf');
        if (massConvertBtn) {
            massConvertBtn.addEventListener('click', async function() {
                const ids = getSelectedPartIds();
                if (ids.length === 0) return;

                // Filter to only STL parts
                const stlParts = ids.filter(id => {
                    const partItem = document.querySelector(`.part-item[data-part-id="${id}"]`);
                    return partItem && partItem.dataset.partType === 'stl';
                });

                if (stlParts.length === 0) {
                    showToast('No STL files selected. Only STL files can be converted to 3MF.', 'error');
                    return;
                }

                if (!await showConfirm(`Convert ${stlParts.length} STL file(s) to 3MF? This will replace the original files.`)) {
                    return;
                }

                massConvertBtn.disabled = true;
                massConvertBtn.textContent = 'Queuing...';

                try {
                    const formData = new FormData();
                    formData.append('action', 'batch');
                    stlParts.forEach(id => formData.append('part_ids[]', id));

                    const response = await fetch('/actions/convert-part', { method: 'POST', body: formData });
                    const result = await response.json();

                    if (result.success && result.queued > 0) {
                        massConvertBtn.textContent = `Queued ${result.queued}`;
                        if (typeof refreshQueueStatus === 'function') refreshQueueStatus();
                    } else {
                        showToast('Failed to queue conversions', 'error');
                        massConvertBtn.textContent = 'Convert to 3MF';
                        massConvertBtn.disabled = false;
                    }
                } catch (err) {
                    showToast('Failed to queue conversions', 'error');
                    massConvertBtn.textContent = 'Convert to 3MF';
                    massConvertBtn.disabled = false;
                }
            });
        }

        // Update print type for a part
        async function updatePrintType(selectEl) {
            const partId = selectEl.dataset.partId;
            const printType = selectEl.value;
            const formData = new FormData();
            formData.append('part_id', partId);
            formData.append('print_type', printType);
            formData.append('csrf_token', '<?= Csrf::getToken() ?>');
            try {
                const resp = await fetch('<?= route('actions.update.part') ?>', { method: 'POST', body: formData });
                const data = await resp.json();
                if (!data.success) {
                    showToast(data.error || 'Failed to update print type', 'error');
                    selectEl.value = selectEl.dataset.prev || '';
                }
                selectEl.dataset.prev = selectEl.value;
            } catch (e) {
                showToast('Failed to update print type', 'error');
            }
        }

        async function updateFolderPrintType(selectEl, folderName) {
            const printType = selectEl.value;
            const label = printType ? printType.toUpperCase() : 'none';
            if (!await showConfirm('Set print type to ' + label + ' for all parts in "' + folderName + '"?')) {
                selectEl.value = '';
                return;
            }

            const formData = new FormData();
            formData.append('action', 'set_print_type');
            formData.append('model_id', <?= $model['id'] ?>);
            formData.append('folder_name', folderName);
            formData.append('print_type', printType);
            formData.append('csrf_token', '<?= Csrf::getToken() ?>');

            try {
                const resp = await fetch('/actions/part-folders', { method: 'POST', body: formData });
                const data = await resp.json();
                if (data.success) {
                    // Update all part dropdowns in this folder
                    const folder = selectEl.closest('.parts-group');
                    if (folder) {
                        folder.querySelectorAll('.print-type-select:not(.folder-print-type)').forEach(sel => {
                            sel.value = printType;
                            sel.dataset.prev = printType;
                        });
                    }
                    selectEl.value = '';
                } else {
                    showToast(data.error || 'Failed to update print type', 'error');
                    selectEl.value = '';
                }
            } catch (e) {
                showToast('Failed to update folder print type', 'error');
                selectEl.value = '';
            }
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
                    const response = await fetch('/actions/add-part', {
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
                    showToast(`Uploaded ${successCount} files. ${errorCount} files failed.`, 'error');
                }
                location.reload();
            } else if (errorCount > 0) {
                showToast(`Upload failed. ${errorCount} files could not be uploaded.`, 'error');
            }
        }

        // Folder management
        function showMoreParts(btn) {
            var list = btn.closest('.parts-list');
            list.querySelectorAll('.part-hidden').forEach(function(el) {
                el.classList.remove('part-hidden');
            });
            btn.remove();
        }

        function toggleFolder(groupEl) {
            groupEl.classList.toggle('collapsed');
            const folder = groupEl.dataset.folder;
            const key = 'model_<?= $model['id'] ?>_folder_' + folder;
            sessionStorage.setItem(key, groupEl.classList.contains('collapsed') ? '1' : '0');
            updateCollapseAllToggle();
        }

        function toggleCollapseAllGroups(toggleEl) {
            const groups = document.querySelectorAll('.parts-group[data-folder]');
            const allCollapsed = Array.from(groups).every(g => g.classList.contains('collapsed'));
            groups.forEach(group => {
                if (allCollapsed) {
                    group.classList.remove('collapsed');
                } else {
                    group.classList.add('collapsed');
                }
                const folder = group.dataset.folder;
                const key = 'model_<?= $model['id'] ?>_folder_' + folder;
                sessionStorage.setItem(key, allCollapsed ? '0' : '1');
            });
            updateCollapseAllToggle();
        }

        function updateCollapseAllToggle() {
            const toggle = document.querySelector('.collapse-all-toggle');
            if (!toggle) return;
            const groups = document.querySelectorAll('.parts-group[data-folder]');
            const allCollapsed = Array.from(groups).every(g => g.classList.contains('collapsed'));
            toggle.classList.toggle('all-collapsed', allCollapsed);
        }

        // Restore collapsed folder states on page load
        document.querySelectorAll('.parts-group[data-folder]').forEach(group => {
            const folder = group.dataset.folder;
            const key = 'model_<?= $model['id'] ?>_folder_' + folder;
            if (sessionStorage.getItem(key) === '1') {
                group.classList.add('collapsed');
            }
        });
        updateCollapseAllToggle();

        function showCreateFolderModal() {
            document.getElementById('create-folder-modal').style.display = 'flex';
            document.getElementById('new-folder-name').focus();
        }

        function closeCreateFolderModal() {
            document.getElementById('create-folder-modal').style.display = 'none';
            document.getElementById('new-folder-name').value = '';
        }

        document.getElementById('create-folder-modal')?.addEventListener('click', function(e) {
            if (e.target === this) closeCreateFolderModal();
        });

        async function submitCreateFolder(e) {
            e.preventDefault();
            const name = document.getElementById('new-folder-name').value.trim();
            if (!name) return;

            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('model_id', <?= $model['id'] ?>);
            formData.append('folder_name', name);

            try {
                const response = await fetch('/actions/part-folders', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    location.reload();
                } else {
                    showToast('Failed: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Create folder error:', err);
                showToast('Failed to create folder', 'error');
            }
        }

        async function renameFolder(oldName) {
            const newName = await showPrompt('Rename folder:', oldName);
            if (!newName || newName.trim() === '' || newName.trim() === oldName) return;

            const formData = new FormData();
            formData.append('action', 'rename');
            formData.append('model_id', <?= $model['id'] ?>);
            formData.append('old_folder', oldName);
            formData.append('new_folder', newName.trim());

            try {
                const response = await fetch('/actions/part-folders', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    location.reload();
                } else {
                    showToast('Failed: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Rename folder error:', err);
                showToast('Failed to rename folder', 'error');
            }
        }

        async function deleteFolder(folderName) {
            if (!await showConfirm('Delete folder "' + folderName + '"? Parts will be moved to root.')) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('model_id', <?= $model['id'] ?>);
            formData.append('folder_name', folderName);

            try {
                const response = await fetch('/actions/part-folders', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    location.reload();
                } else {
                    showToast('Failed: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Delete folder error:', err);
                showToast('Failed to delete folder', 'error');
            }
        }

        let movingPartIds = [];

        function showMoveFolderModal(partIds) {
            movingPartIds = partIds;
            document.getElementById('move-part-ids').value = partIds.join(',');
            document.getElementById('move-folder-modal').style.display = 'flex';
            // Uncheck all radios
            document.querySelectorAll('#move-folder-list input[type="radio"]').forEach(r => r.checked = false);
        }

        function closeMoveFolderModal() {
            document.getElementById('move-folder-modal').style.display = 'none';
            movingPartIds = [];
        }

        document.getElementById('move-folder-modal')?.addEventListener('click', function(e) {
            if (e.target === this) closeMoveFolderModal();
        });

        async function submitMoveToFolder() {
            const selected = document.querySelector('#move-folder-list input[type="radio"]:checked');
            if (!selected) {
                showToast('Please select a folder', 'error');
                return;
            }

            const targetFolder = selected.value;
            const formData = new FormData();
            formData.append('action', 'move');
            formData.append('model_id', <?= $model['id'] ?>);
            formData.append('target_folder', targetFolder);
            movingPartIds.forEach(id => formData.append('part_ids[]', id));

            try {
                const response = await fetch('/actions/part-folders', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    location.reload();
                } else {
                    showToast('Failed: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Move to folder error:', err);
                showToast('Failed to move parts', 'error');
            }
        }

        // Version upload management
        function showUploadVersionModal() {
            document.getElementById('upload-version-modal').style.display = 'flex';
        }

        function closeUploadVersionModal() {
            document.getElementById('upload-version-modal').style.display = 'none';
            document.getElementById('upload-version-form').reset();
        }

        document.getElementById('upload-version-modal')?.addEventListener('click', function(e) {
            if (e.target === this) closeUploadVersionModal();
        });

        async function submitUploadVersion(e) {
            e.preventDefault();
            const fileInput = document.getElementById('version-file');
            const changelog = document.getElementById('version-changelog').value.trim();
            const submitBtn = document.getElementById('version-submit-btn');

            if (!fileInput.files.length) {
                showToast('Please select a file', 'error');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Uploading...';

            const formData = new FormData();
            formData.append('model_id', <?= $model['id'] ?>);
            formData.append('version_file', fileInput.files[0]);
            formData.append('changelog', changelog);

            try {
                const response = await fetch('/actions/upload-version', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    closeUploadVersionModal();
                    location.reload();
                } else {
                    showToast('Failed: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Upload version error:', err);
                showToast('Failed to upload version', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Upload Version';
            }
        }

        // Position fixed dropdown menu relative to toggle button
        function positionDropdownMenu(dropdown) {
            const menu = dropdown.querySelector('.dropdown-menu');
            const btn = dropdown.querySelector('.dropdown-toggle');
            if (!menu || !btn) return;

            const btnRect = btn.getBoundingClientRect();

            // Check if dropdown is inside part-actions (needs fixed positioning)
            const isPartDropdown = dropdown.classList.contains('part-actions-dropdown');

            if (isPartDropdown) {
                // Position below the button, aligned to the right
                const right = window.innerWidth - btnRect.right;
                menu.style.top = (btnRect.bottom + 4) + 'px';
                menu.style.right = right + 'px';
                menu.style.left = 'auto';
            }
        }

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
                    positionDropdownMenu(dropdown);

                    // Restore calculated data for part dropdowns
                    if (dropdown.classList.contains('part-actions-dropdown')) {
                        const partItem = dropdown.closest('.part-item');
                        if (partItem) {
                            const partId = partItem.querySelector('.part-checkbox')?.value ||
                                          partItem.dataset.partId;
                            if (partId) {
                                restorePartCalculatedData(partId, dropdown);
                            }
                        }
                    }
                }
            });
        });

        // Close dropdowns when clicking outside (but not when interacting with inline controls)
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.dropdown')) {
                document.querySelectorAll('.dropdown.open').forEach(d => {
                    d.classList.remove('open');
                });
            } else if (e.target.closest('.dropdown-item') && !e.target.closest('.dropdown-item-inline') && !e.target.closest('select')) {
                // Close dropdown on regular item click (but not inline controls)
                document.querySelectorAll('.dropdown.open').forEach(d => {
                    d.classList.remove('open');
                });
            }
        });

        // Close fixed-position dropdowns on scroll (since they won't move with the page)
        window.addEventListener('scroll', function() {
            document.querySelectorAll('.part-actions-dropdown.open').forEach(d => {
                d.classList.remove('open');
            });
        }, { passive: true });

        // Favorite toggle
        async function toggleFavorite(modelId, btn) {
            try {
                const response = await fetch('/actions/favorite', {
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

        // Storage for calculated part data (persists during page session)
        const partCalculatedData = {};

        // Per-part actions
        async function calculatePartDimensions(partId, linkEl) {
            const originalText = 'Calculate Dimensions';
            linkEl.textContent = 'Calculating...';
            try {
                const formData = new FormData();
                formData.append('model_id', partId);
                const response = await fetch('/actions/calculate-dimensions', { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success && data.formatted) {
                    // Store the calculated value
                    if (!partCalculatedData[partId]) partCalculatedData[partId] = {};
                    partCalculatedData[partId].dimensions = data.formatted;
                    linkEl.textContent = 'Dimensions: ' + data.formatted;
                } else {
                    showToast('Failed: ' + (data.error || 'Unknown error'), 'error');
                    linkEl.textContent = originalText;
                }
            } catch (err) {
                console.error('Part dimensions error:', err);
                showToast('Failed to calculate dimensions', 'error');
                linkEl.textContent = originalText;
            }
        }

        async function calculatePartVolume(partId, linkEl) {
            const originalText = 'Calculate Volume';
            linkEl.textContent = 'Calculating...';
            try {
                const formData = new FormData();
                formData.append('model_id', partId);
                const response = await fetch('/actions/calculate-volume', { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success && data.volume_cm3) {
                    // Store the calculated value
                    if (!partCalculatedData[partId]) partCalculatedData[partId] = {};
                    partCalculatedData[partId].volume = data.volume_cm3;
                    partCalculatedData[partId].costEstimate = data.cost_estimate;
                    let volumeText = 'Volume: ' + data.volume_cm3.toFixed(1) + ' cm\u00B3';
                    if (data.cost_estimate) {
                        volumeText += ' (~$' + data.cost_estimate.estimated_cost.toFixed(2) + ')';
                    }
                    linkEl.textContent = volumeText;
                } else {
                    showToast('Failed: ' + (data.error || 'Unknown error'), 'error');
                    linkEl.textContent = originalText;
                }
            } catch (err) {
                console.error('Part volume error:', err);
                showToast('Failed to calculate volume', 'error');
                linkEl.textContent = originalText;
            }
        }

        // Restore calculated data when dropdown opens
        function restorePartCalculatedData(partId, dropdown) {
            const data = partCalculatedData[partId];
            if (!data) return;

            const dimsLink = dropdown.querySelector('[onclick*="calculatePartDimensions"]');
            const volLink = dropdown.querySelector('[onclick*="calculatePartVolume"]');

            if (dimsLink && data.dimensions) {
                dimsLink.textContent = 'Dimensions: ' + data.dimensions;
            }
            if (volLink && data.volume) {
                let volumeText = 'Volume: ' + data.volume.toFixed(1) + ' cm\u00B3';
                if (data.costEstimate) {
                    volumeText += ' (~$' + data.costEstimate.estimated_cost.toFixed(2) + ')';
                }
                volLink.textContent = volumeText;
            }
        }

        async function analyzePartMesh(partId, linkEl) {
            const originalText = linkEl.textContent;
            linkEl.textContent = 'Analyzing...';
            try {
                const formData = new FormData();
                formData.append('action', 'analyze');
                formData.append('model_id', partId);
                const response = await fetch('/actions/mesh-repair', { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success) {
                    if (data.analysis && data.analysis.is_manifold) {
                        linkEl.textContent = 'Mesh OK';
                        linkEl.style.color = 'var(--success-color, #10b981)';
                    } else if (data.analysis) {
                        const issues = data.analysis.issues ? data.analysis.issues.length : 0;
                        linkEl.textContent = issues + ' issue(s)';
                        linkEl.style.color = 'var(--warning-color, #f59e0b)';
                    } else {
                        linkEl.textContent = 'Analyzed';
                    }
                } else {
                    showToast('Failed: ' + (data.error || 'Unknown error'), 'error');
                    linkEl.textContent = originalText;
                }
            } catch (err) {
                console.error('Part mesh analysis error:', err);
                showToast('Failed to analyze mesh', 'error');
                linkEl.textContent = originalText;
            }
        }

        // Archive toggle
        async function toggleArchive(modelId, archive) {
            try {
                const response = await fetch('/actions/update-model', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'model_id=' + modelId + '&is_archived=' + (archive ? '1' : '0')
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    showToast('Failed to update: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Failed to toggle archive:', err);
                showToast('Failed to update model', 'error');
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
                            <span class="tag-color-dot" style="background-color: var(--color-primary);"></span>
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
                const response = await fetch('/actions/tag', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=add&model_id=<?= $model['id'] ?>&tag_name=' + encodeURIComponent(tagName)
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    showToast('Failed to add tag: ' + (data.error || 'Unknown error'), 'error');
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
                const response = await fetch('/actions/tag', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=remove&model_id=' + modelId + '&tag_id=' + tagId
                });
                const data = await response.json();
                if (data.success) {
                    element.remove();
                } else {
                    showToast('Failed to remove tag: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Failed to remove tag:', err);
            }
        }

        // Share Modal Functions
        <?php if (isLoggedIn()): ?>
        function openShareModal() {
            document.getElementById('share-modal').style.display = 'flex';
            loadShareLinks();
        }

        function closeShareModal() {
            document.getElementById('share-modal').style.display = 'none';
        }

        document.getElementById('share-modal')?.addEventListener('click', function(e) {
            if (e.target === this) closeShareModal();
        });

        document.getElementById('share-link-form')?.addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('model_id', <?= $model['id'] ?>);
            formData.append('expires_in', document.getElementById('share-expires').value);
            formData.append('max_downloads', document.getElementById('share-max-downloads').value);
            formData.append('password', document.getElementById('share-password').value);

            try {
                const response = await fetch('/actions/share-link', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    // Clear form
                    this.reset();
                    // Reload links
                    loadShareLinks();
                } else {
                    showToast('Failed to create share link: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Error creating share link:', err);
                showToast('Failed to create share link', 'error');
            }
        });

        async function loadShareLinks() {
            const container = document.getElementById('share-links-list');

            try {
                const response = await fetch('/actions/share-link?action=list&model_id=<?= $model['id'] ?>');
                const result = await response.json();

                if (!result.success) {
                    container.innerHTML = '<p class="text-muted">Failed to load share links</p>';
                    return;
                }

                if (result.links.length === 0) {
                    container.innerHTML = '<p class="text-muted">No active share links</p>';
                    return;
                }

                container.innerHTML = result.links.map(link => `
                    <div class="share-link-item ${link.is_expired ? 'expired' : ''}">
                        <div class="share-link-info">
                            <div class="share-link-url">
                                <input type="text" readonly value="${link.share_url}" class="share-url-input" onclick="this.select()">
                                <button type="button" class="btn btn-small" onclick="copyShareUrl(this.previousElementSibling)" title="Copy URL">Copy</button>
                            </div>
                            <div class="share-link-meta">
                                ${link.has_password ? '<span class="share-badge">Password</span>' : ''}
                                ${link.expires_at ? `<span class="share-meta-item">${link.is_expired ? 'Expired' : 'Expires: ' + new Date(link.expires_at).toLocaleDateString()}</span>` : '<span class="share-meta-item">Never expires</span>'}
                                ${link.max_downloads ? `<span class="share-meta-item">Downloads: ${link.download_count}/${link.max_downloads}</span>` : `<span class="share-meta-item">${link.download_count} downloads</span>`}
                            </div>
                        </div>
                        <button type="button" class="btn btn-danger btn-small" onclick="deleteShareLink(${link.id})">Delete</button>
                    </div>
                `).join('');
            } catch (err) {
                console.error('Error loading share links:', err);
                container.innerHTML = '<p class="text-muted">Failed to load share links</p>';
            }
        }

        function copyShareUrl(input) {
            input.select();
            navigator.clipboard.writeText(input.value).then(() => {
                showToast('Link copied to clipboard', 'success');
                const btn = input.nextElementSibling;
                const originalText = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(() => btn.textContent = originalText, 1500);
            }).catch(() => {
                document.execCommand('copy');
                showToast('Link copied to clipboard', 'success');
            });
        }

        async function deleteShareLink(linkId) {
            if (!await showConfirm('Delete this share link? Anyone with this link will no longer be able to access the model.')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('link_id', linkId);

            try {
                const response = await fetch('/actions/share-link', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    loadShareLinks();
                } else {
                    showToast('Failed to delete share link: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Error deleting share link:', err);
                showToast('Failed to delete share link', 'error');
            }
        }
        <?php endif; ?>

        // External Links
        function toggleAddLinkForm() {
            const form = document.getElementById('add-link-form');
            const btn = document.getElementById('add-link-toggle');
            if (form.style.display === 'none') {
                form.style.display = 'block';
                btn.style.display = 'none';
                document.getElementById('link-title').focus();
            } else {
                form.style.display = 'none';
                btn.style.display = '';
                document.getElementById('link-title').value = '';
                document.getElementById('link-url').value = '';
                document.getElementById('link-type').value = 'other';
            }
        }

        async function addModelLink() {
            const title = document.getElementById('link-title').value.trim();
            const url = document.getElementById('link-url').value.trim();
            const linkType = document.getElementById('link-type').value;

            if (!title || !url) {
                showToast('Title and URL are required', 'error');
                return;
            }

            try {
                const response = await fetch('/actions/model-links', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'add',
                        model_id: <?= $model['id'] ?>,
                        title: title,
                        url: url,
                        link_type: linkType
                    })
                });
                const result = await response.json();

                if (result.success) {
                    const list = document.getElementById('model-links-list');
                    const empty = document.getElementById('model-links-empty');
                    if (empty) empty.remove();

                    const link = result.link;
                    const item = document.createElement('div');
                    item.className = 'model-link-item';
                    item.dataset.linkId = link.id;
                    item.innerHTML =
                        '<span class="model-link-type type-' + escapeHtml(link.link_type) + '">' + escapeHtml(link.link_type) + '</span>' +
                        '<a href="' + escapeHtml(link.url) + '" target="_blank" rel="noopener noreferrer" class="model-link-title">' + escapeHtml(link.title) + '</a>' +
                        '<button type="button" class="model-link-delete" aria-label="Remove link" onclick="deleteModelLink(' + link.id + ')" title="Remove link">&times;</button>';
                    list.appendChild(item);

                    toggleAddLinkForm();
                } else {
                    showToast(result.error, 'error');
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function deleteModelLink(linkId) {
            if (!await showConfirm('Remove this link?')) return;

            try {
                const response = await fetch('/actions/model-links', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', link_id: linkId })
                });
                const result = await response.json();

                if (result.success) {
                    const item = document.querySelector('[data-link-id="' + linkId + '"]');
                    if (item) item.remove();

                    // Show empty state if no links remain
                    const list = document.getElementById('model-links-list');
                    if (!list.querySelector('.model-link-item')) {
                        const p = document.createElement('p');
                        p.className = 'model-links-empty';
                        p.id = 'model-links-empty';
                        p.textContent = 'No external links yet.';
                        list.appendChild(p);
                    }
                } else {
                    showToast(result.error, 'error');
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Attachments - Lightbox
        var lightboxImages = [];
        var lightboxIndex = 0;
        var lbZoom = 1, lbPanX = 0, lbPanY = 0, lbDragging = false, lbDragStart = {};

        function collectLightboxImages() {
            lightboxImages = [];
            var grid = document.getElementById('attachment-images');
            if (!grid) return;
            var imgs = grid.querySelectorAll('.attachment-image img');
            imgs.forEach(function(img) {
                lightboxImages.push({ src: img.src, caption: img.alt });
            });
        }

        function lightboxResetZoom() {
            lbZoom = 1; lbPanX = 0; lbPanY = 0;
            var lightbox = document.getElementById('image-lightbox');
            if (lightbox) {
                var img = lightbox.querySelector('.lightbox-content img');
                if (img) { img.style.transform = ''; img.style.cursor = ''; }
            }
        }

        function lightboxApplyTransform() {
            var lightbox = document.getElementById('image-lightbox');
            if (!lightbox) return;
            var img = lightbox.querySelector('.lightbox-content img');
            if (!img) return;
            if (lbZoom <= 1) {
                img.style.transform = '';
                img.style.cursor = '';
            } else {
                img.style.transform = 'scale(' + lbZoom + ') translate(' + (lbPanX / lbZoom) + 'px, ' + (lbPanY / lbZoom) + 'px)';
                img.style.cursor = 'grab';
            }
        }

        function openImageLightbox(src, caption) {
            collectLightboxImages();
            lightboxIndex = lightboxImages.findIndex(function(img) { return img.src === src || img.src.endsWith(src); });
            if (lightboxIndex < 0) lightboxIndex = 0;
            lbZoom = 1; lbPanX = 0; lbPanY = 0;

            var existing = document.getElementById('image-lightbox');
            if (existing) existing.remove();

            var hasMultiple = lightboxImages.length > 1;
            var lightbox = document.createElement('div');
            lightbox.id = 'image-lightbox';
            lightbox.className = 'lightbox-overlay';
            lightbox.setAttribute('role', 'dialog');
            lightbox.setAttribute('aria-modal', 'true');
            lightbox.innerHTML =
                '<div class="lightbox-content">' +
                    '<button type="button" class="lightbox-close" aria-label="Close" onclick="closeLightbox()">&times;</button>' +
                    (hasMultiple ? '<button type="button" class="lightbox-nav lightbox-prev" aria-label="Previous image" onclick="lightboxNav(-1)">&#8249;</button>' : '') +
                    '<img src="' + escapeHtml(src) + '" alt="' + escapeHtml(caption) + '" draggable="false">' +
                    (hasMultiple ? '<button type="button" class="lightbox-nav lightbox-next" aria-label="Next image" onclick="lightboxNav(1)">&#8250;</button>' : '') +
                    '<div class="lightbox-caption">' + escapeHtml(caption) +
                    (hasMultiple ? ' <span class="lightbox-counter">' + (lightboxIndex + 1) + ' / ' + lightboxImages.length + '</span>' : '') +
                    '</div>' +
                '</div>';
            document.body.appendChild(lightbox);
            lightbox.style.display = 'flex';

            var lbImg = lightbox.querySelector('.lightbox-content img');

            // Scroll to zoom
            lightbox.addEventListener('wheel', function(e) {
                e.preventDefault();
                var delta = e.deltaY > 0 ? -0.2 : 0.2;
                lbZoom = Math.min(5, Math.max(1, lbZoom + delta));
                if (lbZoom <= 1) { lbPanX = 0; lbPanY = 0; }
                lightboxApplyTransform();
            }, { passive: false });

            // Double-click to toggle zoom
            lbImg.addEventListener('dblclick', function(e) {
                e.stopPropagation();
                if (lbZoom > 1) { lightboxResetZoom(); } else { lbZoom = 2.5; lightboxApplyTransform(); }
            });

            // Drag to pan
            lbImg.addEventListener('mousedown', function(e) {
                if (lbZoom <= 1) return;
                e.preventDefault();
                lbDragging = true;
                lbDragStart = { x: e.clientX - lbPanX, y: e.clientY - lbPanY };
                lbImg.style.cursor = 'grabbing';
            });
            document.addEventListener('mousemove', lightboxMouseMove);
            document.addEventListener('mouseup', lightboxMouseUp);

            // Touch zoom/pan
            var lastTouchDist = 0;
            lightbox.addEventListener('touchstart', function(e) {
                if (e.touches.length === 2) {
                    lastTouchDist = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
                } else if (e.touches.length === 1 && lbZoom > 1) {
                    lbDragging = true;
                    lbDragStart = { x: e.touches[0].clientX - lbPanX, y: e.touches[0].clientY - lbPanY };
                }
            }, { passive: true });
            lightbox.addEventListener('touchmove', function(e) {
                if (e.touches.length === 2) {
                    e.preventDefault();
                    var dist = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
                    if (lastTouchDist > 0) {
                        lbZoom = Math.min(5, Math.max(1, lbZoom * (dist / lastTouchDist)));
                        if (lbZoom <= 1) { lbPanX = 0; lbPanY = 0; }
                        lightboxApplyTransform();
                    }
                    lastTouchDist = dist;
                } else if (e.touches.length === 1 && lbDragging) {
                    e.preventDefault();
                    lbPanX = e.touches[0].clientX - lbDragStart.x;
                    lbPanY = e.touches[0].clientY - lbDragStart.y;
                    lightboxApplyTransform();
                }
            }, { passive: false });
            lightbox.addEventListener('touchend', function() { lbDragging = false; lastTouchDist = 0; });

            // Close on background click (only if not zoomed)
            lightbox.addEventListener('click', function(e) {
                if (e.target === this && lbZoom <= 1) closeLightbox();
            });

            document.addEventListener('keydown', lightboxKeyHandler);
        }

        function lightboxMouseMove(e) {
            if (!lbDragging) return;
            lbPanX = e.clientX - lbDragStart.x;
            lbPanY = e.clientY - lbDragStart.y;
            lightboxApplyTransform();
        }

        function lightboxMouseUp() {
            if (lbDragging) {
                lbDragging = false;
                var lightbox = document.getElementById('image-lightbox');
                if (lightbox) {
                    var img = lightbox.querySelector('.lightbox-content img');
                    if (img && lbZoom > 1) img.style.cursor = 'grab';
                }
            }
        }

        function lightboxNav(dir) {
            if (lightboxImages.length < 2) return;
            lightboxResetZoom();
            lightboxIndex = (lightboxIndex + dir + lightboxImages.length) % lightboxImages.length;
            var img = lightboxImages[lightboxIndex];
            var lightbox = document.getElementById('image-lightbox');
            if (!lightbox) return;
            lightbox.querySelector('.lightbox-content img').src = img.src;
            lightbox.querySelector('.lightbox-content img').alt = img.caption;
            var caption = lightbox.querySelector('.lightbox-caption');
            caption.innerHTML = escapeHtml(img.caption) + ' <span class="lightbox-counter">' + (lightboxIndex + 1) + ' / ' + lightboxImages.length + '</span>';
        }

        function closeLightbox() {
            var lightbox = document.getElementById('image-lightbox');
            if (lightbox) lightbox.remove();
            document.removeEventListener('keydown', lightboxKeyHandler);
            document.removeEventListener('mousemove', lightboxMouseMove);
            document.removeEventListener('mouseup', lightboxMouseUp);
            lbZoom = 1; lbPanX = 0; lbPanY = 0;
        }

        function lightboxKeyHandler(e) {
            if (e.key === 'Escape') closeLightbox();
            if (e.key === 'ArrowLeft') lightboxNav(-1);
            if (e.key === 'ArrowRight') lightboxNav(1);
        }

        <?php if (canEdit()): ?>
        // Attachments - Upload
        async function uploadAttachments(files) {
            if (!files.length) return;

            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/markdown'];
            const allowedExts = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.pdf', '.txt', '.md'];

            for (const file of files) {
                const ext = '.' + file.name.split('.').pop().toLowerCase();
                if (!allowedTypes.includes(file.type) && !allowedExts.includes(ext)) {
                    showToast(`Invalid file type: ${file.name}. Allowed: Images, PDFs, TXT, MD`, 'error');
                    continue;
                }

                const formData = new FormData();
                formData.append('action', 'upload');
                formData.append('model_id', <?= $modelId ?>);
                formData.append('attachment', file);

                try {
                    const response = await fetch('/actions/attachments', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();

                    if (result.success) {
                        // Remove empty state if present
                        const empty = document.getElementById('attachments-empty');
                        if (empty) empty.remove();

                        // Add new attachment to appropriate section
                        if (result.file_type === 'image') {
                            addImageAttachment(result);
                        } else {
                            addDocumentAttachment(result);
                        }
                    } else {
                        showToast(`Error uploading ${file.name}: ${result.error}`, 'error');
                    }
                } catch (err) {
                    showToast(`Error uploading ${file.name}: ${err.message}`, 'error');
                }
            }

            // Clear the file input
            document.getElementById('attachment-file-input').value = '';
        }

        function addImageAttachment(att) {
            let grid = document.getElementById('attachment-images');

            // Create images section if it doesn't exist
            if (!grid) {
                const section = document.createElement('div');
                section.className = 'attachment-section';
                section.innerHTML = '<h4>Images</h4><div class="attachment-grid" id="attachment-images"></div>';

                const attachmentsDiv = document.querySelector('.model-attachments');
                const uploadDiv = attachmentsDiv.querySelector('.attachment-upload');
                attachmentsDiv.insertBefore(section, uploadDiv);
                grid = document.getElementById('attachment-images');
            }

            const item = document.createElement('div');
            item.className = 'attachment-image';
            item.dataset.attachmentId = att.attachment_id;

            const img = document.createElement('img');
            img.src = '/assets/' + att.file_path;
            img.alt = att.original_filename;
            img.loading = 'lazy';
            img.onclick = function() {
                openImageLightbox('/assets/' + att.file_path, att.original_filename);
            };

            const thumbBtn = document.createElement('button');
            thumbBtn.type = 'button';
            thumbBtn.className = 'attachment-set-thumb';
            thumbBtn.title = 'Set as model thumbnail';
            thumbBtn.innerHTML = '&#128247;';
            thumbBtn.onclick = function() { setAttachmentAsThumbnail(att.attachment_id); };

            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'attachment-delete';
            deleteBtn.title = 'Delete';
            deleteBtn.textContent = '×';
            deleteBtn.onclick = function() { deleteAttachment(att.attachment_id); };

            item.appendChild(img);
            item.appendChild(thumbBtn);
            item.appendChild(deleteBtn);
            grid.appendChild(item);
        }

        function addDocumentAttachment(att) {
            let list = document.getElementById('attachment-documents');

            // Create documents section if it doesn't exist
            if (!list) {
                const section = document.createElement('div');
                section.className = 'attachment-section';
                section.innerHTML = '<h4>Documents</h4><div class="attachment-documents" id="attachment-documents"></div>';

                const attachmentsDiv = document.querySelector('.model-attachments');
                const uploadDiv = attachmentsDiv.querySelector('.attachment-upload');
                attachmentsDiv.insertBefore(section, uploadDiv);
                list = document.getElementById('attachment-documents');
            }

            const item = document.createElement('div');
            item.className = 'attachment-document';
            item.dataset.attachmentId = att.attachment_id;

            const badge = document.createElement('span');
            badge.className = 'file-type-badge';
            const docExt = att.original_filename.split('.').pop().toLowerCase();
            badge.textContent = '.' + docExt;

            const link = document.createElement('a');
            link.href = '/assets/' + att.file_path;
            link.target = '_blank';
            link.rel = 'noopener';
            link.className = 'attachment-doc-name';
            link.textContent = att.original_filename;

            const sizeSpan = document.createElement('span');
            sizeSpan.className = 'attachment-doc-size';
            sizeSpan.textContent = formatFileSize(att.file_size);

            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'attachment-delete';
            deleteBtn.title = 'Delete';
            deleteBtn.textContent = '×';
            deleteBtn.onclick = function() { deleteAttachment(att.attachment_id); };

            item.appendChild(badge);
            item.appendChild(link);
            item.appendChild(sizeSpan);
            item.appendChild(deleteBtn);
            list.appendChild(item);
        }

        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        }

        async function setAttachmentAsThumbnail(attachmentId) {
            const formData = new FormData();
            formData.append('action', 'set_from_attachment');
            formData.append('model_id', <?= $modelId ?>);
            formData.append('attachment_id', attachmentId);
            formData.append('csrf_token', '<?= Csrf::getToken() ?>');

            try {
                const response = await fetch('/actions/thumbnail', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    // Update the thumbnail display
                    const thumbContainer = document.querySelector('.model-detail-thumbnail');
                    if (thumbContainer) {
                        let img = thumbContainer.querySelector('.model-thumbnail-image');
                        if (!img) {
                            img = document.createElement('img');
                            img.className = 'model-thumbnail-image';
                            img.alt = '<?= htmlspecialchars(addslashes($model['name'])) ?>';
                            thumbContainer.prepend(img);
                        }
                        img.src = '/assets/' + result.thumbnail_path + '?t=' + Date.now();
                    }
                } else {
                    showToast(result.error || 'Failed to set thumbnail', 'error');
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function deleteAttachment(attachmentId) {
            if (!await showConfirm('Delete this attachment?')) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('attachment_id', attachmentId);

            try {
                const response = await fetch('/actions/attachments', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    const item = document.querySelector('[data-attachment-id="' + attachmentId + '"]');
                    if (item) {
                        const section = item.closest('.attachment-section');
                        item.remove();

                        // Remove section if empty
                        const container = section ? section.querySelector('.attachment-grid, .attachment-documents') : null;
                        if (container && container.children.length === 0) {
                            section.remove();
                        }

                        // Show empty state if no attachments remain
                        const imagesGrid = document.getElementById('attachment-images');
                        const docsList = document.getElementById('attachment-documents');
                        const hasImages = imagesGrid && imagesGrid.children.length > 0;
                        const hasDocs = docsList && docsList.children.length > 0;

                        if (!hasImages && !hasDocs) {
                            const uploadDiv = document.querySelector('.attachment-upload');
                            if (uploadDiv && !document.getElementById('attachments-empty')) {
                                const empty = document.createElement('p');
                                empty.className = 'attachments-empty';
                                empty.id = 'attachments-empty';
                                empty.textContent = 'No attachments yet.';
                                uploadDiv.parentNode.insertBefore(empty, uploadDiv);
                            }
                        }
                    }
                } else {
                    showToast(result.error, 'error');
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        }
        <?php endif; ?>

        // Drag and drop reordering
        <?php if (canEdit()): ?>
        function initSortable() {
            document.querySelectorAll('.parts-list').forEach(list => {
                new Sortable(list, {
                    handle: '.drag-handle',
                    animation: 150,
                    ghostClass: 'part-item-ghost',
                    chosenClass: 'part-item-chosen',
                    onEnd: async function(evt) {
                        // Collect all part IDs in new order
                        const partIds = Array.from(list.querySelectorAll('.part-item'))
                            .map(item => item.dataset.partId);

                        const formData = new FormData();
                        formData.append('parent_id', <?= $modelId ?>);
                        partIds.forEach(id => formData.append('part_ids[]', id));

                        try {
                            const response = await fetch('/actions/reorder-parts', {
                                method: 'POST',
                                body: formData
                            });
                            const result = await response.json();
                            if (!result.success) {
                                console.error('Failed to save order:', result.error);
                                location.reload(); // Revert on failure
                            }
                        } catch (err) {
                            console.error('Error saving order:', err);
                            location.reload();
                        }
                    }
                });
            });
        }

        // Load SortableJS and initialize
        (function() {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js';
            script.onload = initSortable;
            document.head.appendChild(script);
        })();

        // Batch rename
        let batchRenamePartIds = [];

        function showBatchRenameModal(partIds) {
            batchRenamePartIds = partIds;
            document.getElementById('rename-pattern').value = '{name}';
            document.getElementById('rename-prefix').value = '';
            document.getElementById('rename-suffix').value = '';
            document.getElementById('batch-rename-modal').style.display = 'flex';
            updateRenamePreview();
        }

        function closeBatchRenameModal() {
            document.getElementById('batch-rename-modal').style.display = 'none';
            batchRenamePartIds = [];
        }

        function updateRenamePreview() {
            const pattern = document.getElementById('rename-pattern').value || '{name}';
            const prefix = document.getElementById('rename-prefix').value;
            const suffix = document.getElementById('rename-suffix').value;
            const previewList = document.getElementById('rename-preview-list');

            // Get part names for preview
            const previews = batchRenamePartIds.slice(0, 3).map((id, idx) => {
                const partEl = document.querySelector(`.part-item[data-part-id="${id}"]`);
                if (!partEl) return null;
                const name = partEl.dataset.partName;
                const ext = partEl.dataset.partType;

                let newName = pattern
                    .replace('{name}', name)
                    .replace('{index}', idx + 1)
                    .replace('{ext}', ext);
                newName = prefix + newName + suffix;

                return { old: name, new: newName };
            }).filter(Boolean);

            previewList.innerHTML = previews.map(p =>
                `<li><span class="old-name">${escapeHtml(p.old)}</span> &rarr; <span class="new-name">${escapeHtml(p.new)}</span></li>`
            ).join('');

            if (batchRenamePartIds.length > 3) {
                previewList.innerHTML += `<li>...and ${batchRenamePartIds.length - 3} more</li>`;
            }
        }

        async function applyBatchRename() {
            const pattern = document.getElementById('rename-pattern').value || '{name}';
            const prefix = document.getElementById('rename-prefix').value;
            const suffix = document.getElementById('rename-suffix').value;

            if (!pattern && !prefix && !suffix) {
                showToast('Please enter a pattern, prefix, or suffix', 'error');
                return;
            }

            try {
                const response = await fetch('/actions/batch-rename', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        parent_id: <?= $modelId ?>,
                        part_ids: batchRenamePartIds,
                        pattern: pattern,
                        prefix: prefix,
                        suffix: suffix
                    })
                });

                const result = await response.json();
                if (result.success) {
                    location.reload();
                } else {
                    showToast(result.error, 'error');
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        }
        <?php endif; ?>
        </script>

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
