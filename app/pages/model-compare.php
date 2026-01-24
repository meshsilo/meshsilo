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
$stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
$result = $stmt->execute();
$model = $result->fetchArray(SQLITE3_ASSOC);

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
$stmt->bindValue(':model_id', $modelId, SQLITE3_INTEGER);
$result = $stmt->execute();

$versions = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
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

// Format file size
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

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
// Store file paths for the viewers
const version1Path = <?= json_encode($version1 && $version1['file_path'] ? basePath('assets/' . $version1['file_path']) : null) ?>;
const version2Path = <?= json_encode($version2 && $version2['file_path'] ? basePath('assets/' . $version2['file_path']) : null) ?>;
const version1Type = <?= json_encode($version1 ? pathinfo($version1['file_path'] ?? '', PATHINFO_EXTENSION) : null) ?>;
const version2Type = <?= json_encode($version2 ? pathinfo($version2['file_path'] ?? '', PATHINFO_EXTENSION) : null) ?>;

let viewer1 = null;
let viewer2 = null;
let syncCameras = true;

// Initialize comparison viewers when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    initCompareViewers();

    // Version selector changes
    document.getElementById('version-select-1').addEventListener('change', updateComparison);
    document.getElementById('version-select-2').addEventListener('change', updateComparison);

    // Swap button
    document.getElementById('swap-versions').addEventListener('click', function() {
        const sel1 = document.getElementById('version-select-1');
        const sel2 = document.getElementById('version-select-2');
        const temp = sel1.value;
        sel1.value = sel2.value;
        sel2.value = temp;
        updateComparison();
    });

    // Sync cameras toggle
    document.getElementById('sync-cameras').addEventListener('change', function() {
        syncCameras = this.checked;
    });

    // Wireframe toggle
    document.getElementById('show-wireframe').addEventListener('change', function() {
        toggleWireframe(this.checked);
    });

    // Reset views
    document.getElementById('reset-views').addEventListener('click', resetViews);
});

function initCompareViewers() {
    // Initialize both 3D viewers
    if (version1Path && document.getElementById('viewer-1')) {
        viewer1 = initViewer('viewer-1', version1Path, version1Type);
    }
    if (version2Path && document.getElementById('viewer-2')) {
        viewer2 = initViewer('viewer-2', version2Path, version2Type);
    }
}

function initViewer(containerId, modelPath, modelType) {
    const container = document.getElementById(containerId);
    if (!container) return null;

    container.innerHTML = '';

    const scene = new THREE.Scene();
    scene.background = new THREE.Color(getComputedStyle(document.documentElement).getPropertyValue('--bg-color').trim() || '#1a1a1a');

    const camera = new THREE.PerspectiveCamera(45, container.clientWidth / container.clientHeight, 0.1, 1000);
    camera.position.set(100, 100, 100);

    const renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setSize(container.clientWidth, container.clientHeight);
    renderer.setPixelRatio(window.devicePixelRatio);
    container.appendChild(renderer.domElement);

    const controls = new THREE.OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.05;

    // Sync camera controls
    if (containerId === 'viewer-1') {
        controls.addEventListener('change', function() {
            if (syncCameras && viewer2) {
                viewer2.camera.position.copy(camera.position);
                viewer2.camera.rotation.copy(camera.rotation);
                viewer2.controls.target.copy(controls.target);
            }
        });
    } else {
        controls.addEventListener('change', function() {
            if (syncCameras && viewer1) {
                viewer1.camera.position.copy(camera.position);
                viewer1.camera.rotation.copy(camera.rotation);
                viewer1.controls.target.copy(controls.target);
            }
        });
    }

    // Lighting
    const ambientLight = new THREE.AmbientLight(0xffffff, 0.6);
    scene.add(ambientLight);

    const directionalLight = new THREE.DirectionalLight(0xffffff, 0.8);
    directionalLight.position.set(1, 1, 1);
    scene.add(directionalLight);

    const directionalLight2 = new THREE.DirectionalLight(0xffffff, 0.4);
    directionalLight2.position.set(-1, -1, -1);
    scene.add(directionalLight2);

    // Load model
    let loader;
    if (modelType === 'stl') {
        loader = new THREE.STLLoader();
    } else if (modelType === '3mf') {
        loader = new THREE.ThreeMFLoader();
    }

    if (loader) {
        loader.load(modelPath, function(geometry) {
            let mesh;

            if (modelType === 'stl') {
                const material = new THREE.MeshPhongMaterial({
                    color: 0x3498db,
                    specular: 0x111111,
                    shininess: 100
                });
                mesh = new THREE.Mesh(geometry, material);
            } else {
                mesh = geometry;
            }

            // Center the model
            const box = new THREE.Box3().setFromObject(mesh);
            const center = box.getCenter(new THREE.Vector3());
            mesh.position.sub(center);

            scene.add(mesh);

            // Adjust camera
            const size = box.getSize(new THREE.Vector3());
            const maxDim = Math.max(size.x, size.y, size.z);
            const fov = camera.fov * (Math.PI / 180);
            let cameraZ = Math.abs(maxDim / Math.sin(fov / 2));
            camera.position.set(cameraZ * 0.5, cameraZ * 0.5, cameraZ * 0.5);
            camera.lookAt(0, 0, 0);
            controls.update();

            container.querySelector('.loading-spinner')?.remove();
        }, undefined, function(error) {
            console.error('Error loading model:', error);
            container.innerHTML = '<div class="no-preview">Error loading model</div>';
        });
    }

    // Animation loop
    function animate() {
        requestAnimationFrame(animate);
        controls.update();
        renderer.render(scene, camera);
    }
    animate();

    // Handle resize
    window.addEventListener('resize', function() {
        camera.aspect = container.clientWidth / container.clientHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(container.clientWidth, container.clientHeight);
    });

    return { scene, camera, renderer, controls };
}

function updateComparison() {
    const v1 = document.getElementById('version-select-1').value;
    const v2 = document.getElementById('version-select-2').value;
    window.location.href = '<?= route('model.compare', ['id' => $modelId]) ?>&v1=' + v1 + '&v2=' + v2;
}

function toggleWireframe(enabled) {
    [viewer1, viewer2].forEach(viewer => {
        if (!viewer) return;
        viewer.scene.traverse(function(child) {
            if (child.isMesh) {
                child.material.wireframe = enabled;
            }
        });
    });
}

function resetViews() {
    [viewer1, viewer2].forEach(viewer => {
        if (!viewer) return;
        viewer.camera.position.set(100, 100, 100);
        viewer.camera.lookAt(0, 0, 0);
        viewer.controls.target.set(0, 0, 0);
        viewer.controls.update();
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
