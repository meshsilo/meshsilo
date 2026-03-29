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

        // Upload parts function
        async function uploadParts(files) {
            if (!files || files.length === 0) return;

            const modelId = ModelPageConfig.modelId;
            let successCount = 0;
            let errorCount = 0;

            // Show loading indicator
            const addBtn = document.querySelector('button[onclick*="add-part-file"]');
            const originalText = addBtn ? addBtn.textContent : '';
            if (addBtn) {
                addBtn.textContent = 'Uploading...';
                addBtn.disabled = true;
            }

            for (const file of files) {
                const formData = new FormData();
                formData.append('model_id', modelId);
                formData.append('part_file', file);

                try {
                    const response = await fetch('/actions/add-part', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const result = await response.json();

                    if (result.success) {
                        successCount++;
                    } else {
                        errorCount++;
                        console.error('Upload failed for', file.name, ':', result.error);
                    }
                } catch (err) {
                    errorCount++;
                    console.error('Upload error for', file.name, ':', err);
                }
            }

            // Reset file input
            document.getElementById('add-part-file').value = '';

            // Restore button
            if (addBtn) {
                addBtn.textContent = originalText;
                addBtn.disabled = false;
            }

            // Show result and reload
            if (successCount > 0) {
                if (errorCount > 0) {
                    showToast(`Uploaded ${successCount} files. ${errorCount} files failed.`, 'error');
                }
                location.reload();
            } else if (errorCount > 0) {
                showToast(`Upload failed. ${errorCount} files could not be uploaded.`, 'error');
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


