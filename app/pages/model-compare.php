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
            <span aria-hidden="true"><i class="fa-solid fa-right-left"></i></span>
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
        <table class="data-table" aria-label="Geometry comparison">
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

<script<?= csp_nonce_attr() ?>>
// Version data — exposed globally for compare-viewer.js module
window.CompareConfig = {
    version1Path: <?= json_encode($version1 && $version1['file_path'] ? basePath('assets/' . $version1['file_path']) : null) ?>,
    version2Path: <?= json_encode($version2 && $version2['file_path'] ? basePath('assets/' . $version2['file_path']) : null) ?>,
    version1Type: <?= json_encode($version1 ? strtolower(pathinfo($version1['file_path'] ?? '', PATHINFO_EXTENSION)) : null) ?>,
    version2Type: <?= json_encode($version2 ? strtolower(pathinfo($version2['file_path'] ?? '', PATHINFO_EXTENSION)) : null) ?>,
    compareUrl: '<?= route('model.compare', ['id' => $model['id']]) ?>'
};
</script>
<script<?= csp_nonce_attr() ?>>
// Load compare-viewer after Three.js is ready
if (window.THREE_READY) {
    var s = document.createElement('script');
    s.src = '<?= basePath('js/compare-viewer.js') ?>?v=<?= filemtime(__DIR__ . '/../../public/js/compare-viewer.js') ?>';
    document.head.appendChild(s);
} else {
    window.addEventListener('three-ready', function() {
        var s = document.createElement('script');
        s.src = '<?= basePath('js/compare-viewer.js') ?>?v=<?= filemtime(__DIR__ . '/../../public/js/compare-viewer.js') ?>';
        document.head.appendChild(s);
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
