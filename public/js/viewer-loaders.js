/**
 * Model Viewer Loaders
 * Loader methods for various 3D file formats (STL, 3MF, OBJ, PLY, GLTF, etc.)
 * Extends ModelViewer class via prototype. Load AFTER viewer.js.
 */

ModelViewer.prototype.loadSTL = async function(url) {
    return new Promise(async (resolve, reject) => {
        try {
            const loader = new THREE.STLLoader();
            loader.load(
                url,
                (geometry) => {
                    this.clearModel();

                    // Ensure normals are computed correctly
                    geometry.computeVertexNormals();

                    const material = ModelViewer.getDefaultMaterial();

                    this.model = new THREE.Mesh(geometry, material);
                    this.centerAndScaleModel();
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
};

ModelViewer.prototype.load3MF = async function(url) {
    return new Promise(async (resolve, reject) => {
        try {
            const loader = new THREE.ThreeMFLoader();

            // Set a timeout to prevent hanging on problematic files
            const timeoutId = setTimeout(() => {
                reject(new Error('3MF loading timed out'));
            }, 30000);

            loader.load(
                url,
                (object) => {
                    clearTimeout(timeoutId);
                    try {
                        this.clearModel();

                        // 3MF files can contain multiple meshes
                        this.model = object;

                        // Apply material to all meshes
                        this.model.traverse((child) => {
                            if (child.isMesh) {
                                if (child.geometry) {
                                    child.geometry.computeVertexNormals();
                                }
                                child.material = ModelViewer.getDefaultMaterial();
                            }
                        });

                        this.centerAndScaleModel();
                        this.startAnimation();
                        resolve(this.model);
                    } catch (err) {
                        reject(new Error('Failed to process 3MF model: ' + err.message));
                    }
                },
                undefined,
                (error) => {
                    clearTimeout(timeoutId);
                    reject(error);
                }
            );
        } catch (error) {
            reject(new Error('3MF loading failed: ' + error.message));
        }
    });
};

ModelViewer.prototype.startAnimation = function() {
    if (!this.isReady) {
        this.isReady = true;
        this.animate();
    }
};

ModelViewer.prototype.loadGCODE = async function(url) {
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
                this.startAnimation();
                resolve(this.model);
            })
            .catch(error => reject(error));
    });
};

ModelViewer.prototype.parseGCODE = function(gcodeText) {
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
};

ModelViewer.prototype.loadOBJ = async function(url) {
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
                        child.material = ModelViewer.getDefaultMaterial();
                    }
                });

                this.centerAndScaleModel();
                this.startAnimation();
                resolve(this.model);
            },
            undefined,
            (error) => reject(error)
        );
    });
};

ModelViewer.prototype.loadPLY = async function(url) {
    return new Promise((resolve, reject) => {
        const loader = new THREE.PLYLoader();
        loader.load(
            url,
            (geometry) => {
                this.clearModel();
                geometry.computeVertexNormals();

                const material = new THREE.MeshStandardMaterial({
                    color: 0x888888,
                    roughness: 0.6,
                    metalness: 0.1,
                    vertexColors: geometry.hasAttribute('color')
                });

                this.model = new THREE.Mesh(geometry, material);
                this.centerAndScaleModel();
                this.startAnimation();
                resolve(this.model);
            },
            undefined,
            (error) => reject(error)
        );
    });
};

ModelViewer.prototype.loadGLTF = async function(url) {
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
                this.startAnimation();
                resolve(this.model);
            },
            undefined,
            (error) => reject(error)
        );
    });
};

ModelViewer.prototype.loadDAE = async function(url) {
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
                this.startAnimation();
                resolve(this.model);
            },
            undefined,
            (error) => reject(error)
        );
    });
};

ModelViewer.prototype.loadFBX = async function(url) {
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
                this.startAnimation();
                resolve(this.model);
            },
            undefined,
            (error) => reject(error)
        );
    });
};

ModelViewer.prototype.load3DS = async function(url) {
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
                this.startAnimation();
                resolve(this.model);
            },
            undefined,
            (error) => reject(error)
        );
    });
};

ModelViewer.prototype.loadAMF = async function(url) {
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
                            child.material = ModelViewer.getDefaultMaterial();
                        }
                    }
                });

                this.centerAndScaleModel();
                this.startAnimation();
                resolve(this.model);
            },
            undefined,
            (error) => reject(error)
        );
    });
};

ModelViewer.prototype.loadCAD = async function(url, fileType) {
    // Lazy load OpenCascade.js only when needed
    if (!window.occtImportJS) {
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
            this.startAnimation();
            resolve(this.model);
        } catch (error) {
            reject(error);
        }
    });
};

ModelViewer.prototype.loadOpenCascade = async function() {
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
};

ModelViewer.prototype.convertOCCTToThree = function(result) {
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

            const material = new THREE.MeshStandardMaterial({
                color: 0x888888,
                roughness: 0.6,
                metalness: 0.1,
                side: THREE.DoubleSide
            });

            const meshObj = new THREE.Mesh(geometry, material);
            group.add(meshObj);
        });
    }

    return group;
};

ModelViewer.prototype.loadModel = async function(url, fileType) {
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
};

ModelViewer.prototype.centerAndScaleModel = function() {
    if (!this.model) return;

    // Reset transforms before measuring
    this.model.position.set(0, 0, 0);
    this.model.scale.set(1, 1, 1);
    this.model.rotation.set(0, 0, 0);

    // Compute bounding box
    const box = new THREE.Box3().setFromObject(this.model);
    const center = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3());

    // Scale to fit in view
    const maxDim = Math.max(size.x, size.y, size.z);
    const scale = maxDim > 0 ? 50 / maxDim : 1;
    this.model.scale.setScalar(scale);

    // Center the model (offset must account for scale)
    this.model.position.set(
        -center.x * scale,
        -center.y * scale,
        -center.z * scale
    );

    // Wrap model in a pivot group so rotation happens around the visual center.
    // The model's position offset centers geometry inside the pivot;
    // rotating the pivot then orbits around origin correctly.
    if (this.pivot) {
        this.scene.remove(this.pivot);
    }
    this.pivot = new THREE.Group();
    this.scene.remove(this.model);
    this.pivot.add(this.model);
    this.scene.add(this.pivot);

    // Position camera
    this.camera.position.set(0, 30, 80);
    this.camera.lookAt(0, 0, 0);

    if (this.controls) {
        this.controls.target.set(0, 0, 0);
        this.controls.update();
    }
};

ModelViewer.prototype.clearModel = function() {
    if (this.pivot) {
        this.scene.remove(this.pivot);
        this.pivot = null;
    }
    if (this.model) {
        this.scene.remove(this.model);
        // Dispose all geometries and materials in the tree (handles Group/multi-mesh models)
        this.model.traverse(function(child) {
            if (child.geometry) child.geometry.dispose();
            if (child.material) {
                if (Array.isArray(child.material)) {
                    child.material.forEach(function(m) { m.dispose(); });
                } else {
                    child.material.dispose();
                }
            }
        });
        this.model = null;
    }
};

ModelViewer.prototype.onResize = function() {
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
};

ModelViewer.prototype.animate = function() {
    // Stop animation loop when scrolled off-screen
    if (!this.isVisible) {
        this.animationId = null;
        return;
    }

    this.animationId = requestAnimationFrame(() => this.animate());

    // Throttle non-interactive thumbnails to 30fps (saves ~50% GPU)
    if (!this.controls) {
        const now = performance.now();
        if (now - this.lastFrameTime < 33) return; // ~30fps
        this.lastFrameTime = now;
    }

    // Auto-rotate if no controls (rotate pivot so it orbits around model center)
    if (!this.controls && this.pivot && this.options.autoRotate) {
        this.pivot.rotation.y += 0.01;
    }

    if (this.controls) {
        this.controls.update();
    }

    this.renderer.render(this.scene, this.camera);
};

ModelViewer.prototype.dispose = function() {
    if (this.animationId) {
        cancelAnimationFrame(this.animationId);
    }

    if (this.resizeObserver) {
        this.resizeObserver.disconnect();
    }

    if (this.visibilityObserver) {
        this.visibilityObserver.disconnect();
    }

    this.clearModel();

    if (this.renderer) {
        this.renderer.dispose();
        this.container.removeChild(this.renderer.domElement);
    }

    if (this.controls) {
        this.controls.dispose();
    }
};

// =====================
// Progressive Mesh Loading
// =====================
