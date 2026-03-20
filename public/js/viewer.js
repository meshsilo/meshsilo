// 3D Model Viewer using Three.js
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

        // Camera
        this.camera = new THREE.PerspectiveCamera(45, width / height, 0.1, 1000);
        this.camera.position.set(0, 0, 100);

        // Renderer - wrap in try-catch for WebGL context errors
        try {
            this.renderer = new THREE.WebGLRenderer({ antialias: true });
            if (!this.renderer.getContext()) {
                throw new Error('WebGL context not available');
            }
        } catch (e) {
            this.initFailed = true;
            throw new Error('WebGL not available: ' + e.message);
        }
        this.renderer.setSize(width, height);
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        this.container.appendChild(this.renderer.domElement);

        // Lighting - brighter for white models
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

        // Don't start animation until model is loaded to prevent flashing
        // Render one frame with just the background
        this.renderer.render(this.scene, this.camera);
    }


}

