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

<div class="container">
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
                        <span class="version-date" data-timestamp="<?= htmlspecialchars($version['created_at']) ?>" title="<?= htmlspecialchars($version['created_at']) ?>">
                            <?= date('M j, Y', strtotime($version['created_at'])) ?>
                        </span>
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
<div id="revert-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Revert to Version</h3>
            <button type="button" class="modal-close" onclick="closeRevertModal()">&times;</button>
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
            <button type="button" class="btn btn-secondary" onclick="closeRevertModal()">Cancel</button>
            <button type="button" class="btn btn-warning" id="confirm-revert-btn">Revert</button>
        </div>
    </div>
</div>

<style>
.version-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
}

.version-count {
    color: var(--text-muted);
}

.version-timeline {
    position: relative;
}

.version-item {
    display: flex;
    margin-bottom: 0;
}

.version-item:last-child {
    margin-bottom: 0;
}

.version-marker {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-right: 1.5rem;
}

.version-dot {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: var(--border-color);
    border: 3px solid var(--bg-color);
    z-index: 1;
}

.version-dot.current {
    background: var(--accent-color);
    box-shadow: 0 0 0 4px rgba(var(--accent-color-rgb), 0.2);
}

.version-line {
    width: 2px;
    flex: 1;
    background: var(--border-color);
    min-height: 20px;
}

.version-content {
    flex: 1;
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 1.25rem;
    margin-bottom: 1rem;
}

.version-item.current .version-content {
    border-color: var(--accent-color);
}

.version-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.75rem;
}

.version-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.version-number {
    font-weight: 600;
    font-size: 1.1rem;
}

.version-meta {
    color: var(--text-muted);
    font-size: 0.875rem;
}

.version-author {
    margin-left: 0.5rem;
}

.version-changelog {
    background: var(--bg-color);
    padding: 0.75rem 1rem;
    border-radius: 4px;
    margin-bottom: 0.75rem;
    font-size: 0.9rem;
    line-height: 1.5;
}

.version-details {
    display: flex;
    gap: 1.5rem;
    margin-bottom: 0.75rem;
    font-size: 0.875rem;
}

.detail-item {
    display: flex;
    gap: 0.5rem;
}

.detail-label {
    color: var(--text-muted);
}

.detail-value.hash {
    font-family: monospace;
    font-size: 0.8rem;
}

.version-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.badge {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
}

.badge-success {
    background: var(--success-color);
    color: white;
}

.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: 0.875rem;
}

.btn-warning {
    background: var(--warning-color);
    color: var(--warning-text);
}

.btn-warning:hover {
    opacity: 0.9;
}

/* Modal */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal-content {
    background: var(--card-bg);
    border-radius: 8px;
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
}

.modal-header h3 {
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--text-muted);
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--border-color);
}

.text-muted {
    color: var(--text-muted);
    font-size: 0.875rem;
}

.empty-state {
    text-align: center;
    padding: 3rem;
}

.empty-state p {
    margin-bottom: 1rem;
    color: var(--text-muted);
}

@media (max-width: 768px) {
    .version-header {
        flex-direction: column;
        gap: 0.5rem;
    }

    .version-details {
        flex-direction: column;
        gap: 0.5rem;
    }
}
</style>

<script>
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

document.getElementById('confirm-revert-btn').addEventListener('click', async function() {
    if (!revertModelId || !revertVersionNumber) return;

    const changelog = document.getElementById('revert-changelog').value;

    this.disabled = true;
    this.textContent = 'Reverting...';

    try {
        const response = await fetch('/actions/revert-version', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
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
