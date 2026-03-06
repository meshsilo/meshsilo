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
            <label>Left (v<?= $v1 ?>)</label>
            <select id="version-select-1" class="form-control">
                <?php foreach ($versions as $num => $ver): ?>
                <option value="<?= $num ?>" <?= $num == $v1 ? 'selected' : '' ?>>
                    v<?= $num ?> - <?= htmlspecialchars(substr($ver['changelog'] ?? 'No changelog', 0, 30)) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="button" id="swap-versions" class="btn btn-secondary" title="Swap versions">
            &#8644;
        </button>

        <div class="selector-group">
            <label>Right (v<?= $v2 ?>)</label>
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
                <span class="version-date"><?= date('M j, Y', strtotime($version1['created_at'])) ?></span>
                <?php endif; ?>
            </div>

            <div class="compare-viewer">
                <?php if ($version1 && $version1['file_path']): ?>
                <div class="model-preview-container" id="viewer-1">
                    <div class="loading-spinner">Loading...</div>
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
                <span class="version-date"><?= date('M j, Y', strtotime($version2['created_at'])) ?></span>
                <?php endif; ?>
            </div>

            <div class="compare-viewer">
                <?php if ($version2 && $version2['file_path']): ?>
                <div class="model-preview-container" id="viewer-2">
                    <div class="loading-spinner">Loading...</div>
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
            <div class="loading-spinner">Loading overlay...</div>
        </div>
        <div class="overlay-opacity-control">
            <label>Right model opacity:</label>
            <input type="range" id="overlay-opacity" min="0" max="100" value="60">
            <span id="overlay-opacity-value">60%</span>
        </div>
    </div>

    <!-- Geometry Stats -->
    <div class="geometry-stats" id="geometry-stats" style="display: none;">
        <h3>Geometry Comparison</h3>
        <table class="stats-table">
            <thead>
                <tr>
                    <th></th>
                    <th>v<?= $v1 ?> (Left)</th>
                    <th>v<?= $v2 ?> (Right)</th>
                    <th>Diff</th>
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
    border: 1px solid var(--border-color);
}

.compare-side {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.compare-divider {
    width: 4px;
    background: var(--border-color);
    cursor: col-resize;
}

.compare-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 1rem;
    background: var(--bg-color);
    border-bottom: 1px solid var(--border-color);
}

.version-badge {
    font-weight: 600;
    font-size: 1rem;
    padding: 0.25rem 0.5rem;
    background: var(--accent-color);
    color: white;
    border-radius: 4px;
}

.version-date {
    color: var(--text-muted);
    font-size: 0.875rem;
}

.compare-viewer {
    height: 400px;
    background: var(--bg-color);
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
    color: var(--text-muted);
}

.loading-spinner {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: var(--text-muted);
}

.compare-details {
    padding: 1rem;
    border-top: 1px solid var(--border-color);
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
    color: var(--text-muted);
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
    background: var(--bg-color);
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
    color: var(--warning-color);
}

.diff.decrease {
    color: var(--success-color);
}

.diff.changed {
    color: var(--warning-color);
}

.diff.same {
    color: var(--success-color);
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
    border: 1px solid var(--border-color);
    overflow: hidden;
    margin-bottom: 1rem;
}

.overlay-legend {
    display: flex;
    gap: 1.5rem;
    padding: 0.75rem 1rem;
    background: var(--bg-color);
    border-bottom: 1px solid var(--border-color);
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
    background: var(--bg-color);
    position: relative;
}

.overlay-opacity-control {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    border-top: 1px solid var(--border-color);
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
    border-bottom: 1px solid var(--border-color);
}

.stats-table th {
    font-weight: 500;
    color: var(--text-muted);
    font-size: 0.8rem;
}

.stats-table td.label {
    font-weight: 500;
    color: var(--text-muted);
    min-width: 120px;
}

.stats-diff-positive { color: var(--warning-color, #f59e0b); }
.stats-diff-negative { color: var(--success-color, #22c55e); }
.stats-diff-zero { color: var(--text-muted); }

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
// Version data
const version1Path = <?= json_encode($version1 && $version1['file_path'] ? basePath('assets/' . $version1['file_path']) : null) ?>;
const version2Path = <?= json_encode($version2 && $version2['file_path'] ? basePath('assets/' . $version2['file_path']) : null) ?>;
const version1Type = <?= json_encode($version1 ? strtolower(pathinfo($version1['file_path'] ?? '', PATHINFO_EXTENSION)) : null) ?>;
const version2Type = <?= json_encode($version2 ? strtolower(pathinfo($version2['file_path'] ?? '', PATHINFO_EXTENSION)) : null) ?>;

let viewer1 = null;
let viewer2 = null;
let overlayViewer = null;
let syncCameras = true;
let overlayActive = false;

// Geometry stats storage
const geoStats = { 1: null, 2: null };

document.addEventListener('DOMContentLoaded', function() {
    initCompareViewers();

    document.getElementById('version-select-1').addEventListener('change', updateComparison);
    document.getElementById('version-select-2').addEventListener('change', updateComparison);

    document.getElementById('swap-versions').addEventListener('click', function() {
        const sel1 = document.getElementById('version-select-1');
        const sel2 = document.getElementById('version-select-2');
        const temp = sel1.value;
        sel1.value = sel2.value;
        sel2.value = temp;
        updateComparison();
    });

    document.getElementById('sync-cameras').addEventListener('change', function() {
        syncCameras = this.checked;
    });

    document.getElementById('show-wireframe').addEventListener('change', function() {
        toggleWireframe(this.checked);
    });

    document.getElementById('reset-views').addEventListener('click', resetViews);

    // Overlay mode toggle
    document.getElementById('overlay-mode').addEventListener('change', function() {
        toggleOverlayMode(this.checked);
    });

    // Overlay opacity slider
    document.getElementById('overlay-opacity').addEventListener('input', function() {
        document.getElementById('overlay-opacity-value').textContent = this.value + '%';
        updateOverlayOpacity(this.value / 100);
    });
});

function initCompareViewers() {
    if (version1Path && document.getElementById('viewer-1')) {
        viewer1 = initViewer('viewer-1', version1Path, version1Type, 1);
    }
    if (version2Path && document.getElementById('viewer-2')) {
        viewer2 = initViewer('viewer-2', version2Path, version2Type, 2);
    }
}

function loadModelByType(modelPath, modelType, onLoad, onError) {
    const ext = (modelType || '').toLowerCase();

    if (ext === 'stl') {
        new THREE.STLLoader().load(modelPath, function(geometry) {
            const material = new THREE.MeshPhongMaterial({ color: 0x3498db, specular: 0x111111, shininess: 100 });
            onLoad(new THREE.Mesh(geometry, material));
        }, undefined, onError);
    } else if (ext === '3mf') {
        new THREE.ThreeMFLoader().load(modelPath, function(group) {
            onLoad(group);
        }, undefined, onError);
    } else if (ext === 'obj') {
        new THREE.OBJLoader().load(modelPath, function(group) {
            onLoad(group);
        }, undefined, onError);
    } else if (ext === 'ply') {
        new THREE.PLYLoader().load(modelPath, function(geometry) {
            geometry.computeVertexNormals();
            const material = new THREE.MeshPhongMaterial({ color: 0x3498db, specular: 0x111111, shininess: 100 });
            onLoad(new THREE.Mesh(geometry, material));
        }, undefined, onError);
    } else if (ext === 'glb' || ext === 'gltf') {
        new THREE.GLTFLoader().load(modelPath, function(gltf) {
            onLoad(gltf.scene);
        }, undefined, onError);
    } else if (ext === 'fbx' && THREE.FBXLoader) {
        new THREE.FBXLoader().load(modelPath, function(group) {
            onLoad(group);
        }, undefined, onError);
    } else if (ext === 'dae' && THREE.ColladaLoader) {
        new THREE.ColladaLoader().load(modelPath, function(collada) {
            onLoad(collada.scene);
        }, undefined, onError);
    } else {
        // Fallback: try STL loader
        new THREE.STLLoader().load(modelPath, function(geometry) {
            const material = new THREE.MeshPhongMaterial({ color: 0x3498db, specular: 0x111111, shininess: 100 });
            onLoad(new THREE.Mesh(geometry, material));
        }, undefined, onError);
    }
}

function getGeometryStats(object) {
    let triangles = 0;
    const box = new THREE.Box3().setFromObject(object);
    const size = box.getSize(new THREE.Vector3());

    object.traverse(function(child) {
        if (child.isMesh && child.geometry) {
            const geo = child.geometry;
            if (geo.index) {
                triangles += geo.index.count / 3;
            } else if (geo.attributes.position) {
                triangles += geo.attributes.position.count / 3;
            }
        }
    });

    return { triangles: Math.round(triangles), x: size.x, y: size.y, z: size.z };
}

function updateStatsDisplay() {
    const s1 = geoStats[1];
    const s2 = geoStats[2];
    if (!s1 && !s2) return;

    document.getElementById('geometry-stats').style.display = '';

    if (s1) {
        document.getElementById('stats-tri-1').textContent = s1.triangles.toLocaleString();
        document.getElementById('stats-x-1').textContent = s1.x.toFixed(2) + ' mm';
        document.getElementById('stats-y-1').textContent = s1.y.toFixed(2) + ' mm';
        document.getElementById('stats-z-1').textContent = s1.z.toFixed(2) + ' mm';
    }
    if (s2) {
        document.getElementById('stats-tri-2').textContent = s2.triangles.toLocaleString();
        document.getElementById('stats-x-2').textContent = s2.x.toFixed(2) + ' mm';
        document.getElementById('stats-y-2').textContent = s2.y.toFixed(2) + ' mm';
        document.getElementById('stats-z-2').textContent = s2.z.toFixed(2) + ' mm';
    }
    if (s1 && s2) {
        setDiffCell('stats-tri-diff', s2.triangles - s1.triangles, '', true);
        setDiffCell('stats-x-diff', s2.x - s1.x, ' mm');
        setDiffCell('stats-y-diff', s2.y - s1.y, ' mm');
        setDiffCell('stats-z-diff', s2.z - s1.z, ' mm');
    }
}

function setDiffCell(id, diff, suffix, isInt) {
    const el = document.getElementById(id);
    const val = isInt ? Math.round(diff) : diff.toFixed(2);
    const sign = diff > 0 ? '+' : '';
    el.textContent = sign + (isInt ? val.toLocaleString() : val) + (suffix || '');
    el.className = diff > 0 ? 'stats-diff-positive' : diff < 0 ? 'stats-diff-negative' : 'stats-diff-zero';
}

function centerAndFitCamera(object, camera, controls) {
    const box = new THREE.Box3().setFromObject(object);
    const center = box.getCenter(new THREE.Vector3());
    object.position.sub(center);

    const size = box.getSize(new THREE.Vector3());
    const maxDim = Math.max(size.x, size.y, size.z);
    const fov = camera.fov * (Math.PI / 180);
    const cameraZ = Math.abs(maxDim / Math.sin(fov / 2));
    camera.position.set(cameraZ * 0.5, cameraZ * 0.5, cameraZ * 0.5);
    camera.lookAt(0, 0, 0);
    controls.target.set(0, 0, 0);
    controls.update();
}

function createScene(container) {
    const scene = new THREE.Scene();
    scene.background = new THREE.Color(getComputedStyle(document.documentElement).getPropertyValue('--bg-color').trim() || '#1a1a1a');

    const camera = new THREE.PerspectiveCamera(45, container.clientWidth / container.clientHeight, 0.1, 10000);
    camera.position.set(100, 100, 100);

    const renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setSize(container.clientWidth, container.clientHeight);
    renderer.setPixelRatio(window.devicePixelRatio);
    container.appendChild(renderer.domElement);

    const controls = new THREE.OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.05;

    // Lighting
    scene.add(new THREE.AmbientLight(0xffffff, 0.6));
    const dl1 = new THREE.DirectionalLight(0xffffff, 0.8);
    dl1.position.set(1, 1, 1);
    scene.add(dl1);
    const dl2 = new THREE.DirectionalLight(0xffffff, 0.4);
    dl2.position.set(-1, -1, -1);
    scene.add(dl2);

    function animate() {
        requestAnimationFrame(animate);
        controls.update();
        renderer.render(scene, camera);
    }
    animate();

    window.addEventListener('resize', function() {
        camera.aspect = container.clientWidth / container.clientHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(container.clientWidth, container.clientHeight);
    });

    return { scene, camera, renderer, controls };
}

function initViewer(containerId, modelPath, modelType, viewerIndex) {
    const container = document.getElementById(containerId);
    if (!container) return null;

    container.innerHTML = '';

    const viewer = createScene(container);

    // Sync camera controls
    if (containerId === 'viewer-1') {
        viewer.controls.addEventListener('change', function() {
            if (syncCameras && viewer2) {
                viewer2.camera.position.copy(viewer.camera.position);
                viewer2.camera.rotation.copy(viewer.camera.rotation);
                viewer2.controls.target.copy(viewer.controls.target);
            }
        });
    } else {
        viewer.controls.addEventListener('change', function() {
            if (syncCameras && viewer1) {
                viewer1.camera.position.copy(viewer.camera.position);
                viewer1.camera.rotation.copy(viewer.camera.rotation);
                viewer1.controls.target.copy(viewer.controls.target);
            }
        });
    }

    loadModelByType(modelPath, modelType, function(object) {
        // Collect geometry stats before centering
        geoStats[viewerIndex] = getGeometryStats(object);
        updateStatsDisplay();

        centerAndFitCamera(object, viewer.camera, viewer.controls);
        viewer.scene.add(object);
        container.querySelector('.loading-spinner')?.remove();
    }, function(error) {
        console.error('Error loading model:', error);
        container.innerHTML = '<div class="no-preview">Error loading model</div>';
    });

    return viewer;
}

// --- Overlay Mode ---

function toggleOverlayMode(enabled) {
    overlayActive = enabled;
    const panel = document.querySelector('.compare-panel');
    const overlay = document.getElementById('overlay-container');

    if (enabled) {
        panel.style.display = 'none';
        overlay.style.display = '';
        if (!overlayViewer) {
            initOverlayViewer();
        }
    } else {
        panel.style.display = '';
        overlay.style.display = 'none';
    }
}

function initOverlayViewer() {
    const container = document.getElementById('overlay-viewer');
    if (!container) return;

    container.innerHTML = '';
    const viewer = createScene(container);
    overlayViewer = viewer;
    overlayViewer.leftModel = null;
    overlayViewer.rightModel = null;

    let loadCount = 0;
    const onBothLoaded = function() {
        loadCount++;
        if (loadCount < 2) return;

        // Fit camera to combined bounding box
        const combinedBox = new THREE.Box3();
        if (overlayViewer.leftModel) combinedBox.expandByObject(overlayViewer.leftModel);
        if (overlayViewer.rightModel) combinedBox.expandByObject(overlayViewer.rightModel);

        const size = combinedBox.getSize(new THREE.Vector3());
        const maxDim = Math.max(size.x, size.y, size.z);
        const fov = viewer.camera.fov * (Math.PI / 180);
        const cameraZ = Math.abs(maxDim / Math.sin(fov / 2));
        viewer.camera.position.set(cameraZ * 0.5, cameraZ * 0.5, cameraZ * 0.5);
        viewer.camera.lookAt(0, 0, 0);
        viewer.controls.target.set(0, 0, 0);
        viewer.controls.update();

        container.querySelector('.loading-spinner')?.remove();
    };

    // Load left model (blue)
    if (version1Path) {
        loadModelByType(version1Path, version1Type, function(object) {
            applyMaterialOverride(object, 0x3498db, 0.6);
            const box = new THREE.Box3().setFromObject(object);
            const center = box.getCenter(new THREE.Vector3());
            object.position.sub(center);
            viewer.scene.add(object);
            overlayViewer.leftModel = object;
            onBothLoaded();
        }, function() { onBothLoaded(); });
    } else {
        onBothLoaded();
    }

    // Load right model (red)
    if (version2Path) {
        loadModelByType(version2Path, version2Type, function(object) {
            applyMaterialOverride(object, 0xe74c3c, 0.6);
            const box = new THREE.Box3().setFromObject(object);
            const center = box.getCenter(new THREE.Vector3());
            object.position.sub(center);
            viewer.scene.add(object);
            overlayViewer.rightModel = object;
            onBothLoaded();
        }, function() { onBothLoaded(); });
    } else {
        onBothLoaded();
    }
}

function applyMaterialOverride(object, color, opacity) {
    const mat = new THREE.MeshPhongMaterial({
        color: color,
        specular: 0x111111,
        shininess: 60,
        transparent: true,
        opacity: opacity,
        depthWrite: false,
        side: THREE.DoubleSide
    });

    object.traverse(function(child) {
        if (child.isMesh) {
            child.material = mat;
        }
    });
}

function updateOverlayOpacity(value) {
    if (!overlayViewer || !overlayViewer.rightModel) return;
    overlayViewer.rightModel.traverse(function(child) {
        if (child.isMesh && child.material) {
            child.material.opacity = value;
        }
    });
}

// --- Common Controls ---

function updateComparison() {
    const v1 = document.getElementById('version-select-1').value;
    const v2 = document.getElementById('version-select-2').value;
    window.location.href = '<?= route('model.compare', ['id' => $modelId]) ?>&v1=' + v1 + '&v2=' + v2;
}

function toggleWireframe(enabled) {
    const viewers = overlayActive ? [overlayViewer] : [viewer1, viewer2];
    viewers.forEach(viewer => {
        if (!viewer) return;
        viewer.scene.traverse(function(child) {
            if (child.isMesh) {
                child.material.wireframe = enabled;
            }
        });
    });
}

function resetViews() {
    const viewers = overlayActive ? [overlayViewer] : [viewer1, viewer2];
    viewers.forEach(viewer => {
        if (!viewer) return;
        viewer.camera.position.set(100, 100, 100);
        viewer.camera.lookAt(0, 0, 0);
        viewer.controls.target.set(0, 0, 0);
        viewer.controls.update();
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
