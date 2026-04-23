/**
 * Model Parts Management
 * Handles part preview, mass actions, folder management, drag/drop reordering, and batch rename.
 * Loaded on the model detail page before model-page.js.
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


        // Mass action handling
        const partCheckboxes = document.querySelectorAll('.part-checkbox');
        const massActionsBar = document.getElementById('parts-mass-actions');
        const selectedCountEl = document.getElementById('selected-count');
        const massDeleteBtn = document.getElementById('mass-delete-parts');

        function updateMassActionsVisibility() {
            const checkedBoxes = document.querySelectorAll('.part-checkbox:checked');
            const count = checkedBoxes.length;

            if (massActionsBar) {
                massActionsBar.style.display = count > 0 ? 'flex' : 'none';
            }
            if (selectedCountEl) {
                selectedCountEl.textContent = count;
            }
        }

        function getSelectedPartIds() {
            return Array.from(document.querySelectorAll('.part-checkbox:checked')).map(cb => cb.value);
        }

        async function massUpdatePrintType(selectEl) {
            const printType = selectEl.value;
            if (!printType) return;

            const ids = getSelectedPartIds();
            if (ids.length === 0) {
                showToast('No parts selected', 'error');
                selectEl.value = '';
                return;
            }

            const actualType = printType === 'clear' ? '' : printType;
            const label = printType === 'clear' ? 'none' : printType.toUpperCase();
            if (!await showConfirm('Set print type to ' + label + ' for ' + ids.length + ' selected part(s)?')) {
                selectEl.value = '';
                return;
            }

            let success = 0;
            for (const partId of ids) {
                const formData = new FormData();
                formData.append('part_id', partId);
                formData.append('print_type', actualType);
                formData.append('csrf_token', ModelPageConfig.csrfToken);
                try {
                    const resp = await fetch(ModelPageConfig.updatePartRoute, { method: 'POST', body: formData });
                    const data = await resp.json();
                    if (data.success) {
                        success++;
                        // Update the individual dropdown
                        const partEl = document.querySelector('[data-part-id="' + partId + '"].print-type-select:not(.folder-print-type)');
                        if (partEl) {
                            partEl.value = actualType;
                            partEl.dataset.prev = actualType;
                        }
                    }
                } catch (e) {
                    console.error('Failed to update print type:', e);
                }
            }
            selectEl.value = '';
        }

        function toggleSelectAllParts(checkbox) {
            document.querySelectorAll('.part-checkbox').forEach(cb => cb.checked = checkbox.checked);
            updateMassActionsVisibility();
            updateAllCheckboxStates();
        }

        function selectFolderParts(checkbox) {
            const folder = checkbox.closest('.parts-group');
            if (!folder) return;
            const checkboxes = folder.querySelectorAll('.part-checkbox');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
            updateMassActionsVisibility();
            updateAllCheckboxStates();
        }

        function updateAllCheckboxStates() {
            const selectAllCheckbox = document.getElementById('select-all-parts');
            const allPartCheckboxes = document.querySelectorAll('.part-checkbox');
            const checkedCount = document.querySelectorAll('.part-checkbox:checked').length;

            if (selectAllCheckbox) {
                selectAllCheckbox.checked = checkedCount === allPartCheckboxes.length && allPartCheckboxes.length > 0;
                selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < allPartCheckboxes.length;
            }
            updateFolderCheckboxes();
        }

        function updateFolderCheckboxes() {
            document.querySelectorAll('.parts-group').forEach(folder => {
                const folderCheckbox = folder.querySelector('.folder-checkbox');
                if (!folderCheckbox) return;
                const partCheckboxes = folder.querySelectorAll('.part-checkbox');
                const checkedCount = folder.querySelectorAll('.part-checkbox:checked').length;
                folderCheckbox.checked = checkedCount === partCheckboxes.length && partCheckboxes.length > 0;
                folderCheckbox.indeterminate = checkedCount > 0 && checkedCount < partCheckboxes.length;
            });
        }

        partCheckboxes.forEach(cb => {
            cb.addEventListener('change', function() {
                updateMassActionsVisibility();
                updateAllCheckboxStates();
            });
        });

        if (massDeleteBtn) {
            massDeleteBtn.addEventListener('click', async function() {
                const ids = getSelectedPartIds();
                if (ids.length === 0) return;

                if (!await showConfirm(`Delete ${ids.length} selected parts? This cannot be undone.`)) {
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'delete_parts');
                ids.forEach(id => formData.append('ids[]', id));

                try {
                    const response = await fetch('/actions/mass-action', { method: 'POST', body: formData });
                    const result = await response.json();

                    if (result.success) {
                        location.reload();
                    } else {
                        showToast('Failed: ' + (result.error || 'Unknown error'), 'error');
                    }
                } catch (err) {
                    console.error('Mass delete error:', err);
                    showToast('Failed to delete parts', 'error');
                }
            });
        }

        // Mass convert to 3MF
        const massConvertBtn = document.getElementById('mass-convert-3mf');
        if (massConvertBtn) {
            massConvertBtn.addEventListener('click', async function() {
                const ids = getSelectedPartIds();
                if (ids.length === 0) return;

                // Filter to only STL parts
                const stlParts = ids.filter(id => {
                    const partItem = document.querySelector(`.part-item[data-part-id="${id}"]`);
                    return partItem && partItem.dataset.partType === 'stl';
                });

                if (stlParts.length === 0) {
                    showToast('No STL files selected. Only STL files can be converted to 3MF.', 'error');
                    return;
                }

                if (!await showConfirm(`Convert ${stlParts.length} STL file(s) to 3MF? This will replace the original files.`)) {
                    return;
                }

                massConvertBtn.disabled = true;
                massConvertBtn.textContent = 'Queuing...';

                try {
                    const formData = new FormData();
                    formData.append('action', 'batch');
                    stlParts.forEach(id => formData.append('part_ids[]', id));

                    const response = await fetch('/actions/convert-part', { method: 'POST', body: formData });
                    const result = await response.json();

                    if (result.success && result.queued > 0) {
                        massConvertBtn.textContent = `Queued ${result.queued}`;
                        if (typeof refreshQueueStatus === 'function') refreshQueueStatus();
                    } else {
                        showToast('Failed to queue conversions', 'error');
                        massConvertBtn.textContent = 'Convert to 3MF';
                        massConvertBtn.disabled = false;
                    }
                } catch (err) {
                    showToast('Failed to queue conversions', 'error');
                    massConvertBtn.textContent = 'Convert to 3MF';
                    massConvertBtn.disabled = false;
                }
            });
        }

        // Update print type for a part
        async function updatePrintType(selectEl) {
            const partId = selectEl.dataset.partId;
            const printType = selectEl.value;
            const formData = new FormData();
            formData.append('part_id', partId);
            formData.append('print_type', printType);
            formData.append('csrf_token', ModelPageConfig.csrfToken);
            try {
                const resp = await fetch(ModelPageConfig.updatePartRoute, { method: 'POST', body: formData });
                const data = await resp.json();
                if (!data.success) {
                    showToast(data.error || 'Failed to update print type', 'error');
                    selectEl.value = selectEl.dataset.prev || '';
                }
                selectEl.dataset.prev = selectEl.value;
            } catch (e) {
                showToast('Failed to update print type', 'error');
            }
        }

        async function updateFolderPrintType(selectEl, folderName) {
            const printType = selectEl.value;
            const label = printType ? printType.toUpperCase() : 'none';
            if (!await showConfirm('Set print type to ' + label + ' for all parts in "' + folderName + '"?')) {
                selectEl.value = '';
                return;
            }

            const formData = new FormData();
            formData.append('action', 'set_print_type');
            formData.append('model_id', ModelPageConfig.modelId);
            formData.append('folder_name', folderName);
            formData.append('print_type', printType);
            formData.append('csrf_token', ModelPageConfig.csrfToken);

            try {
                const resp = await fetch('/actions/part-folders', { method: 'POST', body: formData });
                const data = await resp.json();
                if (data.success) {
                    // Update all part dropdowns in this folder
                    const folder = selectEl.closest('.parts-group');
                    if (folder) {
                        folder.querySelectorAll('.print-type-select:not(.folder-print-type)').forEach(sel => {
                            sel.value = printType;
                            sel.dataset.prev = printType;
                        });
                    }
                    selectEl.value = '';
                } else {
                    showToast(data.error || 'Failed to update print type', 'error');
                    selectEl.value = '';
                }
            } catch (e) {
                showToast('Failed to update folder print type', 'error');
                selectEl.value = '';
            }
        }

        // Upload parts function — uses TUS chunked resumable uploads
        async function uploadParts(files) {
            if (!files || files.length === 0) return;
            if (typeof tus === 'undefined') {
                showToast('Upload library not loaded. Please refresh and try again.', 'error');
                return;
            }

            const modelId = ModelPageConfig.modelId;
            const totalFiles = files.length;
            let completedFiles = 0;
            let errorCount = 0;

            // Disable add-parts buttons
            document.querySelectorAll('.trigger-add-parts').forEach(function(btn) {
                btn.disabled = true;
                btn._originalText = btn.textContent;
                btn.textContent = 'Uploading...';
            });

            // Create or show progress container
            let progressContainer = document.getElementById('add-parts-progress');
            if (!progressContainer) {
                progressContainer = document.createElement('div');
                progressContainer.id = 'add-parts-progress';
                progressContainer.className = 'add-parts-progress';
                progressContainer.innerHTML =
                    '<div class="progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="Upload progress">' +
                        '<div class="progress-bar-fill" style="width: 0%"></div>' +
                    '</div>' +
                    '<div class="add-parts-progress-text"></div>';
                // Insert before the parts actions area
                var partsActions = document.querySelector('.parts-actions');
                if (partsActions) {
                    partsActions.parentNode.insertBefore(progressContainer, partsActions);
                } else {
                    document.querySelector('.model-parts')?.appendChild(progressContainer);
                }
            }
            progressContainer.style.display = 'block';
            var progressFill = progressContainer.querySelector('.progress-bar-fill');
            var progressText = progressContainer.querySelector('.add-parts-progress-text');

            function formatSize(bytes) {
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
                return (bytes / 1048576).toFixed(1) + ' MB';
            }

            function updateOverallProgress(fileIndex, filePercent) {
                var overall = Math.round(((fileIndex + filePercent / 100) / totalFiles) * 100);
                progressFill.style.width = overall + '%';
                progressFill.parentElement.setAttribute('aria-valuenow', overall);
            }

            // Upload files sequentially using TUS
            for (let i = 0; i < totalFiles; i++) {
                const file = files[i];
                progressText.textContent = 'Uploading ' + (i + 1) + ' of ' + totalFiles + ': ' + file.name;
                updateOverallProgress(i, 0);

                try {
                    await new Promise(function(resolve, reject) {
                        var upload = new tus.Upload(file, {
                            endpoint: '/actions/tus',
                            chunkSize: 5 * 1024 * 1024,
                            retryDelays: [0, 1000, 3000, 5000],
                            metadata: {
                                filename: file.name,
                                filetype: file.type || file.name.split('.').pop(),
                                parent_id: String(modelId)
                            },
                            onProgress: function(bytesUploaded, bytesTotal) {
                                var percent = Math.round((bytesUploaded / bytesTotal) * 100);
                                updateOverallProgress(i, percent);
                                progressText.textContent = 'Uploading ' + (i + 1) + '/' + totalFiles + ': ' + file.name + ' — ' + percent + '% (' + formatSize(bytesUploaded) + ' / ' + formatSize(bytesTotal) + ')';
                            },
                            onSuccess: function() {
                                completedFiles++;
                                resolve();
                            },
                            onError: function(error) {
                                errorCount++;
                                console.error('TUS upload failed for', file.name, ':', error);
                                resolve(); // continue with next file
                            }
                        });
                        upload.start();
                    });
                } catch (err) {
                    errorCount++;
                    console.error('Upload error for', file.name, ':', err);
                }
            }

            // Reset file input
            document.getElementById('add-part-file').value = '';

            // Show completion
            if (completedFiles > 0) {
                progressText.textContent = 'Processing ' + completedFiles + ' file(s)...';
                progressFill.style.width = '100%';
                if (errorCount > 0) {
                    showToast('Uploaded ' + completedFiles + ' files. ' + errorCount + ' failed.', 'error');
                }
                // Reload after a brief delay to let queue process
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                progressContainer.style.display = 'none';
                document.querySelectorAll('.trigger-add-parts').forEach(function(btn) {
                    btn.textContent = btn._originalText || 'Add Parts';
                    btn.disabled = false;
                });
                showToast('Upload failed. ' + errorCount + ' file(s) could not be uploaded.', 'error');
            }
        }

        // Folder management
        function showMoreParts(btn) {
            const list = btn.closest('.parts-list');
            list.querySelectorAll('.part-hidden').forEach(function(el) {
                el.classList.remove('part-hidden');
            });
            btn.remove();
        }

        function toggleFolder(groupEl) {
            groupEl.classList.toggle('collapsed');
            const header = groupEl.querySelector('.parts-group-header');
            if (header) header.setAttribute('aria-expanded', !groupEl.classList.contains('collapsed'));
            const folder = groupEl.dataset.folder;
            const key = `model_${ModelPageConfig.modelId}_folder_` + folder;
            sessionStorage.setItem(key, groupEl.classList.contains('collapsed') ? '1' : '0');
            updateCollapseAllToggle();
        }

        function toggleCollapseAllGroups() {
            const groups = document.querySelectorAll('.parts-group[data-folder]');
            const allCollapsed = Array.from(groups).every(g => g.classList.contains('collapsed'));
            groups.forEach(group => {
                if (allCollapsed) {
                    group.classList.remove('collapsed');
                } else {
                    group.classList.add('collapsed');
                }
                const header = group.querySelector('.parts-group-header');
                if (header) header.setAttribute('aria-expanded', allCollapsed);
                const folder = group.dataset.folder;
                const key = `model_${ModelPageConfig.modelId}_folder_` + folder;
                sessionStorage.setItem(key, allCollapsed ? '0' : '1');
            });
            updateCollapseAllToggle();
        }

        function updateCollapseAllToggle() {
            const toggle = document.querySelector('.collapse-all-toggle');
            if (!toggle) return;
            const groups = document.querySelectorAll('.parts-group[data-folder]');
            const allCollapsed = Array.from(groups).every(g => g.classList.contains('collapsed'));
            toggle.classList.toggle('all-collapsed', allCollapsed);
        }

        // Restore collapsed folder states on page load
        document.querySelectorAll('.parts-group[data-folder]').forEach(group => {
            const folder = group.dataset.folder;
            const key = `model_${ModelPageConfig.modelId}_folder_` + folder;
            if (sessionStorage.getItem(key) === '1') {
                group.classList.add('collapsed');
                const header = group.querySelector('.parts-group-header');
                if (header) header.setAttribute('aria-expanded', 'false');
            }
        });
        updateCollapseAllToggle();

        // Nest folders toggle — moves subfolder groups inside parent folder groups
        (function() {
            var nestBtn = document.querySelector('.nest-folders-toggle');
            if (!nestBtn) return;

            var modelParts = document.querySelector('.model-parts');
            var storageKey = 'meshsilo_nest_folders_' + ModelPageConfig.modelId;
            var isNested = false;
            var originalPositions = [];
            var createdVirtualGroups = [];

            function getTopLevelGroups() {
                return Array.from(modelParts.querySelectorAll(':scope > .parts-group[data-folder]'));
            }

            // Any folder with a / in its path can be nested
            function hasNestableRelationships() {
                var groups = getTopLevelGroups();
                for (var i = 0; i < groups.length; i++) {
                    var folder = groups[i].dataset.folder;
                    if (folder !== 'Root' && folder.indexOf('/') !== -1) return true;
                }
                return false;
            }

            if (!hasNestableRelationships()) {
                nestBtn.style.display = 'none';
                return;
            }

            function nestFolders() {
                var groups = getTopLevelGroups();
                originalPositions = groups.map(function(g) {
                    return { el: g, parent: g.parentNode, next: g.nextSibling };
                });

                // Build map of existing folder groups
                var folderMap = {};
                groups.forEach(function(g) { folderMap[g.dataset.folder] = g; });

                // Collect all intermediate ancestor paths that need virtual groups
                var neededParents = {};
                groups.forEach(function(group) {
                    var folder = group.dataset.folder;
                    if (folder === 'Root') return;
                    var segments = folder.split('/');
                    for (var len = 1; len < segments.length; len++) {
                        var ancestorPath = segments.slice(0, len).join('/');
                        if (!folderMap[ancestorPath]) {
                            neededParents[ancestorPath] = segments[len - 1];
                        }
                    }
                });

                // Create virtual parent groups sorted by depth (shallowest first)
                var parentPaths = Object.keys(neededParents).sort(function(a, b) {
                    return a.split('/').length - b.split('/').length;
                });
                // Find insertion point — before the parts-actions/download area
                var insertBefore = modelParts.querySelector('.parts-actions') ||
                                   modelParts.querySelector('.model-download') ||
                                   null;

                parentPaths.forEach(function(path) {
                    var name = neededParents[path];
                    // Count parts in all child folders of this path
                    var childCount = 0;
                    groups.forEach(function(g) {
                        if (g.dataset.folder.indexOf(path + '/') === 0 || g.dataset.folder === path) {
                            childCount += g.querySelectorAll('.part-item').length;
                        }
                    });
                    var el = document.createElement('div');
                    el.className = 'parts-group';
                    el.dataset.folder = path;
                    el.dataset.virtual = 'true';
                    el.innerHTML =
                        '<h3 class="parts-group-header" tabindex="0" role="button" aria-expanded="true">' +
                            '<span class="folder-toggle" aria-hidden="true">&#9660;</span> ' +
                            name +
                            (childCount ? ' <span class="folder-part-count">(' + childCount + ')</span>' : '') +
                        '</h3>' +
                        '<div class="parts-list"></div>';
                    el.querySelector('.parts-group-header').addEventListener('click', function() {
                        toggleFolder(el);
                    });
                    folderMap[path] = el;
                    createdVirtualGroups.push(el);
                    if (insertBefore) {
                        modelParts.insertBefore(el, insertBefore);
                    } else {
                        modelParts.appendChild(el);
                    }
                });

                // Shorten nested folder names: "Aarakocra/Aarakocra OLD" → "Aarakocra OLD"
                groups.forEach(function(group) {
                    var folder = group.dataset.folder;
                    if (folder === 'Root' || folder.indexOf('/') === -1) return;
                    var headerEl = group.querySelector(':scope > .parts-group-header');
                    if (!headerEl) return;
                    if (!group.dataset.origHeaderHtml) {
                        group.dataset.origHeaderHtml = headerEl.innerHTML;
                    }
                    var leafName = folder.split('/').pop();
                    // Find text nodes that contain a / (the full path display name)
                    var walker = document.createTreeWalker(headerEl, NodeFilter.SHOW_TEXT);
                    var node;
                    while (node = walker.nextNode()) {
                        if (node.textContent.indexOf('/') !== -1) {
                            node.textContent = node.textContent.replace(/\S*\/\S*/g, leafName);
                            break;
                        }
                    }
                });

                // Nest all groups from deepest to shallowest
                var allPaths = Object.keys(folderMap).sort(function(a, b) {
                    return b.split('/').length - a.split('/').length;
                });
                allPaths.forEach(function(folder) {
                    if (folder === 'Root') return;
                    var group = folderMap[folder];
                    var segments = folder.split('/');
                    if (segments.length < 2) return;
                    var parentPath = segments.slice(0, -1).join('/');
                    var parentGroup = folderMap[parentPath];
                    if (parentGroup) {
                        var parentList = parentGroup.querySelector(':scope > .parts-list');
                        if (parentList) {
                            group.classList.add('nested-subfolder');
                            parentList.appendChild(group);
                        }
                    }
                });

                isNested = true;
                nestBtn.classList.add('active');
                nestBtn.setAttribute('aria-pressed', 'true');
                localStorage.setItem(storageKey, '1');
            }

            function unnestFolders() {
                // Restore original header text on nested groups
                originalPositions.forEach(function(pos) {
                    if (pos.el.dataset.origHeaderHtml) {
                        var headerEl = pos.el.querySelector(':scope > .parts-group-header');
                        if (headerEl) headerEl.innerHTML = pos.el.dataset.origHeaderHtml;
                        delete pos.el.dataset.origHeaderHtml;
                    }
                });

                // Restore original groups to their positions
                originalPositions.forEach(function(pos) {
                    pos.el.classList.remove('nested-subfolder');
                    if (pos.next && pos.next.parentNode === pos.parent) {
                        pos.parent.insertBefore(pos.el, pos.next);
                    } else {
                        pos.parent.appendChild(pos.el);
                    }
                });
                originalPositions = [];

                // Remove virtual parent groups
                createdVirtualGroups.forEach(function(g) { g.remove(); });
                createdVirtualGroups = [];

                isNested = false;
                nestBtn.classList.remove('active');
                nestBtn.setAttribute('aria-pressed', 'false');
                localStorage.setItem(storageKey, '0');
            }

            nestBtn.addEventListener('click', function() {
                if (isNested) {
                    unnestFolders();
                } else {
                    nestFolders();
                }
            });

            if (localStorage.getItem(storageKey) === '1') {
                nestFolders();
            }
        })();

        function showCreateFolderModal() {
            const modal = document.getElementById('create-folder-modal');
            modal.style.display = 'flex';
            trapFocus(modal);
        }

        function closeCreateFolderModal() {
            const modal = document.getElementById('create-folder-modal');
            releaseFocus(modal);
            modal.style.display = 'none';
            document.getElementById('new-folder-name').value = '';
        }

        document.getElementById('create-folder-modal')?.addEventListener('click', function(e) {
            if (e.target === this) closeCreateFolderModal();
        });

        async function submitCreateFolder(e) {
            e.preventDefault();
            const name = document.getElementById('new-folder-name').value.trim();
            if (!name) return;

            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('model_id', ModelPageConfig.modelId);
            formData.append('folder_name', name);

            try {
                const response = await fetch('/actions/part-folders', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    location.reload();
                } else {
                    showToast('Failed: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Create folder error:', err);
                showToast('Failed to create folder', 'error');
            }
        }

        async function renameFolder(oldName) {
            const newName = await showPrompt('Rename folder:', oldName);
            if (!newName || newName.trim() === '' || newName.trim() === oldName) return;

            const formData = new FormData();
            formData.append('action', 'rename');
            formData.append('model_id', ModelPageConfig.modelId);
            formData.append('old_folder', oldName);
            formData.append('new_folder', newName.trim());

            try {
                const response = await fetch('/actions/part-folders', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    location.reload();
                } else {
                    showToast('Failed: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Rename folder error:', err);
                showToast('Failed to rename folder', 'error');
            }
        }

        async function deleteFolder(folderName) {
            if (!await showConfirm('Delete folder "' + folderName + '"? Parts will be moved to root.')) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('model_id', ModelPageConfig.modelId);
            formData.append('folder_name', folderName);

            try {
                const response = await fetch('/actions/part-folders', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    location.reload();
                } else {
                    showToast('Failed: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Delete folder error:', err);
                showToast('Failed to delete folder', 'error');
            }
        }

        let movingPartIds = [];

        function showMoveFolderModal(partIds) {
            movingPartIds = partIds;
            document.getElementById('move-part-ids').value = partIds.join(',');
            const modal = document.getElementById('move-folder-modal');
            modal.style.display = 'flex';
            trapFocus(modal);
            // Uncheck all radios
            document.querySelectorAll('#move-folder-list input[type="radio"]').forEach(r => r.checked = false);
        }

        function closeMoveFolderModal() {
            const modal = document.getElementById('move-folder-modal');
            releaseFocus(modal);
            modal.style.display = 'none';
            movingPartIds = [];
        }

        document.getElementById('move-folder-modal')?.addEventListener('click', function(e) {
            if (e.target === this) closeMoveFolderModal();
        });

        async function submitMoveToFolder() {
            const selected = document.querySelector('#move-folder-list input[type="radio"]:checked');
            if (!selected) {
                showToast('Please select a folder', 'error');
                return;
            }

            const targetFolder = selected.value;
            const formData = new FormData();
            formData.append('action', 'move');
            formData.append('model_id', ModelPageConfig.modelId);
            formData.append('target_folder', targetFolder);
            movingPartIds.forEach(id => formData.append('part_ids[]', id));

            try {
                const response = await fetch('/actions/part-folders', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    location.reload();
                } else {
                    showToast('Failed: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Move to folder error:', err);
                showToast('Failed to move parts', 'error');
            }
        }


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
            script.onload = initSortable;
            document.head.appendChild(script);
        })();

        // Batch rename
        let batchRenamePartIds = [];

        function showBatchRenameModal(partIds) {
            batchRenamePartIds = partIds;
            document.getElementById('rename-pattern').value = '{name}';
            document.getElementById('rename-prefix').value = '';
            document.getElementById('rename-suffix').value = '';
            const modal = document.getElementById('batch-rename-modal');
            modal.style.display = 'flex';
            trapFocus(modal);
            updateRenamePreview();
        }

        function closeBatchRenameModal() {
            const modal = document.getElementById('batch-rename-modal');
            releaseFocus(modal);
            modal.style.display = 'none';
            batchRenamePartIds = [];
        }

        function updateRenamePreview() {
            const pattern = document.getElementById('rename-pattern').value || '{name}';
            const prefix = document.getElementById('rename-prefix').value;
            const suffix = document.getElementById('rename-suffix').value;
            const previewList = document.getElementById('rename-preview-list');

            // Get part names for preview
            const previews = batchRenamePartIds.slice(0, 3).map((id, idx) => {
                const partEl = document.querySelector(`.part-item[data-part-id="${id}"]`);
                if (!partEl) return null;
                const name = partEl.dataset.partName;
                const ext = partEl.dataset.partType;

                let newName = pattern
                    .replace('{name}', name)
                    .replace('{index}', idx + 1)
                    .replace('{ext}', ext);
                newName = prefix + newName + suffix;

                return { old: name, new: newName };
            }).filter(Boolean);

            previewList.innerHTML = previews.map(p =>
                `<li><span class="old-name">${escapeHtml(p.old)}</span> &rarr; <span class="new-name">${escapeHtml(p.new)}</span></li>`
            ).join('');

            if (batchRenamePartIds.length > 3) {
                previewList.innerHTML += `<li>...and ${batchRenamePartIds.length - 3} more</li>`;
            }
        }

        async function applyBatchRename() {
            const pattern = document.getElementById('rename-pattern').value || '{name}';
            const prefix = document.getElementById('rename-prefix').value;
            const suffix = document.getElementById('rename-suffix').value;

            if (!pattern && !prefix && !suffix) {
                showToast('Please enter a pattern, prefix, or suffix', 'error');
                return;
            }

            try {
                const response = await fetch('/actions/batch-rename', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        parent_id: ModelPageConfig.modelId,
                        part_ids: batchRenamePartIds,
                        pattern: pattern,
                        prefix: prefix,
                        suffix: suffix
                    })
                });

                const result = await response.json();
                if (result.success) {
                    location.reload();
                } else {
                    showToast(result.error, 'error');
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        }


