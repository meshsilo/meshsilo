/**
 * Browse page JavaScript
 * Handles batch operations, filter selects, model card navigation, and saved searches.
 * PHP data is passed via window.BrowsePageConfig (set in browse.php).
 */

let batchModeEnabled = false;
let lastClickedIndex = -1;

function handleModelCardClick(event, modelId) {
    if (batchModeEnabled) {
        // In batch mode, toggle the checkbox
        const checkbox = event.currentTarget.querySelector('.model-checkbox');
        if (checkbox && event.target !== checkbox) {
            const allCards = Array.from(document.querySelectorAll('[data-model-id]'));
            const currentIndex = allCards.findIndex(card => card.dataset.modelId === String(modelId));

            // Shift+click for range selection
            if (event.shiftKey && lastClickedIndex >= 0 && currentIndex >= 0) {
                const start = Math.min(lastClickedIndex, currentIndex);
                const end = Math.max(lastClickedIndex, currentIndex);
                const shouldCheck = !checkbox.checked;

                for (let i = start; i <= end; i++) {
                    const cb = allCards[i].querySelector('.model-checkbox');
                    if (cb) cb.checked = shouldCheck;
                }
            } else {
                checkbox.checked = !checkbox.checked;
            }

            lastClickedIndex = currentIndex;
            updateBatchSelection();
        }
    } else {
        // Normal mode, navigate to model
        window.location = '/model/' + modelId;
    }
}

function toggleBatchMode(enabled) {
    batchModeEnabled = enabled;
    document.body.classList.toggle('batch-mode', enabled);

    // Show floating bar only when items are selected
    if (!enabled) {
        document.getElementById('batch-actions-bar').style.display = 'none';
    }

    // Show/hide checkboxes
    document.querySelectorAll('.model-select-checkbox, .model-list-checkbox').forEach(el => {
        el.style.display = enabled ? 'block' : 'none';
    });

    if (!enabled) {
        // Uncheck all when disabling
        document.querySelectorAll('.model-checkbox').forEach(cb => cb.checked = false);
        updateBatchSelection();
        lastClickedIndex = -1;
    }
}

function toggleSelectAllModels(checkbox) {
    document.querySelectorAll('.model-checkbox').forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateBatchSelection();
}

function clearSelection() {
    document.querySelectorAll('.model-checkbox').forEach(cb => cb.checked = false);
    updateBatchSelection();
    lastClickedIndex = -1;
}

function updateBatchSelection() {
    const checked = document.querySelectorAll('.model-checkbox:checked');
    const countEl = document.getElementById('selected-count');
    const bar = document.getElementById('batch-actions-bar');

    if (countEl) countEl.textContent = checked.length;

    // Show/hide floating bar based on selection count
    if (bar && batchModeEnabled) {
        bar.style.display = checked.length > 0 ? 'flex' : 'none';
    }

    // Update select all checkbox state
    const allCheckboxes = document.querySelectorAll('.model-checkbox');
    const selectAllCheckbox = document.getElementById('select-all-models');
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = checked.length === allCheckboxes.length && allCheckboxes.length > 0;
        selectAllCheckbox.indeterminate = checked.length > 0 && checked.length < allCheckboxes.length;
    }
}

function getSelectedModelIds() {
    return Array.from(document.querySelectorAll('.model-checkbox:checked')).map(cb => cb.value);
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Only handle shortcuts when batch mode is enabled
    if (!batchModeEnabled) return;

    // Ctrl/Cmd + A: Select all
    if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
        e.preventDefault();
        document.querySelectorAll('.model-checkbox').forEach(cb => cb.checked = true);
        updateBatchSelection();
    }

    // Escape: Clear selection
    if (e.key === 'Escape') {
        e.preventDefault();
        clearSelection();
    }
});


function batchDownload() {
    const ids = getSelectedModelIds();
    if (ids.length === 0) {
        showToast('Please select models first', 'error');
        return;
    }
    window.location = '/actions/batch-download?ids=' + ids.join(',');
}

async function batchApplyTag(tagId) {
    const select = document.getElementById('batch-tag-select');
    if (!tagId) {
        select.value = '';
        return;
    }

    const ids = getSelectedModelIds();
    if (ids.length === 0) {
        showToast('Please select models first', 'error');
        select.value = '';
        return;
    }

    let tagName = '';
    if (tagId === '__new__') {
        tagName = await showPrompt('Enter new tag name:');
        if (!tagName) {
            select.value = '';
            return;
        }
        tagId = '';
    }

    try {
        const formData = new FormData();
        formData.append('action', 'add_tag');
        if (tagId) formData.append('tag_id', tagId);
        if (tagName) formData.append('tag_name', tagName);
        ids.forEach(id => formData.append('model_ids[]', id));

        const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            showToast(`Tagged ${result.updated} model(s)`, 'success');
            location.reload();
        } else {
            showToast(result.error || 'Unknown error', 'error');
        }
    } catch (err) {
        console.error('Batch tag error:', err);
        showToast('Failed to apply tag', 'error');
    }

    select.value = '';
}

async function batchApplyCategory(categoryId) {
    const select = document.getElementById('batch-category-select');
    if (!categoryId) {
        select.value = '';
        return;
    }

    const ids = getSelectedModelIds();
    if (ids.length === 0) {
        showToast('Please select models first', 'error');
        select.value = '';
        return;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'add_category');
        formData.append('category_id', categoryId);
        ids.forEach(id => formData.append('model_ids[]', id));

        const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            showToast(`Added category to ${result.updated} model(s)`, 'success');
        } else {
            showToast(result.error || 'Unknown error', 'error');
        }
    } catch (err) {
        console.error('Batch category error:', err);
        showToast('Failed to apply category', 'error');
    }

    select.value = '';
}

async function batchArchive() {
    const ids = getSelectedModelIds();
    if (ids.length === 0) {
        showToast('Please select models first', 'error');
        return;
    }

    if (!await showConfirm(`Archive ${ids.length} selected model(s)?`)) {
        return;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'archive');
        formData.append('archive', '1');
        ids.forEach(id => formData.append('model_ids[]', id));

        const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            showToast(`Archived ${result.updated} model(s)`, 'success');
            location.reload();
        } else {
            showToast(result.error || 'Unknown error', 'error');
        }
    } catch (err) {
        console.error('Batch archive error:', err);
        showToast('Failed to archive', 'error');
    }
}

async function batchSetCreator() {
    const ids = getSelectedModelIds();
    if (ids.length === 0) {
        showToast('Please select models first', 'error');
        return;
    }

    const creator = await showPrompt(`Set creator for ${ids.length} selected model(s) (leave empty to clear):`);
    if (creator === null) return; // Cancelled

    try {
        const formData = new FormData();
        formData.append('action', 'set_creator');
        formData.append('creator', creator);
        ids.forEach(id => formData.append('model_ids[]', id));

        const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            showToast(`Updated creator on ${result.updated} model(s)`, 'success');
            location.reload();
        } else {
            showToast(result.error || 'Unknown error', 'error');
        }
    } catch (err) {
        console.error('Batch set creator error:', err);
        showToast('Failed to set creator', 'error');
    }
}

async function batchSetCollection() {
    const ids = getSelectedModelIds();
    if (ids.length === 0) {
        showToast('Please select models first', 'error');
        return;
    }

    const collection = await showPrompt(`Set collection for ${ids.length} selected model(s) (leave empty to clear):`);
    if (collection === null) return; // Cancelled

    try {
        const formData = new FormData();
        formData.append('action', 'set_collection');
        formData.append('collection', collection);
        ids.forEach(id => formData.append('model_ids[]', id));

        const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            showToast(`Updated collection on ${result.updated} model(s)`, 'success');
            location.reload();
        } else {
            showToast(result.error || 'Unknown error', 'error');
        }
    } catch (err) {
        console.error('Batch set collection error:', err);
        showToast('Failed to set collection', 'error');
    }
}

async function saveCurrentSearch() {
    var name = await showPrompt('Name for this saved search:');
    if (!name || !name.trim()) return;

    var formData = new FormData();
    formData.append('action', 'save');
    formData.append('name', name.trim());
    formData.append('csrf_token', window.BrowsePageConfig.csrfToken);
    // Pass current filters
    var params = new URLSearchParams(window.location.search);
    params.forEach(function(value, key) {
        if (key !== 'page') {
            formData.append(key, value);
        }
    });

    try {
        var resp = await fetch('/saved-searches', { method: 'POST', body: formData });
        var data = await resp.json();
        if (data.success) {
            location.reload();
        } else {
            showToast(data.error || 'Failed to save search', 'error');
        }
    } catch (e) {
        showToast('Failed to save search', 'error');
    }
}

// Initialize on DOMContentLoaded
document.addEventListener('DOMContentLoaded', function() {
    // Hide checkboxes initially
    document.querySelectorAll('.model-select-checkbox, .model-list-checkbox').forEach(el => {
        el.style.display = 'none';
    });

    // Batch mode checkbox
    const batchModeCheckbox = document.getElementById('batch-mode-checkbox');
    if (batchModeCheckbox) {
        batchModeCheckbox.addEventListener('change', function() {
            toggleBatchMode(this.checked);
        });
    }

    // Select all models checkbox
    const selectAllCheckbox = document.getElementById('select-all-models');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            toggleSelectAllModels(this);
        });
    }

    // Clear selection button
    const clearBtn = document.querySelector('.btn[data-action="clear-selection"]');
    if (clearBtn) {
        clearBtn.addEventListener('click', clearSelection);
    }

    // Batch download button
    const batchDownloadBtn = document.querySelector('.btn[data-action="batch-download"]');
    if (batchDownloadBtn) {
        batchDownloadBtn.addEventListener('click', batchDownload);
    }

    // Batch tag select
    const batchTagSelect = document.getElementById('batch-tag-select');
    if (batchTagSelect) {
        batchTagSelect.addEventListener('change', function() {
            batchApplyTag(this.value);
        });
    }

    // Batch category select
    const batchCategorySelect = document.getElementById('batch-category-select');
    if (batchCategorySelect) {
        batchCategorySelect.addEventListener('change', function() {
            batchApplyCategory(this.value);
        });
    }

    // Batch set creator button
    const batchCreatorBtn = document.querySelector('.btn[data-action="batch-set-creator"]');
    if (batchCreatorBtn) {
        batchCreatorBtn.addEventListener('click', batchSetCreator);
    }

    // Batch set collection button
    const batchCollectionBtn = document.querySelector('.btn[data-action="batch-set-collection"]');
    if (batchCollectionBtn) {
        batchCollectionBtn.addEventListener('click', batchSetCollection);
    }

    // Batch archive button
    const batchArchiveBtn = document.querySelector('.btn[data-action="batch-archive"]');
    if (batchArchiveBtn) {
        batchArchiveBtn.addEventListener('click', batchArchive);
    }

    // Sort select — navigate on change
    const sortSelect = document.querySelector('.sort-select[data-navigate]');
    if (sortSelect) {
        sortSelect.addEventListener('change', function() {
            if (this.value) location.href = this.value;
        });
    }

    // Filter selects — navigate on change
    document.querySelectorAll('.filter-select[data-navigate]').forEach(function(select) {
        select.addEventListener('change', function() {
            if (this.value) location.href = this.value;
        });
    });

    // Save current search button
    const saveSearchBtn = document.querySelector('.btn[data-action="save-search"]');
    if (saveSearchBtn) {
        saveSearchBtn.addEventListener('click', saveCurrentSearch);
    }

    // Model card clicks — delegated on the model list container
    const modelContainer = document.querySelector('.models-list, .models-grid');
    if (modelContainer) {
        modelContainer.addEventListener('click', function(event) {
            const card = event.target.closest('[data-model-id]');
            if (!card) return;
            // Don't handle clicks on checkboxes, action buttons, or links
            if (event.target.closest('.model-list-actions') ||
                event.target.closest('.model-select-checkbox') ||
                event.target.closest('.model-list-checkbox') ||
                event.target.closest('a') ||
                event.target.closest('button:not([data-model-id])')) {
                return;
            }
            const modelId = card.dataset.modelId;
            handleModelCardClick(event, modelId);
        });

        modelContainer.addEventListener('keydown', function(event) {
            if (event.key !== 'Enter') return;
            const card = event.target.closest('[data-model-id]');
            if (!card) return;
            const modelId = card.dataset.modelId;
            handleModelCardClick(event, modelId);
        });

        // Model checkbox changes
        modelContainer.addEventListener('change', function(event) {
            if (event.target.matches('.model-checkbox')) {
                updateBatchSelection();
            }
        });
    }
});
