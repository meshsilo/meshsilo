// model-page.js — extracted from model.php, loaded conditionally on model detail page
// PHP data is injected by model.php via window.ModelPageConfig = {...}

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

            // Create new viewer
            partPreviewViewer = new ModelViewer(container, {
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
                var modals = document.querySelectorAll('.modal-overlay');
                modals.forEach(function(modal) {
                    if (modal.style.display !== 'none' && modal.id !== 'confirm-modal' && modal.id !== 'prompt-modal') {
                        var closeBtn = modal.querySelector('.modal-close');
                        if (closeBtn) closeBtn.click();
                    }
                });
            }
        });

        // Add click handlers to part items
        document.querySelectorAll('.part-preview-trigger').forEach(trigger => {
            trigger.addEventListener('click', function() {
                const partItem = this.closest('.part-item');
                const path = partItem.dataset.partPath;
                const type = partItem.dataset.partType;
                const name = partItem.dataset.partName;

                if (path && type) {
                    openPartPreview(path, type, name);
                }
            });
        });

        // Handle conversion button clicks
        document.querySelectorAll('.convert-btn').forEach(btn => {
            btn.addEventListener('click', async function() {
                const partId = this.dataset.partId;
                const partItem = this.closest('.part-item');
                const originalText = this.textContent;

                // First, estimate the savings
                this.textContent = 'Checking...';
                this.disabled = true;

                try {
                    const estimateResponse = await fetch(`/actions/convert-part?action=estimate&part_id=${partId}`);
                    const estimate = await estimateResponse.json();

                    if (!estimate.success) {
                        showToast('Cannot estimate conversion: ' + (estimate.error || 'Unknown error'), 'error');
                        this.textContent = originalText;
                        this.disabled = false;
                        return;
                    }

                    if (!estimate.worth_converting) {
                        showToast('Converting this file would not save space. Keeping original STL.', 'info');
                        this.textContent = originalText;
                        this.disabled = false;
                        return;
                    }

                    // Format sizes for display
                    const formatBytes = (bytes) => {
                        if (bytes < 1024) return bytes + ' B';
                        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
                    };

                    const confirmed = await showConfirm(
                        'Convert to 3MF? Current size: ' + formatBytes(estimate.original_size) +
                        ', Estimated new size: ' + formatBytes(estimate.estimated_size) +
                        ', Estimated savings: ' + formatBytes(estimate.estimated_savings) + ' (' + estimate.estimated_savings_percent + '%). This will replace the STL file with a 3MF file.'
                    );

                    if (!confirmed) {
                        this.textContent = originalText;
                        this.disabled = false;
                        return;
                    }

                    // Queue the conversion as a background job
                    this.textContent = 'Queuing...';

                    const formData = new FormData();
                    formData.append('action', 'convert');
                    formData.append('part_id', partId);

                    const convertResponse = await fetch('/actions/convert-part', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await convertResponse.json();

                    if (result.success) {
                        this.textContent = 'Queued';
                        if (typeof refreshQueueStatus === 'function') refreshQueueStatus();
                    } else {
                        showToast('Failed to queue conversion: ' + (result.error || 'Unknown error'), 'error');
                        this.textContent = originalText;
                        this.disabled = false;
                    }
                } catch (err) {
                    console.error('Conversion error:', err);
                    showToast('Failed to queue conversion', 'error');
                    this.textContent = originalText;
                    this.disabled = false;
                }
            });
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
                } catch (e) {}
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
            var list = btn.closest('.parts-list');
            list.querySelectorAll('.part-hidden').forEach(function(el) {
                el.classList.remove('part-hidden');
            });
            btn.remove();
        }

        function toggleFolder(groupEl) {
            groupEl.classList.toggle('collapsed');
            var header = groupEl.querySelector('.parts-group-header');
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
                var header = group.querySelector('.parts-group-header');
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
                var header = group.querySelector('.parts-group-header');
                if (header) header.setAttribute('aria-expanded', 'false');
            }
        });
        updateCollapseAllToggle();

        function showCreateFolderModal() {
            var modal = document.getElementById('create-folder-modal');
            modal.style.display = 'flex';
            trapFocus(modal);
        }

        function closeCreateFolderModal() {
            var modal = document.getElementById('create-folder-modal');
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
            var modal = document.getElementById('move-folder-modal');
            modal.style.display = 'flex';
            trapFocus(modal);
            // Uncheck all radios
            document.querySelectorAll('#move-folder-list input[type="radio"]').forEach(r => r.checked = false);
        }

        function closeMoveFolderModal() {
            var modal = document.getElementById('move-folder-modal');
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

        // Version upload management
        function showUploadVersionModal() {
            var modal = document.getElementById('upload-version-modal');
            modal.style.display = 'flex';
            trapFocus(modal);
        }

        function closeUploadVersionModal() {
            var modal = document.getElementById('upload-version-modal');
            releaseFocus(modal);
            modal.style.display = 'none';
            document.getElementById('upload-version-form').reset();
        }

        document.getElementById('upload-version-modal')?.addEventListener('click', function(e) {
            if (e.target === this) closeUploadVersionModal();
        });

        async function submitUploadVersion(e) {
            e.preventDefault();
            const fileInput = document.getElementById('version-file');
            const changelog = document.getElementById('version-changelog').value.trim();
            const submitBtn = document.getElementById('version-submit-btn');

            if (!fileInput.files.length) {
                showToast('Please select a file', 'error');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Uploading...';

            const formData = new FormData();
            formData.append('model_id', ModelPageConfig.modelId);
            formData.append('version_file', fileInput.files[0]);
            formData.append('changelog', changelog);

            try {
                const response = await fetch('/actions/upload-version', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    closeUploadVersionModal();
                    location.reload();
                } else {
                    showToast('Failed: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Upload version error:', err);
                showToast('Failed to upload version', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Upload Version';
            }
        }

        // Position fixed dropdown menu relative to toggle button
        function positionDropdownMenu(dropdown) {
            const menu = dropdown.querySelector('.dropdown-menu');
            const btn = dropdown.querySelector('.dropdown-toggle');
            if (!menu || !btn) return;

            const btnRect = btn.getBoundingClientRect();

            // Check if dropdown is inside part-actions (needs fixed positioning)
            const isPartDropdown = dropdown.classList.contains('part-actions-dropdown');

            if (isPartDropdown) {
                // Position below the button, aligned to the right
                const right = window.innerWidth - btnRect.right;
                menu.style.top = (btnRect.bottom + 4) + 'px';
                menu.style.right = right + 'px';
                menu.style.left = 'auto';
            }
        }

        // Dropdown toggle handling
        document.querySelectorAll('.dropdown-toggle').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const dropdown = this.closest('.dropdown');
                const wasOpen = dropdown.classList.contains('open');

                // Close all other dropdowns
                document.querySelectorAll('.dropdown.open').forEach(d => {
                    d.classList.remove('open');
                    var t = d.querySelector('.dropdown-toggle');
                    if (t) t.setAttribute('aria-expanded', 'false');
                });

                // Toggle this dropdown
                if (!wasOpen) {
                    dropdown.classList.add('open');
                    this.setAttribute('aria-expanded', 'true');
                    positionDropdownMenu(dropdown);

                    // Restore calculated data for part dropdowns
                    if (dropdown.classList.contains('part-actions-dropdown')) {
                        const partItem = dropdown.closest('.part-item');
                        if (partItem) {
                            const partId = partItem.querySelector('.part-checkbox')?.value ||
                                          partItem.dataset.partId;
                            if (partId) {
                                restorePartCalculatedData(partId, dropdown);
                            }
                        }
                    }
                }
            });
        });

        // Close dropdowns when clicking outside (but not when interacting with inline controls)
        function closeAllDropdowns() {
            document.querySelectorAll('.dropdown.open').forEach(d => {
                d.classList.remove('open');
                var t = d.querySelector('.dropdown-toggle');
                if (t) t.setAttribute('aria-expanded', 'false');
            });
        }
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.dropdown')) {
                closeAllDropdowns();
            } else if (e.target.closest('.dropdown-item') && !e.target.closest('.dropdown-item-inline') && !e.target.closest('select')) {
                closeAllDropdowns();
            }
        });

        // Close fixed-position dropdowns on scroll (since they won't move with the page)
        window.addEventListener('scroll', function() {
            closeAllDropdowns();
        }, { passive: true });

        // Favorite toggle
        async function toggleFavorite(modelId, btn) {
            try {
                const response = await fetch('/actions/favorite', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'model_id=' + modelId
                });
                const data = await response.json();
                if (data.success) {
                    btn.classList.toggle('favorited', data.favorited);
                    btn.innerHTML = data.favorited ? '&#9829;' : '&#9825;';
                    btn.title = data.favorited ? 'Remove from favorites' : 'Add to favorites';
                }
            } catch (err) {
                console.error('Failed to toggle favorite:', err);
            }
        }

        // Storage for calculated part data (persists during page session)
        const partCalculatedData = {};

        // Per-part actions
        async function calculatePartDimensions(partId, linkEl) {
            const originalText = 'Calculate Dimensions';
            linkEl.textContent = 'Calculating...';
            try {
                const formData = new FormData();
                formData.append('model_id', partId);
                const response = await fetch('/actions/calculate-dimensions', { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success && data.formatted) {
                    // Store the calculated value
                    if (!partCalculatedData[partId]) partCalculatedData[partId] = {};
                    partCalculatedData[partId].dimensions = data.formatted;
                    linkEl.textContent = 'Dimensions: ' + data.formatted;
                } else {
                    showToast('Failed: ' + (data.error || 'Unknown error'), 'error');
                    linkEl.textContent = originalText;
                }
            } catch (err) {
                console.error('Part dimensions error:', err);
                showToast('Failed to calculate dimensions', 'error');
                linkEl.textContent = originalText;
            }
        }

        async function calculatePartVolume(partId, linkEl) {
            const originalText = 'Calculate Volume';
            linkEl.textContent = 'Calculating...';
            try {
                const formData = new FormData();
                formData.append('model_id', partId);
                const response = await fetch('/actions/calculate-volume', { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success && data.volume_cm3) {
                    // Store the calculated value
                    if (!partCalculatedData[partId]) partCalculatedData[partId] = {};
                    partCalculatedData[partId].volume = data.volume_cm3;
                    partCalculatedData[partId].costEstimate = data.cost_estimate;
                    let volumeText = 'Volume: ' + data.volume_cm3.toFixed(1) + ' cm\u00B3';
                    if (data.cost_estimate) {
                        volumeText += ' (~$' + data.cost_estimate.estimated_cost.toFixed(2) + ')';
                    }
                    linkEl.textContent = volumeText;
                } else {
                    showToast('Failed: ' + (data.error || 'Unknown error'), 'error');
                    linkEl.textContent = originalText;
                }
            } catch (err) {
                console.error('Part volume error:', err);
                showToast('Failed to calculate volume', 'error');
                linkEl.textContent = originalText;
            }
        }

        // Restore calculated data when dropdown opens
        function restorePartCalculatedData(partId, dropdown) {
            const data = partCalculatedData[partId];
            if (!data) return;

            const dimsLink = dropdown.querySelector('[onclick*="calculatePartDimensions"]');
            const volLink = dropdown.querySelector('[onclick*="calculatePartVolume"]');

            if (dimsLink && data.dimensions) {
                dimsLink.textContent = 'Dimensions: ' + data.dimensions;
            }
            if (volLink && data.volume) {
                let volumeText = 'Volume: ' + data.volume.toFixed(1) + ' cm\u00B3';
                if (data.costEstimate) {
                    volumeText += ' (~$' + data.costEstimate.estimated_cost.toFixed(2) + ')';
                }
                volLink.textContent = volumeText;
            }
        }

        async function analyzePartMesh(partId, linkEl) {
            const originalText = linkEl.textContent;
            linkEl.textContent = 'Analyzing...';
            try {
                const formData = new FormData();
                formData.append('action', 'analyze');
                formData.append('model_id', partId);
                const response = await fetch('/actions/mesh-repair', { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success) {
                    if (data.analysis && data.analysis.is_manifold) {
                        linkEl.textContent = 'Mesh OK';
                        linkEl.style.color = 'var(--color-success, #10b981)';
                    } else if (data.analysis) {
                        const issues = data.analysis.issues ? data.analysis.issues.length : 0;
                        linkEl.textContent = issues + ' issue(s)';
                        linkEl.style.color = 'var(--color-warning, #f59e0b)';
                    } else {
                        linkEl.textContent = 'Analyzed';
                    }
                } else {
                    showToast('Failed: ' + (data.error || 'Unknown error'), 'error');
                    linkEl.textContent = originalText;
                }
            } catch (err) {
                console.error('Part mesh analysis error:', err);
                showToast('Failed to analyze mesh', 'error');
                linkEl.textContent = originalText;
            }
        }

        // Archive toggle
        async function toggleArchive(modelId, archive) {
            try {
                const response = await fetch('/actions/update-model', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'model_id=' + modelId + '&is_archived=' + (archive ? '1' : '0')
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    showToast('Failed to update: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Failed to toggle archive:', err);
                showToast('Failed to update model', 'error');
            }
        }

        // Tag management
        const allTags = ModelPageConfig.allTags;
        const tagInput = document.getElementById('tag-input');
        const tagSuggestions = document.getElementById('tag-suggestions');

        if (tagInput) {
            tagInput.addEventListener('input', function() {
                const value = this.value.toLowerCase().trim();
                if (value.length < 1) {
                    tagSuggestions.style.display = 'none';
                    return;
                }

                const matching = allTags.filter(t => t.name.toLowerCase().includes(value));
                if (matching.length === 0 && value.length > 0) {
                    // Show option to create new tag
                    tagSuggestions.innerHTML = `
                        <button type="button" class="tag-suggestion" onclick="addTag('${value.replace(/'/g, "\\'")}')">
                            <span class="tag-color-dot" style="background-color: var(--color-primary);"></span>
                            <span>Create "${value}"</span>
                        </button>
                    `;
                } else {
                    tagSuggestions.innerHTML = matching.map(t => `
                        <button type="button" class="tag-suggestion" onclick="addTagById(${t.id}, '${t.name.replace(/'/g, "\\'")}')">
                            <span class="tag-color-dot" style="background-color: ${t.color};"></span>
                            <span>${t.name}</span>
                        </button>
                    `).join('');
                }
                tagSuggestions.style.display = matching.length > 0 || value.length > 0 ? 'block' : 'none';
            });

            tagInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const value = this.value.trim();
                    if (value) {
                        addTag(value);
                    }
                }
            });

            tagInput.addEventListener('blur', function() {
                setTimeout(() => {
                    tagSuggestions.style.display = 'none';
                }, 200);
            });
        }

        async function addTag(tagName) {
            try {
                const response = await fetch('/actions/tag', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=add&model_id=${ModelPageConfig.modelId}&tag_name=` + encodeURIComponent(tagName)
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    showToast('Failed to add tag: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Failed to add tag:', err);
            }
        }

        async function addTagById(_tagId, tagName) {
            await addTag(tagName);
        }

        async function removeTag(modelId, tagId, element) {
            try {
                const response = await fetch('/actions/tag', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=remove&model_id=' + modelId + '&tag_id=' + tagId
                });
                const data = await response.json();
                if (data.success) {
                    element.remove();
                } else {
                    showToast('Failed to remove tag: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Failed to remove tag:', err);
            }
        }

        // Share Modal Functions
        function openShareModal() {
            var modal = document.getElementById('share-modal');
            modal.style.display = 'flex';
            trapFocus(modal);
            loadShareLinks();
        }

        function closeShareModal() {
            var modal = document.getElementById('share-modal');
            releaseFocus(modal);
            modal.style.display = 'none';
        }

        document.getElementById('share-modal')?.addEventListener('click', function(e) {
            if (e.target === this) closeShareModal();
        });

        document.getElementById('share-link-form')?.addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('model_id', ModelPageConfig.modelId);
            formData.append('expires_in', document.getElementById('share-expires').value);
            formData.append('max_downloads', document.getElementById('share-max-downloads').value);
            formData.append('password', document.getElementById('share-password').value);

            try {
                const response = await fetch('/actions/share-link', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    // Clear form
                    this.reset();
                    // Reload links
                    loadShareLinks();
                } else {
                    showToast('Failed to create share link: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Error creating share link:', err);
                showToast('Failed to create share link', 'error');
            }
        });

        async function loadShareLinks() {
            const container = document.getElementById('share-links-list');

            try {
                const response = await fetch(`/actions/share-link?action=list&model_id=${ModelPageConfig.modelId}`);
                const result = await response.json();

                if (!result.success) {
                    container.innerHTML = '<p class="text-muted">Failed to load share links</p>';
                    return;
                }

                if (result.links.length === 0) {
                    container.innerHTML = '<p class="text-muted">No active share links</p>';
                    return;
                }

                container.innerHTML = result.links.map(link => `
                    <div class="share-link-item ${link.is_expired ? 'expired' : ''}">
                        <div class="share-link-info">
                            <div class="share-link-url">
                                <input type="text" readonly value="${link.share_url}" class="share-url-input" onclick="this.select()">
                            </div>
                            <div class="share-link-meta">
                                ${link.has_password ? '<span class="share-badge">Password</span>' : ''}
                                ${link.expires_at ? `<span class="share-meta-item">${link.is_expired ? 'Expired' : 'Expires: ' + new Date(link.expires_at).toLocaleDateString()}</span>` : '<span class="share-meta-item">Never expires</span>'}
                                ${link.max_downloads ? `<span class="share-meta-item">Downloads: ${link.download_count}/${link.max_downloads}</span>` : `<span class="share-meta-item">${link.download_count} downloads</span>`}
                            </div>
                        </div>
                        <button type="button" class="btn btn-danger btn-small" onclick="deleteShareLink(${link.id})">Delete</button>
                    </div>
                `).join('');
            } catch (err) {
                console.error('Error loading share links:', err);
                container.innerHTML = '<p class="text-muted">Failed to load share links</p>';
            }
        }

        async function deleteShareLink(linkId) {
            if (!await showConfirm('Delete this share link? Anyone with this link will no longer be able to access the model.')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('link_id', linkId);

            try {
                const response = await fetch('/actions/share-link', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    loadShareLinks();
                } else {
                    showToast('Failed to delete share link: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Error deleting share link:', err);
                showToast('Failed to delete share link', 'error');
            }
        }

        // External Links
        function toggleAddLinkForm() {
            const form = document.getElementById('add-link-form');
            const btn = document.getElementById('add-link-toggle');
            if (form.style.display === 'none') {
                form.style.display = 'block';
                btn.style.display = 'none';
                document.getElementById('link-title').focus();
            } else {
                form.style.display = 'none';
                btn.style.display = '';
                document.getElementById('link-title').value = '';
                document.getElementById('link-url').value = '';
                document.getElementById('link-type').value = 'other';
            }
        }

        async function addModelLink() {
            const title = document.getElementById('link-title').value.trim();
            const url = document.getElementById('link-url').value.trim();
            const linkType = document.getElementById('link-type').value;

            if (!title || !url) {
                showToast('Title and URL are required', 'error');
                return;
            }

            try {
                const response = await fetch('/actions/model-links', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'add',
                        model_id: ModelPageConfig.modelId,
                        title: title,
                        url: url,
                        link_type: linkType
                    })
                });
                const result = await response.json();

                if (result.success) {
                    const list = document.getElementById('model-links-list');
                    const empty = document.getElementById('model-links-empty');
                    if (empty) empty.remove();

                    const link = result.link;
                    const item = document.createElement('div');
                    item.className = 'model-link-item';
                    item.dataset.linkId = link.id;
                    item.innerHTML =
                        '<span class="model-link-type type-' + escapeHtml(link.link_type) + '">' + escapeHtml(link.link_type) + '</span>' +
                        '<a href="' + escapeHtml(link.url) + '" target="_blank" rel="noopener noreferrer" class="model-link-title">' + escapeHtml(link.title) + '</a>' +
                        '<button type="button" class="model-link-delete" aria-label="Remove link" onclick="deleteModelLink(' + link.id + ')" title="Remove link">&times;</button>';
                    list.appendChild(item);

                    toggleAddLinkForm();
                } else {
                    showToast(result.error, 'error');
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function deleteModelLink(linkId) {
            if (!await showConfirm('Remove this link?')) return;

            try {
                const response = await fetch('/actions/model-links', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', link_id: linkId })
                });
                const result = await response.json();

                if (result.success) {
                    const item = document.querySelector('[data-link-id="' + linkId + '"]');
                    if (item) item.remove();

                    // Show empty state if no links remain
                    const list = document.getElementById('model-links-list');
                    if (!list.querySelector('.model-link-item')) {
                        const p = document.createElement('p');
                        p.className = 'model-links-empty';
                        p.id = 'model-links-empty';
                        p.textContent = 'No external links yet.';
                        list.appendChild(p);
                    }
                } else {
                    showToast(result.error, 'error');
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        // Attachments - Lightbox
        var lightboxImages = [];
        var lightboxIndex = 0;
        var lbZoom = 1, lbPanX = 0, lbPanY = 0, lbDragging = false, lbDragStart = {};

        function collectLightboxImages() {
            lightboxImages = [];
            var grid = document.getElementById('attachment-images');
            if (!grid) return;
            var imgs = grid.querySelectorAll('.attachment-image img');
            imgs.forEach(function(img) {
                lightboxImages.push({ src: img.src, caption: img.alt });
            });
        }

        function lightboxResetZoom() {
            lbZoom = 1; lbPanX = 0; lbPanY = 0;
            var lightbox = document.getElementById('image-lightbox');
            if (lightbox) {
                var img = lightbox.querySelector('.lightbox-content img');
                if (img) { img.style.transform = ''; img.style.cursor = ''; }
            }
        }

        function lightboxApplyTransform() {
            var lightbox = document.getElementById('image-lightbox');
            if (!lightbox) return;
            var img = lightbox.querySelector('.lightbox-content img');
            if (!img) return;
            if (lbZoom <= 1) {
                img.style.transform = '';
                img.style.cursor = '';
            } else {
                img.style.transform = 'scale(' + lbZoom + ') translate(' + (lbPanX / lbZoom) + 'px, ' + (lbPanY / lbZoom) + 'px)';
                img.style.cursor = 'grab';
            }
        }

        function openImageLightbox(src, caption) {
            collectLightboxImages();
            lightboxIndex = lightboxImages.findIndex(function(img) { return img.src === src || img.src.endsWith(src); });
            if (lightboxIndex < 0) lightboxIndex = 0;
            lbZoom = 1; lbPanX = 0; lbPanY = 0;

            var existing = document.getElementById('image-lightbox');
            if (existing) existing.remove();

            var hasMultiple = lightboxImages.length > 1;
            var lightbox = document.createElement('div');
            lightbox.id = 'image-lightbox';
            lightbox.className = 'lightbox-overlay';
            lightbox.setAttribute('role', 'dialog');
            lightbox.setAttribute('aria-modal', 'true');
            lightbox.innerHTML =
                '<div class="lightbox-content">' +
                    '<button type="button" class="lightbox-close" aria-label="Close" onclick="closeLightbox()">&times;</button>' +
                    (hasMultiple ? '<button type="button" class="lightbox-nav lightbox-prev" aria-label="Previous image" onclick="lightboxNav(-1)">&#8249;</button>' : '') +
                    '<img src="' + escapeHtml(src) + '" alt="' + escapeHtml(caption) + '" draggable="false">' +
                    (hasMultiple ? '<button type="button" class="lightbox-nav lightbox-next" aria-label="Next image" onclick="lightboxNav(1)">&#8250;</button>' : '') +
                    '<div class="lightbox-caption">' + escapeHtml(caption) +
                    (hasMultiple ? ' <span class="lightbox-counter">' + (lightboxIndex + 1) + ' / ' + lightboxImages.length + '</span>' : '') +
                    '</div>' +
                '</div>';
            document.body.appendChild(lightbox);
            lightbox.style.display = 'flex';

            var lbImg = lightbox.querySelector('.lightbox-content img');

            // Scroll to zoom
            lightbox.addEventListener('wheel', function(e) {
                e.preventDefault();
                var delta = e.deltaY > 0 ? -0.2 : 0.2;
                lbZoom = Math.min(5, Math.max(1, lbZoom + delta));
                if (lbZoom <= 1) { lbPanX = 0; lbPanY = 0; }
                lightboxApplyTransform();
            }, { passive: false });

            // Double-click to toggle zoom
            lbImg.addEventListener('dblclick', function(e) {
                e.stopPropagation();
                if (lbZoom > 1) { lightboxResetZoom(); } else { lbZoom = 2.5; lightboxApplyTransform(); }
            });

            // Drag to pan
            lbImg.addEventListener('mousedown', function(e) {
                if (lbZoom <= 1) return;
                e.preventDefault();
                lbDragging = true;
                lbDragStart = { x: e.clientX - lbPanX, y: e.clientY - lbPanY };
                lbImg.style.cursor = 'grabbing';
            });
            document.addEventListener('mousemove', lightboxMouseMove);
            document.addEventListener('mouseup', lightboxMouseUp);

            // Touch zoom/pan
            var lastTouchDist = 0;
            lightbox.addEventListener('touchstart', function(e) {
                if (e.touches.length === 2) {
                    lastTouchDist = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
                } else if (e.touches.length === 1 && lbZoom > 1) {
                    lbDragging = true;
                    lbDragStart = { x: e.touches[0].clientX - lbPanX, y: e.touches[0].clientY - lbPanY };
                }
            }, { passive: true });
            lightbox.addEventListener('touchmove', function(e) {
                if (e.touches.length === 2) {
                    e.preventDefault();
                    var dist = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
                    if (lastTouchDist > 0) {
                        lbZoom = Math.min(5, Math.max(1, lbZoom * (dist / lastTouchDist)));
                        if (lbZoom <= 1) { lbPanX = 0; lbPanY = 0; }
                        lightboxApplyTransform();
                    }
                    lastTouchDist = dist;
                } else if (e.touches.length === 1 && lbDragging) {
                    e.preventDefault();
                    lbPanX = e.touches[0].clientX - lbDragStart.x;
                    lbPanY = e.touches[0].clientY - lbDragStart.y;
                    lightboxApplyTransform();
                }
            }, { passive: false });
            lightbox.addEventListener('touchend', function() { lbDragging = false; lastTouchDist = 0; });

            // Close on background click (only if not zoomed)
            lightbox.addEventListener('click', function(e) {
                if (e.target === this && lbZoom <= 1) closeLightbox();
            });

            document.addEventListener('keydown', lightboxKeyHandler);
        }

        function lightboxMouseMove(e) {
            if (!lbDragging) return;
            lbPanX = e.clientX - lbDragStart.x;
            lbPanY = e.clientY - lbDragStart.y;
            lightboxApplyTransform();
        }

        function lightboxMouseUp() {
            if (lbDragging) {
                lbDragging = false;
                var lightbox = document.getElementById('image-lightbox');
                if (lightbox) {
                    var img = lightbox.querySelector('.lightbox-content img');
                    if (img && lbZoom > 1) img.style.cursor = 'grab';
                }
            }
        }

        function lightboxNav(dir) {
            if (lightboxImages.length < 2) return;
            lightboxResetZoom();
            lightboxIndex = (lightboxIndex + dir + lightboxImages.length) % lightboxImages.length;
            var img = lightboxImages[lightboxIndex];
            var lightbox = document.getElementById('image-lightbox');
            if (!lightbox) return;
            lightbox.querySelector('.lightbox-content img').src = img.src;
            lightbox.querySelector('.lightbox-content img').alt = img.caption;
            var caption = lightbox.querySelector('.lightbox-caption');
            caption.innerHTML = escapeHtml(img.caption) + ' <span class="lightbox-counter">' + (lightboxIndex + 1) + ' / ' + lightboxImages.length + '</span>';
        }

        function closeLightbox() {
            var lightbox = document.getElementById('image-lightbox');
            if (lightbox) lightbox.remove();
            document.removeEventListener('keydown', lightboxKeyHandler);
            document.removeEventListener('mousemove', lightboxMouseMove);
            document.removeEventListener('mouseup', lightboxMouseUp);
            lbZoom = 1; lbPanX = 0; lbPanY = 0;
        }

        function lightboxKeyHandler(e) {
            if (e.key === 'Escape') closeLightbox();
            if (e.key === 'ArrowLeft') lightboxNav(-1);
            if (e.key === 'ArrowRight') lightboxNav(1);
        }

        // Attachments - Upload
        async function uploadAttachments(files) {
            if (!files.length) return;

            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/markdown'];
            const allowedExts = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.pdf', '.txt', '.md'];

            for (const file of files) {
                const ext = '.' + file.name.split('.').pop().toLowerCase();
                if (!allowedTypes.includes(file.type) && !allowedExts.includes(ext)) {
                    showToast(`Invalid file type: ${file.name}. Allowed: Images, PDFs, TXT, MD`, 'error');
                    continue;
                }

                const formData = new FormData();
                formData.append('action', 'upload');
                formData.append('model_id', ModelPageConfig.modelId);
                formData.append('attachment', file);

                try {
                    const response = await fetch('/actions/attachments', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();

                    if (result.success) {
                        // Remove empty state if present
                        const empty = document.getElementById('attachments-empty');
                        if (empty) empty.remove();

                        // Add new attachment to appropriate section
                        if (result.file_type === 'image') {
                            addImageAttachment(result);
                        } else {
                            addDocumentAttachment(result);
                        }
                    } else {
                        showToast(`Error uploading ${file.name}: ${result.error}`, 'error');
                    }
                } catch (err) {
                    showToast(`Error uploading ${file.name}: ${err.message}`, 'error');
                }
            }

            // Clear the file input
            document.getElementById('attachment-file-input').value = '';
        }

        function addImageAttachment(att) {
            let grid = document.getElementById('attachment-images');

            // Create images section if it doesn't exist
            if (!grid) {
                const section = document.createElement('div');
                section.className = 'attachment-section';
                section.innerHTML = '<h4>Images</h4><div class="attachment-grid" id="attachment-images"></div>';

                const attachmentsDiv = document.querySelector('.model-attachments');
                const uploadDiv = attachmentsDiv.querySelector('.attachment-upload');
                attachmentsDiv.insertBefore(section, uploadDiv);
                grid = document.getElementById('attachment-images');
            }

            const item = document.createElement('div');
            item.className = 'attachment-image';
            item.dataset.attachmentId = att.attachment_id;

            const img = document.createElement('img');
            img.src = '/assets/' + att.file_path;
            img.alt = att.original_filename;
            img.loading = 'lazy';
            img.onclick = function() {
                openImageLightbox('/assets/' + att.file_path, att.original_filename);
            };

            const thumbBtn = document.createElement('button');
            thumbBtn.type = 'button';
            thumbBtn.className = 'attachment-set-thumb';
            thumbBtn.title = 'Set as model thumbnail';
            thumbBtn.innerHTML = '&#128247;';
            thumbBtn.onclick = function() { setAttachmentAsThumbnail(att.attachment_id); };

            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'attachment-delete';
            deleteBtn.title = 'Delete';
            deleteBtn.textContent = '×';
            deleteBtn.onclick = function() { deleteAttachment(att.attachment_id); };

            item.appendChild(img);
            item.appendChild(thumbBtn);
            item.appendChild(deleteBtn);
            grid.appendChild(item);
        }

        function addDocumentAttachment(att) {
            let list = document.getElementById('attachment-documents');

            // Create documents section if it doesn't exist
            if (!list) {
                const section = document.createElement('div');
                section.className = 'attachment-section';
                section.innerHTML = '<h4>Documents</h4><div class="attachment-documents" id="attachment-documents"></div>';

                const attachmentsDiv = document.querySelector('.model-attachments');
                const uploadDiv = attachmentsDiv.querySelector('.attachment-upload');
                attachmentsDiv.insertBefore(section, uploadDiv);
                list = document.getElementById('attachment-documents');
            }

            const item = document.createElement('div');
            item.className = 'attachment-document';
            item.dataset.attachmentId = att.attachment_id;

            const badge = document.createElement('span');
            badge.className = 'file-type-badge';
            const docExt = att.original_filename.split('.').pop().toLowerCase();
            badge.textContent = '.' + docExt;

            const link = document.createElement('a');
            link.href = '/assets/' + att.file_path;
            link.target = '_blank';
            link.rel = 'noopener';
            link.className = 'attachment-doc-name';
            link.textContent = att.original_filename;

            const sizeSpan = document.createElement('span');
            sizeSpan.className = 'attachment-doc-size';
            sizeSpan.textContent = formatFileSize(att.file_size);

            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'attachment-delete';
            deleteBtn.title = 'Delete';
            deleteBtn.textContent = '×';
            deleteBtn.onclick = function() { deleteAttachment(att.attachment_id); };

            item.appendChild(badge);
            item.appendChild(link);
            item.appendChild(sizeSpan);
            item.appendChild(deleteBtn);
            list.appendChild(item);
        }

        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        }

        async function setAttachmentAsThumbnail(attachmentId) {
            const formData = new FormData();
            formData.append('action', 'set_from_attachment');
            formData.append('model_id', ModelPageConfig.modelId);
            formData.append('attachment_id', attachmentId);
            formData.append('csrf_token', ModelPageConfig.csrfToken);

            try {
                const response = await fetch('/actions/thumbnail', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    // Update the thumbnail display
                    const thumbContainer = document.querySelector('.model-detail-thumbnail');
                    if (thumbContainer) {
                        let img = thumbContainer.querySelector('.model-thumbnail-image');
                        if (!img) {
                            img = document.createElement('img');
                            img.className = 'model-thumbnail-image';
                            img.alt = ModelPageConfig.modelName;
                            thumbContainer.prepend(img);
                        }
                        img.src = '/assets/' + result.thumbnail_path + '?t=' + Date.now();
                    }
                } else {
                    showToast(result.error || 'Failed to set thumbnail', 'error');
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function deleteAttachment(attachmentId) {
            if (!await showConfirm('Delete this attachment?')) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('attachment_id', attachmentId);

            try {
                const response = await fetch('/actions/attachments', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    const item = document.querySelector('[data-attachment-id="' + attachmentId + '"]');
                    if (item) {
                        const section = item.closest('.attachment-section');
                        item.remove();

                        // Remove section if empty
                        const container = section ? section.querySelector('.attachment-grid, .attachment-documents') : null;
                        if (container && container.children.length === 0) {
                            section.remove();
                        }

                        // Show empty state if no attachments remain
                        const imagesGrid = document.getElementById('attachment-images');
                        const docsList = document.getElementById('attachment-documents');
                        const hasImages = imagesGrid && imagesGrid.children.length > 0;
                        const hasDocs = docsList && docsList.children.length > 0;

                        if (!hasImages && !hasDocs) {
                            const uploadDiv = document.querySelector('.attachment-upload');
                            if (uploadDiv && !document.getElementById('attachments-empty')) {
                                const empty = document.createElement('p');
                                empty.className = 'attachments-empty';
                                empty.id = 'attachments-empty';
                                empty.textContent = 'No attachments yet.';
                                uploadDiv.parentNode.insertBefore(empty, uploadDiv);
                            }
                        }
                    }
                } else {
                    showToast(result.error, 'error');
                }
            } catch (err) {
                showToast(err.message, 'error');
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
            var modal = document.getElementById('batch-rename-modal');
            modal.style.display = 'flex';
            trapFocus(modal);
            updateRenamePreview();
        }

        function closeBatchRenameModal() {
            var modal = document.getElementById('batch-rename-modal');
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


document.addEventListener('DOMContentLoaded', function() {
    // ── Favorite button ──────────────────────────────────────────────
    document.querySelector('.favorite-btn')?.addEventListener('click', function() {
        toggleFavorite(ModelPageConfig.modelId, this);
    });

    // ── Copy link button ─────────────────────────────────────────────
    document.querySelector('.copy-link-btn')?.addEventListener('click', copyPageUrl);

    // ── Share modal open ─────────────────────────────────────────────
    document.querySelector('.open-share-modal')?.addEventListener('click', openShareModal);

    // ── Share modal close (backdrop click handled in openShareModal listener) ─
    document.getElementById('share-modal')?.addEventListener('click', function(e) {
        if (e.target === this) closeShareModal();
    });

    // ── Password visibility toggle ───────────────────────────────────
    document.querySelector('.password-toggle')?.addEventListener('click', function() {
        togglePasswordVisibility(this);
    });

    // ── Add link form toggle ─────────────────────────────────────────
    document.getElementById('add-link-toggle')?.addEventListener('click', toggleAddLinkForm);
    document.querySelector('.cancel-link-form')?.addEventListener('click', toggleAddLinkForm);
    document.querySelector('.submit-link-form')?.addEventListener('click', addModelLink);

    // ── Attachment upload ─────────────────────────────────────────────
    document.getElementById('attachment-file-input')?.addEventListener('change', function() {
        uploadAttachments(this.files);
    });
    document.querySelector('.trigger-attachment-upload')?.addEventListener('click', function() {
        document.getElementById('attachment-file-input').click();
    });

    // ── Add parts upload ─────────────────────────────────────────────
    document.getElementById('add-part-file')?.addEventListener('change', function() {
        uploadParts(this.files);
    });
    document.querySelectorAll('.trigger-add-parts').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('add-part-file')?.click();
        });
    });

    // ── Upload version modal ─────────────────────────────────────────
    document.querySelector('.show-upload-version')?.addEventListener('click', showUploadVersionModal);
    document.getElementById('upload-version-form')?.addEventListener('submit', submitUploadVersion);

    // ── Create folder modal ──────────────────────────────────────────
    document.querySelector('.show-create-folder')?.addEventListener('click', showCreateFolderModal);
    document.getElementById('create-folder-form')?.addEventListener('submit', submitCreateFolder);

    // ── Move folder modal ─────────────────────────────────────────────
    document.querySelector('.submit-move-folder')?.addEventListener('click', submitMoveToFolder);

    // ── Modal close buttons — map parent modal ID to close function ───
    const modalClosers = {
        'part-preview-modal': closePartPreview,
        'share-modal': closeShareModal,
        'create-folder-modal': closeCreateFolderModal,
        'move-folder-modal': closeMoveFolderModal,
        'upload-version-modal': closeUploadVersionModal,
        'batch-rename-modal': closeBatchRenameModal,
    };
    document.querySelectorAll('.modal-close').forEach(btn => {
        btn.addEventListener('click', function() {
            const modal = this.closest('[id]');
            const fn = modal && modalClosers[modal.id];
            if (fn) fn();
        });
    });

    // ── Batch rename modal actions ────────────────────────────────────
    document.querySelector('.cancel-batch-rename')?.addEventListener('click', closeBatchRenameModal);
    document.querySelector('.apply-batch-rename')?.addEventListener('click', applyBatchRename);
    document.getElementById('rename-pattern')?.addEventListener('input', updateRenamePreview);
    document.getElementById('rename-prefix')?.addEventListener('input', updateRenamePreview);
    document.getElementById('rename-suffix')?.addEventListener('input', updateRenamePreview);

    // ── Select all parts ─────────────────────────────────────────────
    document.getElementById('select-all-parts')?.addEventListener('click', function() {
        toggleSelectAllParts(this);
    });

    // ── Collapse all groups ───────────────────────────────────────────
    document.querySelector('.collapse-all-toggle')?.addEventListener('click', function() {
        toggleCollapseAllGroups(this);
    });

    // ── Mass print type select ───────────────────────────────────────
    document.getElementById('mass-print-type')?.addEventListener('change', function() {
        massUpdatePrintType(this);
    });

    // ── Edit model (navigate to edit page) ───────────────────────────
    document.querySelector('.navigate-edit-model')?.addEventListener('click', function() {
        window.location.href = this.dataset.href;
    });

    // ── Archive / unarchive ───────────────────────────────────────────
    document.querySelectorAll('.toggle-archive').forEach(btn => {
        btn.addEventListener('click', function() {
            const archive = this.dataset.archiveValue === 'true';
            toggleArchive(ModelPageConfig.modelId, archive);
        });
    });

    // ── Event delegation for dynamically-generated and loop-rendered elements ──

    document.addEventListener('click', function(e) {
        // Tag remove button
        if (e.target.matches('.model-tag-remove')) {
            e.preventDefault();
            e.stopPropagation();
            const tagId = parseInt(e.target.dataset.tagId);
            removeTag(ModelPageConfig.modelId, tagId, e.target.parentElement);
            return;
        }

        // Model link delete button
        if (e.target.matches('.model-link-delete')) {
            const linkId = parseInt(e.target.closest('.model-link-item')?.dataset.linkId);
            if (linkId) deleteModelLink(linkId);
            return;
        }

        // Attachment set as thumbnail
        if (e.target.matches('.attachment-set-thumb')) {
            const attachmentId = parseInt(e.target.closest('[data-attachment-id]')?.dataset.attachmentId);
            if (attachmentId) setAttachmentAsThumbnail(attachmentId);
            return;
        }

        // Attachment delete
        if (e.target.matches('.attachment-delete')) {
            const attachmentId = parseInt(e.target.closest('[data-attachment-id]')?.dataset.attachmentId);
            if (attachmentId) deleteAttachment(attachmentId);
            return;
        }

        // Attachment image lightbox
        if (e.target.matches('.attachment-image-trigger')) {
            openImageLightbox(e.target.dataset.lightboxSrc, e.target.dataset.lightboxAlt);
            return;
        }

        // Show more parts
        if (e.target.matches('.show-more-parts')) {
            showMoreParts(e.target);
            return;
        }

        // Folder actions — stop propagation
        if (e.target.closest('.folder-actions')) {
            e.stopPropagation();
        }

        // Folder checkbox — stop propagation + select parts
        if (e.target.matches('.folder-checkbox')) {
            e.stopPropagation();
            selectFolderParts(e.target);
            return;
        }

        // Folder header collapse toggle
        if (e.target.matches('.parts-group-header') || e.target.closest('.parts-group-header')) {
            const header = e.target.matches('.parts-group-header') ? e.target : e.target.closest('.parts-group-header');
            if (!e.target.matches('.folder-checkbox') && !e.target.closest('.folder-actions')) {
                toggleFolder(header.parentElement);
            }
            return;
        }

        // Rename folder button
        if (e.target.matches('.rename-folder-btn')) {
            renameFolder(e.target.dataset.folder);
            return;
        }

        // Delete folder button
        if (e.target.matches('.delete-folder-btn')) {
            deleteFolder(e.target.dataset.folder);
            return;
        }

        // Calculate dimensions
        if (e.target.matches('.calc-dimensions-btn')) {
            calculatePartDimensions(parseInt(e.target.dataset.partId), e.target);
            return;
        }

        // Calculate volume
        if (e.target.matches('.calc-volume-btn')) {
            calculatePartVolume(parseInt(e.target.dataset.partId), e.target);
            return;
        }

        // Analyze mesh
        if (e.target.matches('.analyze-mesh-btn')) {
            analyzePartMesh(parseInt(e.target.dataset.partId), e.target);
            return;
        }

        // Move single part to folder
        if (e.target.matches('.show-move-folder-single')) {
            showMoveFolderModal([parseInt(e.target.dataset.partId)]);
            return;
        }

        // Move selected parts to folder
        if (e.target.matches('.show-move-folder-modal')) {
            showMoveFolderModal(getSelectedPartIds());
            return;
        }

        // Show batch rename modal
        if (e.target.matches('.show-batch-rename-modal')) {
            showBatchRenameModal(getSelectedPartIds());
            return;
        }
    });

    // ── Keyboard delegation for focusable elements with role=button ──
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            // Attachment image keyboard activation
            if (e.target.matches('.attachment-image-trigger')) {
                e.preventDefault();
                openImageLightbox(e.target.dataset.lightboxSrc, e.target.dataset.lightboxAlt);
                return;
            }
            // Folder header keyboard activation
            if (e.target.matches('.parts-group-header')) {
                e.preventDefault();
                toggleFolder(e.target.parentElement);
                return;
            }
        }
    });

    // ── Change delegation ─────────────────────────────────────────────
    document.addEventListener('change', function(e) {
        if (e.target.matches('.print-type-select.folder-print-type')) {
            updateFolderPrintType(e.target, e.target.dataset.folder);
            return;
        }
        if (e.target.matches('.print-type-select:not(.folder-print-type):not(#mass-print-type)')) {
            updatePrintType(e.target);
            return;
        }
    });
});
