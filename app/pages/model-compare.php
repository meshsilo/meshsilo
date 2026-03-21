<?php
/**
 * Model Comparison Tool
 *
 * Side-by-side 3D comparison of different model versions
 */
require_once 'includes/config.php';

$db = getDB();

// Get model ID from URL
$modelId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$v1 = isset($_GET['v1']) ? (int)$_GET['v1'] : 0;
$v2 = isset($_GET['v2']) ? (int)$_GET['v2'] : 0;

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
    $versions[$row['version_number']] = $row;
}

if (empty($versions)) {
    header('Location: ' . route('model.versions', ['id' => $modelId]));
    exit;
}

// Default to comparing last two versions
$versionNumbers = array_keys($versions);
if (!$v1 || !isset($versions[$v1])) {
    $v1 = $versionNumbers[0] ?? 0;
}
if (!$v2 || !isset($versions[$v2])) {
    $v2 = $versionNumbers[1] ?? $versionNumbers[0] ?? 0;
}

$version1 = $versions[$v1] ?? null;
$version2 = $versions[$v2] ?? null;

$pageTitle = 'Compare Versions: ' . $model['name'];
$activePage = 'browse';

// formatBytes is defined in includes/helpers.php

$needsViewer = true;
require_once 'includes/header.php';
?>

<div class="container compare-container">
    <div class="breadcrumb">
        <a href="<?= route('browse') ?>">Models</a> &raquo;
        <a href="<?= route('model.show', ['id' => $modelId]) ?>"><?= htmlspecialchars($model['name']) ?></a> &raquo;
        <a href="<?= route('model.versions', ['id' => $modelId]) ?>">Versions</a> &raquo;
        <span>Compare</span>
    </div>

    <div class="page-header">
        <h1>Compare Versions</h1>
        <p class="subtitle"><?= htmlspecialchars($model['name']) ?></p>
    </div>

    <!-- Version Selectors -->
    <div class="compare-selectors">
        <div class="selector-group">
            <label for="version-select-1">Left (v<?= $v1 ?>)</label>
            <select id="version-select-1" class="form-control">
                <?php foreach ($versions as $num => $ver): ?>
                <option value="<?= $num ?>" <?= $num == $v1 ? 'selected' : '' ?>>
                    v<?= $num ?> - <?= htmlspecialchars(substr($ver['changelog'] ?? 'No changelog', 0, 30)) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="button" id="swap-versions" class="btn btn-secondary" title="Swap versions" aria-label="Swap versions">
            <span aria-hidden="true">&#8644;</span>
        </button>

        <div class="selector-group">
            <label for="version-select-2">Right (v<?= $v2 ?>)</label>
            <select id="version-select-2" class="form-control">
                <?php foreach ($versions as $num => $ver): ?>
                <option value="<?= $num ?>" <?= $num == $v2 ? 'selected' : '' ?>>
                    v<?= $num ?> - <?= htmlspecialchars(substr($ver['changelog'] ?? 'No changelog', 0, 30)) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Compare Views -->
    <div class="compare-panel">
        <!-- Left Version -->
        <div class="compare-side left">
            <div class="compare-header">
                <span class="version-badge">v<?= $v1 ?></span>
                <?php if ($version1): ?>
                <time class="version-date" datetime="<?= htmlspecialchars(date('c', strtotime($version1['created_at']))) ?>" data-timestamp="<?= htmlspecialchars($version1['created_at']) ?>"><?= date('M j, Y', strtotime($version1['created_at'])) ?></time>
                <?php endif; ?>
            </div>

            <div class="compare-viewer">
                <?php if ($version1 && $version1['file_path']): ?>
                <div class="model-preview-container" id="viewer-1">
                    <div class="loading-spinner" role="status">Loading...</div>
                </div>
                <?php else: ?>
                <div class="no-preview">No file available</div>
                <?php endif; ?>
            </div>

            <?php if ($version1): ?>
            <div class="compare-details">
                <div class="detail-row">
                    <span class="label">Size:</span>
                    <span class="value"><?= formatBytes($version1['file_size'] ?? 0) ?></span>
                </div>
                <?php if ($version1['file_hash']): ?>
                <div class="detail-row">
                    <span class="label">Hash:</span>
                    <span class="value hash"><?= substr($version1['file_hash'], 0, 16) ?>...</span>
                </div>
                <?php endif; ?>
                <?php if ($version1['changelog']): ?>
                <div class="detail-row changelog">
                    <span class="label">Changelog:</span>
                    <span class="value"><?= nl2br(htmlspecialchars($version1['changelog'])) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Divider -->
        <div class="compare-divider"></div>

        <!-- Right Version -->
        <div class="compare-side right">
            <div class="compare-header">
                <span class="version-badge">v<?= $v2 ?></span>
                <?php if ($version2): ?>
                <time class="version-date" datetime="<?= htmlspecialchars(date('c', strtotime($version2['created_at']))) ?>" data-timestamp="<?= htmlspecialchars($version2['created_at']) ?>"><?= date('M j, Y', strtotime($version2['created_at'])) ?></time>
                <?php endif; ?>
            </div>

            <div class="compare-viewer">
                <?php if ($version2 && $version2['file_path']): ?>
                <div class="model-preview-container" id="viewer-2">
                    <div class="loading-spinner" role="status">Loading...</div>
                </div>
                <?php else: ?>
                <div class="no-preview">No file available</div>
                <?php endif; ?>
            </div>

            <?php if ($version2): ?>
            <div class="compare-details">
                <div class="detail-row">
                    <span class="label">Size:</span>
                    <span class="value"><?= formatBytes($version2['file_size'] ?? 0) ?></span>
                    <?php
                    $sizeDiff = ($version2['file_size'] ?? 0) - ($version1['file_size'] ?? 0);
                    if ($sizeDiff != 0):
                    ?>
                    <span class="diff <?= $sizeDiff > 0 ? 'increase' : 'decrease' ?>">
                        (<?= $sizeDiff > 0 ? '+' : '' ?><?= formatBytes($sizeDiff) ?>)
                    </span>
                    <?php endif; ?>
                </div>
                <?php if ($version2['file_hash']): ?>
                <div class="detail-row">
                    <span class="label">Hash:</span>
                    <span class="value hash"><?= substr($version2['file_hash'], 0, 16) ?>...</span>
                    <?php if ($version1['file_hash'] !== $version2['file_hash']): ?>
                    <span class="diff changed">(changed)</span>
                    <?php else: ?>
                    <span class="diff same">(identical)</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($version2['changelog']): ?>
                <div class="detail-row changelog">
                    <span class="label">Changelog:</span>
                    <span class="value"><?= nl2br(htmlspecialchars($version2['changelog'])) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Overlay Viewer (hidden by default) -->
    <div id="overlay-container" class="overlay-container" style="display: none;">
        <div class="overlay-legend">
            <span class="legend-item"><span class="legend-swatch" style="background: var(--color-version-1);"></span> v<?= $v1 ?> (Left)</span>
            <span class="legend-item"><span class="legend-swatch" style="background: var(--color-version-2);"></span> v<?= $v2 ?> (Right)</span>
            <span class="legend-item"><span class="legend-swatch" style="background: var(--color-overlap);"></span> Overlap</span>
        </div>
        <div class="overlay-viewer" id="overlay-viewer">
            <div class="loading-spinner" role="status">Loading overlay...</div>
        </div>
        <div class="overlay-opacity-control">
            <label for="overlay-opacity">Right model opacity:</label>
            <input type="range" id="overlay-opacity" min="0" max="100" value="60">
            <span id="overlay-opacity-value">60%</span>
        </div>
    </div>

    <!-- Geometry Stats -->
    <div class="geometry-stats" id="geometry-stats" style="display: none;">
        <h3>Geometry Comparison</h3>
        <table class="stats-table" aria-label="Geometry comparison">
            <thead>
                <tr>
                    <th scope="col"></th>
                    <th scope="col">v<?= $v1 ?> (Left)</th>
                    <th scope="col">v<?= $v2 ?> (Right)</th>
                    <th scope="col">Diff</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="label">Triangles</td>
                    <td id="stats-tri-1">—</td>
                    <td id="stats-tri-2">—</td>
                    <td id="stats-tri-diff">—</td>
                </tr>
                <tr>
                    <td class="label">Dimensions (X)</td>
                    <td id="stats-x-1">—</td>
                    <td id="stats-x-2">—</td>
                    <td id="stats-x-diff">—</td>
                </tr>
                <tr>
                    <td class="label">Dimensions (Y)</td>
                    <td id="stats-y-1">—</td>
                    <td id="stats-y-2">—</td>
                    <td id="stats-y-diff">—</td>
                </tr>
                <tr>
                    <td class="label">Dimensions (Z)</td>
                    <td id="stats-z-1">—</td>
                    <td id="stats-z-2">—</td>
                    <td id="stats-z-diff">—</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Compare Controls -->
    <div class="compare-controls">
        <label class="control-item">
            <input type="checkbox" id="sync-cameras" checked>
            <span>Sync camera rotation</span>
        </label>
        <label class="control-item">
            <input type="checkbox" id="show-wireframe">
            <span>Wireframe mode</span>
        </label>
        <label class="control-item">
            <input type="checkbox" id="overlay-mode">
            <span>Overlay mode</span>
        </label>
        <button type="button" class="btn btn-secondary" id="reset-views">
            Reset Views
        </button>
    </div>
</div>

<style>
.compare-container {
    max-width: 1600px;
}

.compare-selectors {
    display: flex;
    align-items: flex-end;
    gap: 1rem;
    margin-bottom: 1.5rem;
    padding: 1rem;
    background: var(--card-bg);
    border-radius: 8px;
}

.selector-group {
    flex: 1;
}

.selector-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    font-size: 0.875rem;
}

.selector-group select {
    width: 100%;
}

#swap-versions {
    padding: 0.5rem 1rem;
    font-size: 1.25rem;
    margin-bottom: 0;
}

.compare-panel {
    display: flex;
    gap: 0;
    background: var(--card-bg);
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid var(--color-border);
}

.compare-side {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.compare-divider {
    width: 4px;
    background: var(--color-border);
    cursor: col-resize;
}

.compare-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 1rem;
    background: var(--color-bg);
    border-bottom: 1px solid var(--color-border);
}

.version-badge {
    font-weight: 600;
    font-size: 1rem;
    padding: 0.25rem 0.5rem;
    background: var(--color-primary);
    color: white;
    border-radius: 4px;
}

.version-date {
    color: var(--color-text-muted);
    font-size: 0.875rem;
}

.compare-viewer {
    height: 400px;
    background: var(--color-bg);
    position: relative;
}

.model-preview-container {
    width: 100%;
    height: 100%;
}

.no-preview {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: var(--color-text-muted);
}

.loading-spinner {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: var(--color-text-muted);
}

.compare-details {
    padding: 1rem;
    border-top: 1px solid var(--color-border);
}

.detail-row {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
    font-size: 0.875rem;
}

.detail-row:last-child {
    margin-bottom: 0;
}

.detail-row .label {
    color: var(--color-text-muted);
    min-width: 70px;
}

.detail-row .value {
    flex: 1;
}

.detail-row .value.hash {
    font-family: monospace;
    font-size: 0.8rem;
}

.detail-row.changelog {
    flex-direction: column;
    gap: 0.25rem;
}

.detail-row.changelog .value {
    background: var(--color-bg);
    padding: 0.5rem;
    border-radius: 4px;
    line-height: 1.4;
}

.diff {
    font-size: 0.8rem;
    padding: 0.1rem 0.3rem;
    border-radius: 3px;
}

.diff.increase {
    color: var(--color-warning);
}

.diff.decrease {
    color: var(--color-success);
}

.diff.changed {
    color: var(--color-warning);
}

.diff.same {
    color: var(--color-success);
}

.compare-controls {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    margin-top: 1rem;
    padding: 1rem;
    background: var(--card-bg);
    border-radius: 8px;
}

.control-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    font-size: 0.9rem;
}

.control-item input[type="checkbox"] {
    width: 16px;
    height: 16px;
}

/* Overlay Mode */
.overlay-container {
    background: var(--card-bg);
    border-radius: 8px;
    border: 1px solid var(--color-border);
    overflow: hidden;
    margin-bottom: 1rem;
}

.overlay-legend {
    display: flex;
    gap: 1.5rem;
    padding: 0.75rem 1rem;
    background: var(--color-bg);
    border-bottom: 1px solid var(--color-border);
    font-size: 0.875rem;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.legend-swatch {
    display: inline-block;
    width: 14px;
    height: 14px;
    border-radius: 3px;
}

.overlay-viewer {
    height: 500px;
    background: var(--color-bg);
    position: relative;
}

.overlay-opacity-control {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    border-top: 1px solid var(--color-border);
    font-size: 0.875rem;
}

.overlay-opacity-control input[type="range"] {
    flex: 1;
    max-width: 300px;
}

/* Geometry Stats */
.geometry-stats {
    background: var(--card-bg);
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
}

.geometry-stats h3 {
    font-size: 1rem;
    margin-bottom: 0.75rem;
}

.stats-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.stats-table th, .stats-table td {
    padding: 0.5rem 0.75rem;
    text-align: left;
    border-bottom: 1px solid var(--color-border);
}

.stats-table th {
    font-weight: 500;
    color: var(--color-text-muted);
    font-size: 0.8rem;
}

.stats-table td.label {
    font-weight: 500;
    color: var(--color-text-muted);
    min-width: 120px;
}

.stats-diff-positive { color: var(--color-warning, #f59e0b); }
.stats-diff-negative { color: var(--color-success, #22c55e); }
.stats-diff-zero { color: var(--color-text-muted); }

@media (max-width: 768px) {
    .compare-selectors {
        flex-direction: column;
    }

    #swap-versions {
        align-self: center;
        transform: rotate(90deg);
    }

    .compare-panel {
        flex-direction: column;
    }

    .compare-divider {
        width: 100%;
        height: 4px;
    }

    .compare-viewer {
        height: 300px;
    }
}
</style>

<script>
// Version data — exposed globally for compare-viewer.js module
window.CompareConfig = {
    version1Path: <?= json_encode($version1 && $version1['file_path'] ? basePath('assets/' . $version1['file_path']) : null) ?>,
    version2Path: <?= json_encode($version2 && $version2['file_path'] ? basePath('assets/' . $version2['file_path']) : null) ?>,
    version1Type: <?= json_encode($version1 ? strtolower(pathinfo($version1['file_path'] ?? '', PATHINFO_EXTENSION)) : null) ?>,
    version2Type: <?= json_encode($version2 ? strtolower(pathinfo($version2['file_path'] ?? '', PATHINFO_EXTENSION)) : null) ?>
};
</script>
<script src="<?= basePath('js/compare-viewer.js') ?>?v=<?= filemtime(__DIR__ . '/../../public/js/compare-viewer.js') ?>" type="module"></script>

<?php require_once 'includes/footer.php'; ?>
