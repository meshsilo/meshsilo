// 3D Model Viewer using Three.js
class ModelViewer {
    constructor(container, options = {}) {
        this.container = container;
        this.options = {
            backgroundColor: 0x1e293b,
            modelColor: 0xffffff,
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
        this.isReady = false;

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

    async loadSTL(url) {
        return new Promise(async (resolve, reject) => {
            try {
                // Pre-flight check: verify URL returns valid data
                const response = await fetch(url, { method: 'HEAD' });
                if (!response.ok) {
                    reject(new Error(`File not found: ${response.status}`));
                    return;
                }

                const loader = new THREE.STLLoader();
                loader.load(
                    url,
                    (geometry) => {
                        this.clearModel();

                        // Ensure normals are computed correctly
                        geometry.computeVertexNormals();

                        const material = new THREE.MeshPhongMaterial({
                            color: 0x888888,
                            specular: 0x222222,
                            shininess: 20
                        });

                        this.model = new THREE.Mesh(geometry, material);
                        this.centerAndScaleModel();
                        this.scene.add(this.model);
                        this.startAnimation();
                        resolve(this.model);
                    },
                    undefined,
                    (error) => reject(error)
                );
            } catch (error) {
                reject(error);
            }
        });
    }

    async load3MF(url) {
        return new Promise(async (resolve, reject) => {
            try {
                // Pre-flight check: verify URL returns valid data
                const response = await fetch(url, { method: 'HEAD' });
                if (!response.ok) {
                    reject(new Error(`File not found: ${response.status}`));
                    return;
                }

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
                                if (child.geometry) {
                                    child.geometry.computeVertexNormals();
                                }
                                child.material = new THREE.MeshPhongMaterial({
                                    color: 0x888888,
                                    specular: 0x222222,
                                    shininess: 20
                                });
                            }
                        });

                        this.centerAndScaleModel();
                        this.scene.add(this.model);
                        this.startAnimation();
                        resolve(this.model);
                    },
                    undefined,
                    (error) => reject(error)
                );
            } catch (error) {
                reject(error);
            }
        });
    }

    startAnimation() {
        if (!this.isReady) {
            this.isReady = true;
            this.animate();
        }
    }

    async loadGCODE(url) {
        return new Promise((resolve, reject) => {
            fetch(url)
                .then(response => {
                    if (!response.ok) throw new Error('Failed to fetch GCODE');
                    return response.text();
                })
                .then(gcodeText => {
                    this.clearModel();

                    const result = this.parseGCODE(gcodeText);
                    if (!result || result.vertices.length === 0) {
                        reject(new Error('No toolpath data found in GCODE'));
                        return;
                    }

                    // Create group to hold all line segments
                    this.model = new THREE.Group();

                    // Create geometry for print moves (with extrusion)
                    if (result.printVertices.length > 0) {
                        const printGeometry = new THREE.BufferGeometry();
                        printGeometry.setAttribute('position', new THREE.Float32BufferAttribute(result.printVertices, 3));
                        printGeometry.setAttribute('color', new THREE.Float32BufferAttribute(result.printColors, 3));

                        const printMaterial = new THREE.LineBasicMaterial({
                            vertexColors: true,
                            linewidth: 1
                        });

                        const printLines = new THREE.LineSegments(printGeometry, printMaterial);
                        this.model.add(printLines);
                    }

                    // Create geometry for travel moves (no extrusion) - make them subtle
                    if (result.travelVertices.length > 0) {
                        const travelGeometry = new THREE.BufferGeometry();
                        travelGeometry.setAttribute('position', new THREE.Float32BufferAttribute(result.travelVertices, 3));

                        const travelMaterial = new THREE.LineBasicMaterial({
                            color: 0x444444,
                            linewidth: 1,
                            transparent: true,
                            opacity: 0.3
                        });

                        const travelLines = new THREE.LineSegments(travelGeometry, travelMaterial);
                        this.model.add(travelLines);
                    }

                    this.centerAndScaleModel();
                    this.scene.add(this.model);
                    this.startAnimation();
                    resolve(this.model);
                })
                .catch(error => reject(error));
        });
    }

    parseGCODE(gcodeText) {
        const lines = gcodeText.split('\n');
        const printVertices = [];
        const printColors = [];
        const travelVertices = [];

        let currentX = 0, currentY = 0, currentZ = 0;
        let currentE = 0;
        let minZ = Infinity, maxZ = -Infinity;
        let isAbsolute = true;
        let isExtruderAbsolute = true;

        // First pass: find Z range for color mapping
        for (const line of lines) {
            const trimmed = line.trim();
            if (trimmed.startsWith(';') || trimmed === '') continue;

            const zMatch = trimmed.match(/Z([+-]?\d*\.?\d+)/i);
            if (zMatch) {
                const z = parseFloat(zMatch[1]);
                if (!isNaN(z) && z > 0) {
                    minZ = Math.min(minZ, z);
                    maxZ = Math.max(maxZ, z);
                }
            }
        }

        if (minZ === Infinity) minZ = 0;
        if (maxZ === -Infinity) maxZ = 1;
        const zRange = maxZ - minZ || 1;

        // Second pass: extract toolpath
        for (const line of lines) {
            const trimmed = line.trim();
            if (trimmed.startsWith(';') || trimmed === '') continue;

            // Check for coordinate mode
            if (trimmed.startsWith('G90')) {
                isAbsolute = true;
                continue;
            }
            if (trimmed.startsWith('G91')) {
                isAbsolute = false;
                continue;
            }
            if (trimmed.startsWith('M82')) {
                isExtruderAbsolute = true;
                continue;
            }
            if (trimmed.startsWith('M83')) {
                isExtruderAbsolute = false;
                continue;
            }

            // Parse G0/G1 moves
            const moveMatch = trimmed.match(/^G[01]\s/i);
            if (!moveMatch) continue;

            const isRapid = trimmed.startsWith('G0');

            // Extract coordinates
            const xMatch = trimmed.match(/X([+-]?\d*\.?\d+)/i);
            const yMatch = trimmed.match(/Y([+-]?\d*\.?\d+)/i);
            const zMatch = trimmed.match(/Z([+-]?\d*\.?\d+)/i);
            const eMatch = trimmed.match(/E([+-]?\d*\.?\d+)/i);

            const prevX = currentX, prevY = currentY, prevZ = currentZ;
            const prevE = currentE;

            if (xMatch) {
                const val = parseFloat(xMatch[1]);
                currentX = isAbsolute ? val : currentX + val;
            }
            if (yMatch) {
                const val = parseFloat(yMatch[1]);
                currentY = isAbsolute ? val : currentY + val;
            }
            if (zMatch) {
                const val = parseFloat(zMatch[1]);
                currentZ = isAbsolute ? val : currentZ + val;
            }
            if (eMatch) {
                const val = parseFloat(eMatch[1]);
                currentE = isExtruderAbsolute ? val : currentE + val;
            }

            // Skip if no actual movement
            if (prevX === currentX && prevY === currentY && prevZ === currentZ) continue;

            // Determine if this is a print move (extruding) or travel move
            const isExtruding = eMatch && (isExtruderAbsolute ? currentE > prevE : parseFloat(eMatch[1]) > 0);

            if (isExtruding && !isRapid) {
                // Print move - add to print vertices with color based on Z height
                printVertices.push(prevX, prevZ, -prevY); // Swap Y and Z for display
                printVertices.push(currentX, currentZ, -currentY);

                // Color based on Z height (blue at bottom, red at top)
                const t = (currentZ - minZ) / zRange;
                const r = t;
                const g = 0.2;
                const b = 1 - t;

                // Two vertices per segment
                printColors.push(r, g, b);
                printColors.push(r, g, b);
            } else {
                // Travel move
                travelVertices.push(prevX, prevZ, -prevY);
                travelVertices.push(currentX, currentZ, -currentY);
            }
        }

        return {
            vertices: [...printVertices, ...travelVertices],
            printVertices,
            printColors,
            travelVertices
        };
    }

    async loadOBJ(url) {
        return new Promise((resolve, reject) => {
            const loader = new THREE.OBJLoader();
            loader.load(
                url,
                (object) => {
                    this.clearModel();
                    this.model = object;

                    // Apply default material
                    this.model.traverse((child) => {
                        if (child.isMesh) {
                            if (child.geometry) {
                                child.geometry.computeVertexNormals();
                            }
                            child.material = new THREE.MeshPhongMaterial({
                                color: 0x888888,
                                specular: 0x222222,
                                shininess: 20
                            });
                        }
                    });

                    this.centerAndScaleModel();
                    this.scene.add(this.model);
                    this.startAnimation();
                    resolve(this.model);
                },
                undefined,
                (error) => reject(error)
            );
        });
    }

    async loadPLY(url) {
        return new Promise((resolve, reject) => {
            const loader = new THREE.PLYLoader();
            loader.load(
                url,
                (geometry) => {
                    this.clearModel();
                    geometry.computeVertexNormals();

                    const material = new THREE.MeshPhongMaterial({
                        color: 0x888888,
                        specular: 0x222222,
                        shininess: 20,
                        vertexColors: geometry.hasAttribute('color')
                    });

                    this.model = new THREE.Mesh(geometry, material);
                    this.centerAndScaleModel();
                    this.scene.add(this.model);
                    this.startAnimation();
                    resolve(this.model);
                },
                undefined,
                (error) => reject(error)
            );
        });
    }

    async loadGLTF(url) {
        return new Promise((resolve, reject) => {
            const loader = new THREE.GLTFLoader();
            loader.load(
                url,
                (gltf) => {
                    this.clearModel();
                    this.model = gltf.scene;

                    // Ensure normals are computed
                    this.model.traverse((child) => {
                        if (child.isMesh && child.geometry) {
                            child.geometry.computeVertexNormals();
                        }
                    });

                    this.centerAndScaleModel();
                    this.scene.add(this.model);
                    this.startAnimation();
                    resolve(this.model);
                },
                undefined,
                (error) => reject(error)
            );
        });
    }

    async loadDAE(url) {
        return new Promise((resolve, reject) => {
            const loader = new THREE.ColladaLoader();
            loader.load(
                url,
                (collada) => {
                    this.clearModel();
                    this.model = collada.scene;

                    this.model.traverse((child) => {
                        if (child.isMesh && child.geometry) {
                            child.geometry.computeVertexNormals();
                        }
                    });

                    this.centerAndScaleModel();
                    this.scene.add(this.model);
                    this.startAnimation();
                    resolve(this.model);
                },
                undefined,
                (error) => reject(error)
            );
        });
    }

    async loadFBX(url) {
        return new Promise((resolve, reject) => {
            const loader = new THREE.FBXLoader();
            loader.load(
                url,
                (object) => {
                    this.clearModel();
                    this.model = object;

                    this.model.traverse((child) => {
                        if (child.isMesh && child.geometry) {
                            child.geometry.computeVertexNormals();
                        }
                    });

                    this.centerAndScaleModel();
                    this.scene.add(this.model);
                    this.startAnimation();
                    resolve(this.model);
                },
                undefined,
                (error) => reject(error)
            );
        });
    }

    async load3DS(url) {
        return new Promise((resolve, reject) => {
            const loader = new THREE.TDSLoader();
            loader.load(
                url,
                (object) => {
                    this.clearModel();
                    this.model = object;

                    this.model.traverse((child) => {
                        if (child.isMesh && child.geometry) {
                            child.geometry.computeVertexNormals();
                        }
                    });

                    this.centerAndScaleModel();
                    this.scene.add(this.model);
                    this.startAnimation();
                    resolve(this.model);
                },
                undefined,
                (error) => reject(error)
            );
        });
    }

    async loadAMF(url) {
        return new Promise((resolve, reject) => {
            const loader = new THREE.AMFLoader();
            loader.load(
                url,
                (object) => {
                    this.clearModel();
                    this.model = object;

                    this.model.traverse((child) => {
                        if (child.isMesh) {
                            if (child.geometry) {
                                child.geometry.computeVertexNormals();
                            }
                            if (!child.material) {
                                child.material = new THREE.MeshPhongMaterial({
                                    color: 0x888888,
                                    specular: 0x222222,
                                    shininess: 20
                                });
                            }
                        }
                    });

                    this.centerAndScaleModel();
                    this.scene.add(this.model);
                    this.startAnimation();
                    resolve(this.model);
                },
                undefined,
                (error) => reject(error)
            );
        });
    }

    async loadCAD(url, fileType) {
        // Lazy load OpenCascade.js only when needed
        if (!window.occtImportJS) {
            console.log('Loading OpenCascade.js for CAD file preview...');
            try {
                await this.loadOpenCascade();
            } catch (error) {
                throw new Error('Failed to load OpenCascade.js: ' + error.message);
            }
        }

        return new Promise(async (resolve, reject) => {
            try {
                // Fetch the CAD file
                const response = await fetch(url);
                if (!response.ok) throw new Error('Failed to fetch CAD file');
                const fileBuffer = await response.arrayBuffer();

                // Convert to format occt-import-js expects
                const fileArray = new Uint8Array(fileBuffer);

                // Use occt-import-js to parse the file
                const result = await window.occtImportJS({
                    fileBuffer: fileArray,
                    fileName: url.split('/').pop()
                });

                if (!result.success) {
                    throw new Error('Failed to parse CAD file');
                }

                // Convert the result to Three.js geometry
                this.clearModel();
                this.model = this.convertOCCTToThree(result);

                this.centerAndScaleModel();
                this.scene.add(this.model);
                this.startAnimation();
                resolve(this.model);
            } catch (error) {
                reject(error);
            }
        });
    }

    async loadOpenCascade() {
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/occt-import-js@0.0.12/dist/occt-import-js.js';
            script.onload = () => {
                // Initialize occt-import-js
                if (typeof occtimportjs !== 'undefined') {
                    occtimportjs().then((occt) => {
                        window.occtImportJS = occt;
                        resolve();
                    }).catch(reject);
                } else {
                    reject(new Error('occt-import-js failed to load'));
                }
            };
            script.onerror = () => reject(new Error('Failed to load OpenCascade script'));
            document.head.appendChild(script);
        });
    }

    convertOCCTToThree(result) {
        const group = new THREE.Group();

        // OCCT result typically contains meshes with vertices and triangles
        if (result.meshes && result.meshes.length > 0) {
            result.meshes.forEach(mesh => {
                const geometry = new THREE.BufferGeometry();

                // Vertices
                if (mesh.attributes && mesh.attributes.position) {
                    geometry.setAttribute('position',
                        new THREE.Float32BufferAttribute(mesh.attributes.position.array, 3));
                }

                // Normals
                if (mesh.attributes && mesh.attributes.normal) {
                    geometry.setAttribute('normal',
                        new THREE.Float32BufferAttribute(mesh.attributes.normal.array, 3));
                } else {
                    geometry.computeVertexNormals();
                }

                // Indices
                if (mesh.index) {
                    geometry.setIndex(mesh.index.array);
                }

                const material = new THREE.MeshPhongMaterial({
                    color: 0x888888,
                    specular: 0x222222,
                    shininess: 20,
                    side: THREE.DoubleSide
                });

                const meshObj = new THREE.Mesh(geometry, material);
                group.add(meshObj);
            });
        }

        return group;
    }

    async loadModel(url, fileType) {
        try {
            const type = fileType.toLowerCase();

            switch(type) {
                case 'stl':
                    return await this.loadSTL(url);
                case '3mf':
                    return await this.load3MF(url);
                case 'obj':
                    return await this.loadOBJ(url);
                case 'ply':
                    return await this.loadPLY(url);
                case 'gltf':
                case 'glb':
                    return await this.loadGLTF(url);
                case 'dae':
                    return await this.loadDAE(url);
                case 'fbx':
                    return await this.loadFBX(url);
                case '3ds':
                    return await this.load3DS(url);
                case 'amf':
                    return await this.loadAMF(url);
                case 'gcode':
                    return await this.loadGCODE(url);
                case 'step':
                case 'stp':
                case 'iges':
                case 'igs':
                    return await this.loadCAD(url, type);
                default:
                    throw new Error('Unsupported file type: ' + fileType);
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

        if (width === 0 || height === 0) return;

        this.camera.aspect = width / height;
        this.camera.updateProjectionMatrix();
        this.renderer.setSize(width, height);

        // Re-render if model is loaded
        if (this.isReady) {
            this.renderer.render(this.scene, this.camera);
        }
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
    const thumbnails = document.querySelectorAll('.model-thumbnail[data-model-url], .model-detail-thumbnail[data-model-url]');

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

// Auto-init is handled by LazyModelLoader in main.js for better performance
// The lazy loader uses IntersectionObserver to only load visible thumbnails
