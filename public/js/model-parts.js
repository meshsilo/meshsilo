/**
 * Model Parts Management - Core (part preview + drag/drop reordering)
 * Loaded on the model detail page before model-page.js.
 *
 * This file was split by concern; the siblings load right after it (see
 * includes/header.php) and must stay in this order, before model-page.js:
 *   - model-parts-selection.js : selection, mass actions, per-part/mass print type, batch rename
 *   - model-parts-folders.js   : folder CRUD, collapse/expand, nesting, move-to-folder
 *   - model-parts-upload.js    : add-parts TUS upload + progress
 * Shared helpers (escapeHtml, showToast, showConfirm, showPrompt, trapFocus,
 * releaseFocus) come from ui-common.js; window.ModelPageConfig is set inline by model.php.
 */

        // Part preview modal
        let partPreviewViewer = null;

        function openPartPreview(path, type, name) {
            const modal = document.getElementById('part-preview-modal');
            const container = document.getElementById('part-preview-container');
            const nameEl = document.getElementById('preview-part-name');

            nameEl.textContent = name;
            modal.style.display = 'flex';
            trapFocus(modal);

            // Clear previous viewer
            if (partPreviewViewer) {
                partPreviewViewer.dispose();
                partPreviewViewer = null;
            }
            container.innerHTML = '';

            // Check if file type supports 3D preview
            const previewableTypes = ['stl', '3mf', 'obj', 'ply', 'gltf', 'glb', 'dae', 'fbx', '3ds', 'amf', 'gcode', 'step', 'stp', 'iges', 'igs'];
            if (!previewableTypes.includes(type.toLowerCase())) {
                container.innerHTML = '<p style="text-align: center; padding: 2rem; color: var(--color-text-muted);">No preview available for .' + escapeHtml(type) + ' files</p>';
                return;
            }

            // Create new viewer
            partPreviewViewer = new window.ModelViewer(container, {
                autoRotate: false,
                interactive: true,
                backgroundColor: 0x1e293b
            });

            partPreviewViewer.loadModel(path, type).catch(err => {
                console.error('Failed to load part:', err);
                container.innerHTML = '<p style="text-align: center; padding: 2rem; color: var(--color-text-muted);">Failed to load 3D preview</p>';
            });
        }

        function closePartPreview() {
            const modal = document.getElementById('part-preview-modal');
            releaseFocus(modal);
            modal.style.display = 'none';

            if (partPreviewViewer) {
                partPreviewViewer.dispose();
                partPreviewViewer = null;
            }
        }

        // Close modal on background click
        document.getElementById('part-preview-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closePartPreview();
            }
        });

        // Close any open modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.modal-overlay');
                modals.forEach(function(modal) {
                    if (modal.style.display !== 'none' && modal.id !== 'confirm-modal' && modal.id !== 'prompt-modal') {
                        const closeBtn = modal.querySelector('.modal-close');
                        if (closeBtn) closeBtn.click();
                    }
                });
            }
        });


        // Drag and drop reordering
        function initSortable() {
            document.querySelectorAll('.parts-list').forEach(list => {
                new Sortable(list, {
                    handle: '.drag-handle',
                    animation: 150,
                    ghostClass: 'part-item-ghost',
                    chosenClass: 'part-item-chosen',
                    onEnd: async function() {
                        // Collect all part IDs in new order
                        const partIds = Array.from(list.querySelectorAll('.part-item'))
                            .map(item => item.dataset.partId);

                        const formData = new FormData();
                        formData.append('parent_id', ModelPageConfig.modelId);
                        partIds.forEach(id => formData.append('part_ids[]', id));

                        try {
                            const response = await fetch('/actions/reorder-parts', {
                                method: 'POST',
                                body: formData
                            });
                            const result = await response.json();
                            if (!result.success) {
                                console.error('Failed to save order:', result.error);
                                location.reload(); // Revert on failure
                            }
                        } catch (err) {
                            console.error('Error saving order:', err);
                            location.reload();
                        }
                    }
                });
            });
        }

        // Load SortableJS and initialize
        (function() {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js';
            script.integrity = 'sha384-HZZ/fukV+9G8gwTNjN7zQDG0Sp7MsZy5DDN6VfY3Be7V9dvQpEpR2jF2HlyFUUjU';
            script.crossOrigin = 'anonymous';
            script.onload = initSortable;
            document.head.appendChild(script);
        })();
