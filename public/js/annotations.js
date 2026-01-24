/**
 * 3D Model Annotations System
 *
 * Provides interactive annotations on 3D models using Three.js
 */

class AnnotationManager {
    constructor(options = {}) {
        this.modelId = options.modelId;
        this.scene = options.scene;
        this.camera = options.camera;
        this.renderer = options.renderer;
        this.controls = options.controls;
        this.mesh = options.mesh;
        this.container = options.container;
        this.onAnnotationClick = options.onAnnotationClick || (() => {});

        this.annotations = [];
        this.markers = [];
        this.raycaster = new THREE.Raycaster();
        this.mouse = new THREE.Vector2();
        this.enabled = false;
        this.addMode = false;

        this.init();
    }

    init() {
        // Create annotation group
        this.annotationGroup = new THREE.Group();
        this.annotationGroup.name = 'annotations';
        this.scene.add(this.annotationGroup);

        // Create HTML overlay for labels
        this.overlay = document.createElement('div');
        this.overlay.className = 'annotation-overlay';
        this.overlay.style.cssText = 'position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; overflow: hidden;';
        this.container.style.position = 'relative';
        this.container.appendChild(this.overlay);

        // Event listeners
        this.container.addEventListener('click', this.handleClick.bind(this));
        this.container.addEventListener('mousemove', this.handleMouseMove.bind(this));

        // Load existing annotations
        this.loadAnnotations();
    }

    async loadAnnotations() {
        try {
            const response = await fetch(`actions/annotations.php?action=list&model_id=${this.modelId}`);
            const data = await response.json();

            if (data.success) {
                this.annotations = data.annotations;
                this.renderAnnotations();
            }
        } catch (err) {
            console.error('Failed to load annotations:', err);
        }
    }

    renderAnnotations() {
        // Clear existing markers
        this.clearMarkers();

        // Create marker for each annotation
        this.annotations.forEach((annotation, index) => {
            this.createMarker(annotation, index);
        });

        this.updateLabels();
    }

    createMarker(annotation, index) {
        // Create sprite for marker
        const canvas = document.createElement('canvas');
        canvas.width = 64;
        canvas.height = 64;
        const ctx = canvas.getContext('2d');

        // Draw marker circle
        ctx.beginPath();
        ctx.arc(32, 32, 24, 0, Math.PI * 2);
        ctx.fillStyle = annotation.color || '#ff0000';
        ctx.fill();
        ctx.strokeStyle = 'white';
        ctx.lineWidth = 3;
        ctx.stroke();

        // Draw number
        ctx.fillStyle = 'white';
        ctx.font = 'bold 20px Arial';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(index + 1, 32, 32);

        const texture = new THREE.CanvasTexture(canvas);
        const material = new THREE.SpriteMaterial({
            map: texture,
            depthTest: false,
            sizeAttenuation: false
        });
        const sprite = new THREE.Sprite(material);

        sprite.position.set(
            annotation.position.x,
            annotation.position.y,
            annotation.position.z
        );
        sprite.scale.set(0.05, 0.05, 1);
        sprite.userData = { annotation, index };

        this.annotationGroup.add(sprite);
        this.markers.push(sprite);

        // Create HTML label
        const label = document.createElement('div');
        label.className = 'annotation-label';
        label.dataset.index = index;
        label.innerHTML = `
            <div class="annotation-label-header">
                <span class="annotation-number" style="background: ${annotation.color}">${index + 1}</span>
                <span class="annotation-author">${annotation.username}</span>
            </div>
            <div class="annotation-content">${this.escapeHtml(annotation.content)}</div>
        `;
        label.style.display = 'none';
        label.style.pointerEvents = 'auto';
        this.overlay.appendChild(label);
    }

    clearMarkers() {
        this.markers.forEach(marker => {
            this.annotationGroup.remove(marker);
            marker.material.dispose();
            marker.material.map?.dispose();
        });
        this.markers = [];
        this.overlay.innerHTML = '';
    }

    handleClick(event) {
        if (!this.enabled) return;

        const rect = this.container.getBoundingClientRect();
        this.mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
        this.mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

        this.raycaster.setFromCamera(this.mouse, this.camera);

        // If in add mode, try to place annotation on mesh
        if (this.addMode && this.mesh) {
            const meshIntersects = this.raycaster.intersectObject(this.mesh, true);
            if (meshIntersects.length > 0) {
                const intersect = meshIntersects[0];
                this.onAddAnnotation({
                    position: intersect.point.clone(),
                    normal: intersect.face ? intersect.face.normal.clone() : new THREE.Vector3(0, 0, 1)
                });
                return;
            }
        }

        // Check if clicking on existing marker
        const markerIntersects = this.raycaster.intersectObjects(this.markers);
        if (markerIntersects.length > 0) {
            const marker = markerIntersects[0].object;
            this.onAnnotationClick(marker.userData.annotation, marker.userData.index);
        }
    }

    handleMouseMove(event) {
        if (!this.enabled) return;

        const rect = this.container.getBoundingClientRect();
        this.mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
        this.mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

        // Hover effect
        this.raycaster.setFromCamera(this.mouse, this.camera);
        const intersects = this.raycaster.intersectObjects(this.markers);

        this.markers.forEach(marker => {
            marker.scale.set(0.05, 0.05, 1);
        });

        if (intersects.length > 0) {
            intersects[0].object.scale.set(0.07, 0.07, 1);
            this.container.style.cursor = 'pointer';
        } else if (this.addMode) {
            this.container.style.cursor = 'crosshair';
        } else {
            this.container.style.cursor = 'default';
        }
    }

    updateLabels() {
        // Update 2D positions of labels based on 3D positions
        this.markers.forEach((marker, index) => {
            const label = this.overlay.querySelector(`[data-index="${index}"]`);
            if (!label) return;

            const position = marker.position.clone();
            position.project(this.camera);

            const x = (position.x * 0.5 + 0.5) * this.container.clientWidth;
            const y = (-position.y * 0.5 + 0.5) * this.container.clientHeight;

            // Check if marker is in front of camera
            if (position.z < 1) {
                label.style.transform = `translate(${x + 20}px, ${y - 10}px)`;
                label.style.display = this.showLabels ? 'block' : 'none';
            } else {
                label.style.display = 'none';
            }
        });
    }

    onAddAnnotation(intersectData) {
        // Override this in implementation
        console.log('Add annotation at:', intersectData);
    }

    async addAnnotation(content, position, normal, color = '#ff0000') {
        try {
            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('model_id', this.modelId);
            formData.append('position_x', position.x);
            formData.append('position_y', position.y);
            formData.append('position_z', position.z);
            formData.append('normal_x', normal.x);
            formData.append('normal_y', normal.y);
            formData.append('normal_z', normal.z);
            formData.append('content', content);
            formData.append('color', color);

            const response = await fetch('actions/annotations.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.success) {
                this.annotations.push(data.annotation);
                this.renderAnnotations();
                return data.annotation;
            } else {
                throw new Error(data.error);
            }
        } catch (err) {
            console.error('Failed to add annotation:', err);
            throw err;
        }
    }

    async deleteAnnotation(annotationId) {
        try {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', annotationId);

            const response = await fetch('actions/annotations.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.success) {
                this.annotations = this.annotations.filter(a => a.id !== annotationId);
                this.renderAnnotations();
                return true;
            } else {
                throw new Error(data.error);
            }
        } catch (err) {
            console.error('Failed to delete annotation:', err);
            throw err;
        }
    }

    setEnabled(enabled) {
        this.enabled = enabled;
        this.annotationGroup.visible = enabled;
        if (!enabled) {
            this.overlay.innerHTML = '';
            this.container.style.cursor = 'default';
        } else {
            this.renderAnnotations();
        }
    }

    setAddMode(enabled) {
        this.addMode = enabled;
        this.container.style.cursor = enabled ? 'crosshair' : 'default';
    }

    setShowLabels(show) {
        this.showLabels = show;
        this.updateLabels();
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    update() {
        if (this.enabled) {
            this.updateLabels();
        }
    }

    dispose() {
        this.clearMarkers();
        this.scene.remove(this.annotationGroup);
        this.overlay.remove();
    }
}

// Export for use
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AnnotationManager;
}
