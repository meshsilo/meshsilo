<?php
/**
 * Model Version History Page
 *
 * Displays version timeline with comparison and revert capabilities
 */
require_once 'includes/config.php';

$db = getDB();

// Get model ID from URL
$modelId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$modelId) {
    header('Location: ' . route('browse'));
    exit;
}

// Get model details
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id AND parent_id IS NULL');
$stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
$result = $stmt->execute();
$model = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$model) {
    header('Location: ' . route('browse'));
    exit;
}

// Check if user can access versions
$canEdit = false;
if (isLoggedIn()) {
    $user = getCurrentUser();
    $canEdit = ($model['user_id'] == $user['id']) || $user['is_admin'];
}

// Get all versions for this model
$stmt = $db->prepare('
    SELECT mv.*, u.username as created_by_name
    FROM model_versions mv
    LEFT JOIN users u ON mv.created_by = u.id
    WHERE mv.model_id = :model_id
    ORDER BY mv.version_number DESC
');
$stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
$result = $stmt->execute();

$versions = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $versions[] = $row;
}

// Get current version number
$currentVersion = $model['current_version'] ?? 0;
if (empty($versions) && $model['file_path']) {
    // No versions tracked yet, just show current file as v1
    $currentVersion = 1;
}

$pageTitle = 'Version History: ' . $model['name'];
$activePage = 'browse';

// formatBytes is defined in includes/helpers.php

require_once 'includes/header.php';
?>

<div class="container page-model-versions">
    <div class="breadcrumb">
        <a href="<?= route('browse') ?>">Models</a> &raquo;
        <a href="<?= route('model.show', ['id' => $modelId]) ?>"><?= htmlspecialchars($model['name']) ?></a> &raquo;
        <span>Version History</span>
    </div>

    <div class="page-header">
        <h1>Version History</h1>
        <p class="subtitle"><?= htmlspecialchars($model['name']) ?></p>
    </div>

    <?php if (empty($versions)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <p>No version history available yet.</p>
                <?php if ($canEdit): ?>
                <p>Upload a new version of this model to start tracking changes.</p>
                <a href="<?= route('model.show', ['id' => $modelId]) ?>" class="btn btn-primary">Go to Model</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php else: ?>

    <div class="version-controls">
        <?php if ($canEdit): ?>
        <a href="<?= route('model.show', ['id' => $modelId]) ?>#upload-version" class="btn btn-primary">Upload New Version</a>
        <?php endif; ?>
        <span class="version-count"><?= count($versions) ?> version<?= count($versions) !== 1 ? 's' : '' ?></span>
    </div>

    <div class="version-timeline">
        <?php foreach ($versions as $index => $version): ?>
        <div class="version-item <?= $version['version_number'] == $currentVersion ? 'current' : '' ?>">
            <div class="version-marker">
                <span class="version-dot <?= $version['version_number'] == $currentVersion ? 'current' : '' ?>"></span>
                <?php if ($index < count($versions) - 1): ?>
                <span class="version-line"></span>
                <?php endif; ?>
            </div>

            <div class="version-content">
                <div class="version-header">
                    <div class="version-info">
                        <span class="version-number">v<?= $version['version_number'] ?></span>
                        <?php if ($version['version_number'] == $currentVersion): ?>
                        <span class="badge badge-success">Current</span>
                        <?php endif; ?>
                    </div>
                    <div class="version-meta">
                        <time class="version-date" datetime="<?= htmlspecialchars(date('c', strtotime($version['created_at']))) ?>" data-timestamp="<?= htmlspecialchars($version['created_at']) ?>" title="<?= htmlspecialchars($version['created_at']) ?>">
                            <?= date('M j, Y', strtotime($version['created_at'])) ?>
                        </time>
                        <?php if ($version['created_by_name']): ?>
                        <span class="version-author">by <?= htmlspecialchars($version['created_by_name']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($version['changelog']): ?>
                <div class="version-changelog">
                    <?= nl2br(htmlspecialchars($version['changelog'])) ?>
                </div>
                <?php endif; ?>

                <div class="version-details">
                    <span class="detail-item">
                        <span class="detail-label">Size:</span>
                        <span class="detail-value"><?= formatBytes($version['file_size']) ?></span>
                    </span>
                    <?php if ($version['file_hash']): ?>
                    <span class="detail-item">
                        <span class="detail-label">Hash:</span>
                        <span class="detail-value hash" title="<?= htmlspecialchars($version['file_hash']) ?>">
                            <?= substr($version['file_hash'], 0, 12) ?>...
                        </span>
                    </span>
                    <?php endif; ?>
                </div>

                <div class="version-actions">
                    <?php if ($version['file_path']): ?>
                    <a href="<?= basePath('assets/' . $version['file_path']) ?>"
                       class="btn btn-sm btn-secondary" download>
                        Download
                    </a>
                    <?php endif; ?>

                    <?php if ($index > 0 && count($versions) > 1): ?>
                    <a href="<?= route('model.compare', ['id' => $modelId, 'v1' => $versions[$index]['version_number'], 'v2' => $versions[$index - 1]['version_number']]) ?>"
                       class="btn btn-sm btn-secondary">
                        Compare with v<?= $versions[$index - 1]['version_number'] ?>
                    </a>
                    <?php endif; ?>

                    <?php if ($canEdit && $version['version_number'] != $currentVersion): ?>
                    <button type="button"
                            class="btn btn-sm btn-warning revert-btn"
                            data-version="<?= $version['version_number'] ?>"
                            data-model="<?= $modelId ?>">
                        Revert to this version
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Revert Confirmation Modal -->
<div id="revert-modal" class="modal page-model-versions" role="dialog" aria-modal="true" aria-labelledby="revert-modal-title" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="revert-modal-title">Revert to Version</h3>
            <button type="button" class="modal-close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to revert to <strong>v<span id="revert-version-number"></span></strong>?</p>
            <p class="text-muted">This will create a new version with the contents of the selected version. No data will be lost.</p>

            <div class="form-group">
                <label for="revert-changelog">Changelog (optional)</label>
                <textarea id="revert-changelog" class="form-control" rows="2" placeholder="Reverted to v..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-action="close-revert-modal">Cancel</button>
            <button type="button" class="btn btn-warning" id="confirm-revert-btn">Revert</button>
        </div>
    </div>
</div>

<script<?= csp_nonce_attr() ?>>
let revertModelId = null;
let revertVersionNumber = null;

document.querySelectorAll('.revert-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        revertVersionNumber = this.dataset.version;
        revertModelId = this.dataset.model;
        document.getElementById('revert-version-number').textContent = revertVersionNumber;
        document.getElementById('revert-changelog').value = 'Reverted to v' + revertVersionNumber;
        document.getElementById('revert-modal').style.display = 'flex';
    });
});

function closeRevertModal() {
    document.getElementById('revert-modal').style.display = 'none';
    revertModelId = null;
    revertVersionNumber = null;
}
document.querySelectorAll('#revert-modal .modal-close, #revert-modal [data-action="close-revert-modal"]').forEach(function(btn) {
    btn.addEventListener('click', closeRevertModal);
});

document.getElementById('confirm-revert-btn').addEventListener('click', async function() {
    if (!revertModelId || !revertVersionNumber) return;

    const changelog = document.getElementById('revert-changelog').value;

    this.disabled = true;
    this.textContent = 'Reverting...';

    try {
        var csrfToken = document.querySelector('meta[name="csrf-token"]');
        const response = await fetch('/actions/revert-version', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken ? csrfToken.content : '',
            },
            body: JSON.stringify({
                model_id: revertModelId,
                version_number: revertVersionNumber,
                changelog: changelog
            })
        });

        const result = await response.json();

        if (result.success) {
            window.location.reload();
        } else {
            showToast(result.error, 'error');
            this.disabled = false;
            this.textContent = 'Revert';
        }
    } catch (err) {
        showToast(err.message, 'error');
        this.disabled = false;
        this.textContent = 'Revert';
    }
});

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRevertModal();
    }
});

// Close modal on backdrop click
document.getElementById('revert-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeRevertModal();
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
