/**
 * Model Parts - Selection & Mass Actions
 * Split from model-parts.js (behaviour unchanged; code moved verbatim).
 * Handles part selection, select-all / folder checkboxes, mass delete / convert /
 * print-type, per-part print type, and batch rename of selected parts.
 *
 * Global functions here are invoked by delegated handlers in model-page.js.
 * Load order (set in includes/header.php): after window.ModelPageConfig and the
 * model-parts.js core, before model-page.js. Relies on global helpers
 * (showToast, showConfirm, trapFocus, releaseFocus, escapeHtml) from ui-common.js.
 */

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
            updateAllCheckboxStates(folder);
        }

        function updateAllCheckboxStates(scope) {
            const selectAllCheckbox = document.getElementById('select-all-parts');
            const allPartCheckboxes = document.querySelectorAll('.part-checkbox');
            const checkedCount = document.querySelectorAll('.part-checkbox:checked').length;

            if (selectAllCheckbox) {
                selectAllCheckbox.checked = checkedCount === allPartCheckboxes.length && allPartCheckboxes.length > 0;
                selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < allPartCheckboxes.length;
            }
            updateFolderCheckboxes(scope);
        }

        // Pass a single .parts-group as `scope` to recompute only that folder's checkbox
        // (toggling one part only changes its own folder); omit to recompute every folder.
        function updateFolderCheckboxes(scope) {
            const folders = scope ? [scope] : document.querySelectorAll('.parts-group');
            folders.forEach(folder => {
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
                updateAllCheckboxStates(this.closest('.parts-group'));
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
