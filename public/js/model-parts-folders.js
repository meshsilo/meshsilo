/**
 * Model Parts - Folders (CRUD & View)
 * Split from model-parts.js (behaviour unchanged; code moved verbatim).
 * Handles show-more parts, folder collapse/expand + collapse-all, nested-folder
 * layout, folder create/rename/delete, move-to-folder, and folder print type.
 *
 * Global functions here are invoked by delegated handlers in model-page.js.
 * Load order (set in includes/header.php): after window.ModelPageConfig and the
 * model-parts.js core, before model-page.js. Relies on global helpers
 * (showToast, showConfirm, showPrompt, trapFocus, releaseFocus, escapeHtml).
 */

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

        // Nest folders - moves subfolder groups inside parent folder groups when nest_folders is saved on the model
        (function() {
            var modelParts = document.querySelector('.model-parts');
            if (!modelParts) return;

            function getTopLevelGroups() {
                return Array.from(modelParts.querySelectorAll(':scope > .parts-group[data-folder]'));
            }

            function hasNestableRelationships() {
                var groups = getTopLevelGroups();
                for (var i = 0; i < groups.length; i++) {
                    var folder = groups[i].dataset.folder;
                    if (folder !== 'Root' && folder.indexOf('/') !== -1) return true;
                }
                return false;
            }

            function nestFolders() {
                var groups = getTopLevelGroups();

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
                var insertBefore = modelParts.querySelector('.parts-actions') ||
                                   modelParts.querySelector('.model-download') ||
                                   null;

                parentPaths.forEach(function(path) {
                    var name = neededParents[path];
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
                            '<span class="folder-toggle" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span> ' +
                            escapeHtml(name) +
                            (childCount ? ' <span class="folder-part-count">(' + childCount + ')</span>' : '') +
                        '</h3>' +
                        '<div class="parts-list"></div>';
                    // Click handling is done by the delegated handler in model-page.js
                    folderMap[path] = el;
                    if (insertBefore) {
                        modelParts.insertBefore(el, insertBefore);
                    } else {
                        modelParts.appendChild(el);
                    }
                });

                // Shorten nested folder names: "Aarakocra/Aarakocra OLD" -> "Aarakocra OLD"
                groups.forEach(function(group) {
                    var folder = group.dataset.folder;
                    if (folder === 'Root' || folder.indexOf('/') === -1) return;
                    var headerEl = group.querySelector(':scope > .parts-group-header');
                    if (!headerEl) return;
                    var leafName = folder.split('/').pop();
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
                            // Collapse nested subfolders by default; session storage '0' means user explicitly opened it
                            var ssKey = 'model_' + ModelPageConfig.modelId + '_folder_' + folder;
                            if (sessionStorage.getItem(ssKey) !== '0') {
                                group.classList.add('collapsed');
                                var groupHeader = group.querySelector(':scope > .parts-group-header');
                                if (groupHeader) groupHeader.setAttribute('aria-expanded', 'false');
                            }
                            parentList.appendChild(group);
                        }
                    }
                });

                // Sort remaining top-level groups alphabetically
                var topGroups = Array.from(modelParts.querySelectorAll(':scope > .parts-group[data-folder]'));
                var anchor = modelParts.querySelector('.parts-actions') ||
                             modelParts.querySelector('.model-download') || null;
                topGroups.sort(function(a, b) {
                    var fa = a.dataset.folder.toLowerCase();
                    var fb = b.dataset.folder.toLowerCase();
                    if (fa === 'root') return -1;
                    if (fb === 'root') return 1;
                    return fa.localeCompare(fb);
                });
                topGroups.forEach(function(g) {
                    if (anchor) {
                        modelParts.insertBefore(g, anchor);
                    } else {
                        modelParts.appendChild(g);
                    }
                });
            }

            if (ModelPageConfig.nestFolders && hasNestableRelationships()) {
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
