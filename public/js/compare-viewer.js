/**
 * Compare Viewer — Side-by-side 3D model version comparison
 * Uses Three.js for rendering. Loaded as module after viewer.js.
 * Reads config from window.CompareConfig (set by model-compare.php).
 */
const { version1Path, version2Path, version1Type, version2Type } = window.CompareConfig || {};
let viewer1 = null;
let viewer2 = null;
let overlayViewer = null;
let syncCameras = true;
let overlayActive = false;
const geoStats = { 1: null, 2: null };

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
            const material = new THREE.MeshStandardMaterial({ color: 0x3498db, roughness: 0.4, metalness: 0.1 });
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
            const material = new THREE.MeshStandardMaterial({ color: 0x3498db, roughness: 0.4, metalness: 0.1 });
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
            const material = new THREE.MeshStandardMaterial({ color: 0x3498db, roughness: 0.4, metalness: 0.1 });
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
        if (!document.hidden) {
            requestAnimationFrame(animate);
            controls.update();
            renderer.render(scene, camera);
        } else {
            document.addEventListener('visibilitychange', function onVisible() {
                document.removeEventListener('visibilitychange', onVisible);
                requestAnimationFrame(animate);
            });
        }
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
    const mat = new THREE.MeshStandardMaterial({
        color: color,
        roughness: 0.5,
        metalness: 0.1,
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
    window.location.href = window.CompareConfig.compareUrl + '&v1=' + v1 + '&v2=' + v2;
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

// Initialize when DOM is ready (this module loads after Three.js module)
document.addEventListener('DOMContentLoaded', function() {
    initCompareViewers();

    document.getElementById('version-select-1')?.addEventListener('change', updateComparison);
    document.getElementById('version-select-2')?.addEventListener('change', updateComparison);

    document.getElementById('swap-versions')?.addEventListener('click', function() {
        const sel1 = document.getElementById('version-select-1');
        const sel2 = document.getElementById('version-select-2');
        const temp = sel1.value;
        sel1.value = sel2.value;
        sel2.value = temp;
        updateComparison();
    });

    document.getElementById('sync-cameras')?.addEventListener('change', function() {
        syncCameras = this.checked;
    });

    document.getElementById('show-wireframe')?.addEventListener('change', function() {
        toggleWireframe(this.checked);
    });

    document.getElementById('reset-views')?.addEventListener('click', resetViews);

    document.getElementById('overlay-mode')?.addEventListener('change', function() {
        toggleOverlayMode(this.checked);
    });

    document.getElementById('overlay-opacity')?.addEventListener('input', function() {
        document.getElementById('overlay-opacity-value').textContent = this.value + '%';
        updateOverlayOpacity(this.value / 100);
    });
});
