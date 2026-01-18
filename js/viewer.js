// 3D Model Viewer using Three.js
class ModelViewer {
    constructor(container, options = {}) {
        this.container = container;
        this.options = {
            backgroundColor: 0x1e293b,
            modelColor: 0x3b82f6,
            autoRotate: options.autoRotate ?? true,
            interactive: options.interactive ?? false,
            ...options
        };

        this.scene = null;
        this.camera = null;
        this.renderer = null;
        this.controls = null;
        this.model = null;
        this.animationId = null;

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

        // Renderer
        this.renderer = new THREE.WebGLRenderer({ antialias: true });
        this.renderer.setSize(width, height);
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        this.container.appendChild(this.renderer.domElement);

        // Lighting
        const ambientLight = new THREE.AmbientLight(0xffffff, 0.6);
        this.scene.add(ambientLight);

        const directionalLight1 = new THREE.DirectionalLight(0xffffff, 0.8);
        directionalLight1.position.set(1, 1, 1);
        this.scene.add(directionalLight1);

        const directionalLight2 = new THREE.DirectionalLight(0xffffff, 0.4);
        directionalLight2.position.set(-1, -1, -1);
        this.scene.add(directionalLight2);

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

        // Start animation
        this.animate();
    }

    async loadSTL(url) {
        return new Promise((resolve, reject) => {
            const loader = new THREE.STLLoader();
            loader.load(
                url,
                (geometry) => {
                    this.clearModel();

                    const material = new THREE.MeshPhongMaterial({
                        color: this.options.modelColor,
                        specular: 0x444444,
                        shininess: 30,
                        flatShading: false
                    });

                    this.model = new THREE.Mesh(geometry, material);
                    this.centerAndScaleModel();
                    this.scene.add(this.model);
                    resolve(this.model);
                },
                undefined,
                (error) => reject(error)
            );
        });
    }

    async load3MF(url) {
        return new Promise((resolve, reject) => {
            const loader = new THREE.ThreeMFLoader();
            loader.load(
                url,
                (object) => {
                    this.clearModel();

                    // 3MF files can contain multiple meshes
                    this.model = object;

                    // Apply material to all meshes
                    this.model.traverse((child) => {
                        if (child.isMesh) {
                            child.material = new THREE.MeshPhongMaterial({
                                color: this.options.modelColor,
                                specular: 0x444444,
                                shininess: 30
                            });
                        }
                    });

                    this.centerAndScaleModel();
                    this.scene.add(this.model);
                    resolve(this.model);
                },
                undefined,
                (error) => reject(error)
            );
        });
    }

    async loadModel(url, fileType) {
        try {
            if (fileType === 'stl') {
                return await this.loadSTL(url);
            } else if (fileType === '3mf') {
                return await this.load3MF(url);
            }
        } catch (error) {
            console.error('Error loading model:', error);
            throw error;
        }
    }

    centerAndScaleModel() {
        if (!this.model) return;

        // Compute bounding box
        const box = new THREE.Box3().setFromObject(this.model);
        const center = box.getCenter(new THREE.Vector3());
        const size = box.getSize(new THREE.Vector3());

        // Center the model
        this.model.position.sub(center);

        // Scale to fit in view
        const maxDim = Math.max(size.x, size.y, size.z);
        const scale = 50 / maxDim;
        this.model.scale.setScalar(scale);

        // Position camera
        this.camera.position.set(0, 30, 80);
        this.camera.lookAt(0, 0, 0);

        if (this.controls) {
            this.controls.target.set(0, 0, 0);
            this.controls.update();
        }
    }

    clearModel() {
        if (this.model) {
            this.scene.remove(this.model);
            if (this.model.geometry) {
                this.model.geometry.dispose();
            }
            if (this.model.material) {
                this.model.material.dispose();
            }
            this.model = null;
        }
    }

    onResize() {
        const width = this.container.clientWidth;
        const height = this.container.clientHeight;

        this.camera.aspect = width / height;
        this.camera.updateProjectionMatrix();
        this.renderer.setSize(width, height);
    }

    animate() {
        this.animationId = requestAnimationFrame(() => this.animate());

        // Auto-rotate if no controls
        if (!this.controls && this.model && this.options.autoRotate) {
            this.model.rotation.y += 0.01;
        }

        if (this.controls) {
            this.controls.update();
        }

        this.renderer.render(this.scene, this.camera);
    }

    dispose() {
        if (this.animationId) {
            cancelAnimationFrame(this.animationId);
        }

        if (this.resizeObserver) {
            this.resizeObserver.disconnect();
        }

        this.clearModel();

        if (this.renderer) {
            this.renderer.dispose();
            this.container.removeChild(this.renderer.domElement);
        }

        if (this.controls) {
            this.controls.dispose();
        }
    }
}

// Initialize thumbnail viewers on page load
function initThumbnailViewers() {
    const thumbnails = document.querySelectorAll('.model-thumbnail[data-model-url]');

    thumbnails.forEach(thumbnail => {
        const url = thumbnail.dataset.modelUrl;
        const fileType = thumbnail.dataset.fileType;

        if (!url || !fileType) return;

        // Create viewer container
        const viewerContainer = document.createElement('div');
        viewerContainer.className = 'thumbnail-viewer';
        viewerContainer.style.cssText = 'position: absolute; top: 0; left: 0; width: 100%; height: 100%;';
        thumbnail.appendChild(viewerContainer);

        // Initialize viewer
        const viewer = new ModelViewer(viewerContainer, {
            autoRotate: true,
            interactive: false,
            backgroundColor: 0x1e293b
        });

        // Load model
        viewer.loadModel(url, fileType)
            .then(() => {
                // Add class to hide file type badge when 3D viewer is active
                thumbnail.classList.add('has-viewer');
            })
            .catch(err => {
                console.warn('Failed to load thumbnail model:', err);
                viewerContainer.remove();
            });
    });
}

// Initialize detail viewer
function initDetailViewer(containerId, url, fileType) {
    const container = document.getElementById(containerId);
    if (!container) return null;

    const viewer = new ModelViewer(container, {
        autoRotate: true,
        interactive: true,
        backgroundColor: 0x1e293b
    });

    viewer.loadModel(url, fileType).catch(err => {
        console.error('Failed to load model:', err);
    });

    return viewer;
}

// Auto-init on DOM ready
document.addEventListener('DOMContentLoaded', initThumbnailViewers);
