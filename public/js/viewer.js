// 3D Model Viewer using Three.js

// Shared default material — reused across all loaders to reduce GPU objects
// Using MeshStandardMaterial (PBR) — better optimized on modern GPUs than legacy Phong
ModelViewer.DEFAULT_MATERIAL = null;
ModelViewer.getDefaultMaterial = function() {
    if (!ModelViewer.DEFAULT_MATERIAL) {
        ModelViewer.DEFAULT_MATERIAL = new THREE.MeshStandardMaterial({
            color: 0x888888,
            roughness: 0.6,
            metalness: 0.1
        });
        ModelViewer.DEFAULT_MATERIAL.needsUpdate = false;
    }
    return ModelViewer.DEFAULT_MATERIAL.clone();
};

class ModelViewer {
    constructor(container, options = {}) {
        this.container = container;
        this.options = {
            backgroundColor: 0x1e293b,
            modelColor: 0xffffff,
            autoRotate: options.autoRotate ?? true,
            interactive: options.interactive ?? false,
            progressiveLoading: options.progressiveLoading ?? true,
            ...options
        };

        this.scene = null;
        this.camera = null;
        this.renderer = null;
        this.controls = null;
        this.model = null;
        this.pivot = null;

        this.animationId = null;
        this.isReady = false;
        this.isVisible = true;
        this.lastFrameTime = 0;
        this.loadProgress = 0;
        this.onProgress = options.onProgress || null;

        this.init();
    }

    init() {
        const width = this.container.clientWidth;
        const height = this.container.clientHeight;

        // Scene
        this.scene = new THREE.Scene();
        this.scene.background = new THREE.Color(this.options.backgroundColor);

        // Camera — tighter near/far for better depth precision
        this.camera = new THREE.PerspectiveCamera(45, width / height, 1, 500);
        this.camera.position.set(0, 0, 100);

        // Renderer — use discrete GPU, skip antialias on non-interactive thumbnails
        try {
            this.renderer = new THREE.WebGLRenderer({
                antialias: this.options.interactive,
                powerPreference: 'high-performance'
            });
            if (!this.renderer.getContext()) {
                throw new Error('WebGL context not available');
            }
        } catch (e) {
            this.initFailed = true;
            throw new Error('WebGL not available: ' + e.message);
        }
        this.renderer.setSize(width, height);
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, this.options.interactive ? 2 : 1.5));

        // Skip object sorting for simple single-model scenes
        if (!this.options.interactive) {
            this.renderer.sortObjects = false;
        }

        this.container.appendChild(this.renderer.domElement);

        // Lighting
        const ambientLight = new THREE.AmbientLight(0xffffff, 0.8);
        this.scene.add(ambientLight);

        const directionalLight1 = new THREE.DirectionalLight(0xffffff, 1.0);
        directionalLight1.position.set(1, 1, 1);
        this.scene.add(directionalLight1);

        const directionalLight2 = new THREE.DirectionalLight(0xffffff, 0.6);
        directionalLight2.position.set(-1, -1, -1);
        this.scene.add(directionalLight2);

        const directionalLight3 = new THREE.DirectionalLight(0xffffff, 0.4);
        directionalLight3.position.set(0, -1, 0);
        this.scene.add(directionalLight3);

        // Controls (if interactive)
        if (this.options.interactive && typeof THREE.OrbitControls !== 'undefined') {
            this.controls = new THREE.OrbitControls(this.camera, this.renderer.domElement);
            this.controls.enableDamping = true;
            this.controls.dampingFactor = 0.05;
            this.controls.autoRotate = this.options.autoRotate;
            this.controls.autoRotateSpeed = 2;
        }

        // Handle resize
        this.resizeObserver = new ResizeObserver(() => this.onResize());
        this.resizeObserver.observe(this.container);

        // Pause animation when scrolled off-screen to save GPU
        this.visibilityObserver = new IntersectionObserver((entries) => {
            this.isVisible = entries[0].isIntersecting;
            if (this.isVisible && this.isReady && !this.animationId) {
                this.animate();
            }
        }, { threshold: 0 });
        this.visibilityObserver.observe(this.container);

        // Don't start animation until model is loaded to prevent flashing
        // Render one frame with just the background
        this.renderer.render(this.scene, this.camera);
    }


}

// Expose to global scope for viewer-loaders.js and other scripts
window.ModelViewer = ModelViewer;
