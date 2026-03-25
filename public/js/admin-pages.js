/**
 * Admin Pages JavaScript
 * Handles inline handlers for admin/models.php and admin/audit-log.php.
 * PHP data is passed via window.AuditLogConfig (set in audit-log.php).
 */

// ========================
// Admin Models Page
// ========================

function toggleSelectAll(checkbox) {
    document.querySelectorAll('.model-checkbox').forEach(cb => {
        cb.checked = checkbox.checked;
    });
    const headerCb = document.getElementById('header-select-all');
    const selectAllCb = document.getElementById('select-all');
    if (headerCb) headerCb.checked = checkbox.checked;
    if (selectAllCb) selectAllCb.checked = checkbox.checked;
    updateSelection();
}

function updateSelection() {
    const checked = document.querySelectorAll('.model-checkbox:checked');
    const countEl = document.getElementById('selected-count');
    const bar = document.getElementById('bulk-actions-bar');

    if (countEl) countEl.textContent = checked.length;
    if (bar) bar.style.display = checked.length > 0 ? 'flex' : 'none';

    const allCheckboxes = document.querySelectorAll('.model-checkbox');
    const headerCheckbox = document.getElementById('header-select-all');
    const selectAllCheckbox = document.getElementById('select-all');

    if (checked.length === allCheckboxes.length && allCheckboxes.length > 0) {
        if (headerCheckbox) { headerCheckbox.checked = true; headerCheckbox.indeterminate = false; }
        if (selectAllCheckbox) { selectAllCheckbox.checked = true; selectAllCheckbox.indeterminate = false; }
    } else if (checked.length > 0) {
        if (headerCheckbox) headerCheckbox.indeterminate = true;
        if (selectAllCheckbox) selectAllCheckbox.indeterminate = true;
    } else {
        if (headerCheckbox) { headerCheckbox.checked = false; headerCheckbox.indeterminate = false; }
        if (selectAllCheckbox) { selectAllCheckbox.checked = false; selectAllCheckbox.indeterminate = false; }
    }
}

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.model-checkbox:checked')).map(cb => cb.value);
}

async function bulkAddTag(tagId) {
    const select = document.getElementById('bulk-tag');
    if (!tagId) return;

    const ids = getSelectedIds();
    if (ids.length === 0) {
        showToast('Select models first', 'error');
        select.value = '';
        return;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'add_tag');
        formData.append('tag_id', tagId);
        ids.forEach(id => formData.append('model_ids[]', id));

        const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            showToast(`Tagged ${result.updated} model(s)`, 'success');
        } else {
            showToast(result.error || 'Unknown error', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('Failed', 'error');
    }
    select.value = '';
}

async function bulkAddCategory(categoryId) {
    const select = document.getElementById('bulk-category');
    if (!categoryId) return;

    const ids = getSelectedIds();
    if (ids.length === 0) {
        showToast('Select models first', 'error');
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
        console.error(err);
        showToast('Failed', 'error');
    }
    select.value = '';
}

async function bulkSetLicense(license) {
    const select = document.getElementById('bulk-license');
    if (license === '') return;

    const ids = getSelectedIds();
    if (ids.length === 0) {
        showToast('Select models first', 'error');
        select.value = '';
        return;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'set_license');
        formData.append('license', license);
        ids.forEach(id => formData.append('model_ids[]', id));

        const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            showToast(`Set license on ${result.updated} model(s)`, 'success');
        } else {
            showToast(result.error || 'Unknown error', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('Failed', 'error');
    }
    select.value = '';
}

async function bulkArchive(archive) {
    const ids = getSelectedIds();
    if (ids.length === 0) {
        showToast('Select models first', 'error');
        return;
    }

    if (!await showConfirm(`${archive ? 'Archive' : 'Unarchive'} ${ids.length} model(s)?`)) return;

    try {
        const formData = new FormData();
        formData.append('action', 'archive');
        formData.append('archive', archive ? '1' : '0');
        ids.forEach(id => formData.append('model_ids[]', id));

        const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            showToast(`${archive ? 'Archived' : 'Unarchived'} ${result.updated} model(s)`, 'success');
            location.reload();
        } else {
            showToast(result.error || 'Unknown error', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('Failed', 'error');
    }
}

async function bulkDelete() {
    const ids = getSelectedIds();
    if (ids.length === 0) {
        showToast('Select models first', 'error');
        return;
    }

    if (!await showConfirm(`DELETE ${ids.length} model(s)? This cannot be undone!`)) return;
    if (!await showConfirm(`Are you sure? All files and data will be permanently deleted.`)) return;

    try {
        const formData = new FormData();
        formData.append('action', 'delete');
        ids.forEach(id => formData.append('model_ids[]', id));

        const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            showToast(`Deleted ${result.deleted} model(s)`, 'success');
            location.reload();
        } else {
            showToast(result.error || 'Unknown error', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('Failed', 'error');
    }
}

async function deleteModel(id, name) {
    if (!await showConfirm(`Delete "${name}"? This cannot be undone!`)) return;

    try {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('model_ids[]', id);

        const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            location.reload();
        } else {
            showToast(result.error || 'Unknown error', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('Failed', 'error');
    }
}

// ========================
// Admin Audit Log Page
// ========================

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function showDetails(id) {
    const logsData = window.AuditLogConfig?.logsData || {};
    const log = logsData[id];
    if (!log) return;

    let html = '<div class="detail-grid">';
    html += `<div class="detail-item"><strong>Timestamp:</strong> ${escapeHtml(log.created_at)}</div>`;
    html += `<div class="detail-item"><strong>Event Type:</strong> ${escapeHtml(log.event_type)}</div>`;
    html += `<div class="detail-item"><strong>Event Name:</strong> ${escapeHtml(log.event_name)}</div>`;
    html += `<div class="detail-item"><strong>Severity:</strong> ${escapeHtml(log.severity)}</div>`;
    html += `<div class="detail-item"><strong>User:</strong> ${escapeHtml(log.username) || 'System'} (ID: ${escapeHtml(String(log.user_id || '')) || 'N/A'})</div>`;
    html += `<div class="detail-item"><strong>IP Address:</strong> ${escapeHtml(log.ip_address) || 'N/A'}</div>`;
    html += `<div class="detail-item"><strong>User Agent:</strong> ${escapeHtml(log.user_agent) || 'N/A'}</div>`;
    html += `<div class="detail-item"><strong>Resource:</strong> ${escapeHtml(log.resource_type) || 'N/A'} #${escapeHtml(String(log.resource_id || '')) || 'N/A'}</div>`;
    html += `<div class="detail-item"><strong>Resource Name:</strong> ${escapeHtml(log.resource_name) || 'N/A'}</div>`;
    html += `<div class="detail-item"><strong>Session ID:</strong> ${escapeHtml(log.session_id) || 'N/A'}</div>`;
    html += `<div class="detail-item"><strong>Request ID:</strong> ${escapeHtml(log.request_id) || 'N/A'}</div>`;
    html += '</div>';

    if (log.old_value) {
        html += '<h4>Old Value</h4><pre class="json-display">' + escapeHtml(JSON.stringify(log.old_value, null, 2)) + '</pre>';
    }

    if (log.new_value) {
        html += '<h4>New Value</h4><pre class="json-display">' + escapeHtml(JSON.stringify(log.new_value, null, 2)) + '</pre>';
    }

    if (log.metadata) {
        html += '<h4>Metadata</h4><pre class="json-display">' + escapeHtml(JSON.stringify(log.metadata, null, 2)) + '</pre>';
    }

    document.getElementById('details-content').innerHTML = html;
    document.getElementById('details-modal').style.display = 'flex';
}

function closeDetailsModal() {
    const modal = document.getElementById('details-modal');
    if (modal) modal.style.display = 'none';
}

function showComplianceModal() {
    const modal = document.getElementById('compliance-modal');
    if (modal) modal.style.display = 'flex';
}

function closeComplianceModal() {
    const modal = document.getElementById('compliance-modal');
    if (modal) modal.style.display = 'none';
}

// ========================
// Initialize on DOMContentLoaded
// ========================

document.addEventListener('DOMContentLoaded', function() {
    // --- Admin Models page ---

    const selectAllCb = document.getElementById('select-all');
    if (selectAllCb) {
        selectAllCb.addEventListener('change', function() { toggleSelectAll(this); });
    }

    const headerSelectAllCb = document.getElementById('header-select-all');
    if (headerSelectAllCb) {
        headerSelectAllCb.addEventListener('change', function() { toggleSelectAll(this); });
    }

    const bulkTagSelect = document.getElementById('bulk-tag');
    if (bulkTagSelect) {
        bulkTagSelect.addEventListener('change', function() { bulkAddTag(this.value); });
    }

    const bulkCategorySelect = document.getElementById('bulk-category');
    if (bulkCategorySelect) {
        bulkCategorySelect.addEventListener('change', function() { bulkAddCategory(this.value); });
    }

    const bulkLicenseSelect = document.getElementById('bulk-license');
    if (bulkLicenseSelect) {
        bulkLicenseSelect.addEventListener('change', function() { bulkSetLicense(this.value); });
    }

    // Bulk action buttons use data-action attribute
    document.querySelectorAll('[data-action="bulk-archive"]').forEach(btn => {
        btn.addEventListener('click', function() {
            bulkArchive(this.dataset.archive === '1');
        });
    });

    const bulkDeleteBtn = document.querySelector('[data-action="bulk-delete"]');
    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', bulkDelete);
    }

    // Per-row delete buttons use data-action="delete-model"
    const modelsTable = document.querySelector('.admin-table');
    if (modelsTable) {
        modelsTable.addEventListener('click', function(event) {
            const btn = event.target.closest('[data-action="delete-model"]');
            if (!btn) return;
            deleteModel(btn.dataset.modelId, btn.dataset.modelName);
        });

        // Model checkbox changes
        modelsTable.addEventListener('change', function(event) {
            if (event.target.matches('.model-checkbox')) {
                updateSelection();
            }
        });
    }

    // --- Admin Audit Log page ---

    const complianceBtn = document.querySelector('[data-action="show-compliance-modal"]');
    if (complianceBtn) {
        complianceBtn.addEventListener('click', showComplianceModal);
    }

    document.querySelectorAll('[data-action="close-compliance-modal"]').forEach(btn => {
        btn.addEventListener('click', closeComplianceModal);
    });

    const closeDetailsBtn = document.querySelector('[data-action="close-details-modal"]');
    if (closeDetailsBtn) {
        closeDetailsBtn.addEventListener('click', closeDetailsModal);
    }

    // Details buttons use data-action="show-details" with data-log-id
    const auditTable = document.querySelector('.audit-log-table');
    if (auditTable) {
        auditTable.addEventListener('click', function(event) {
            const btn = event.target.closest('[data-action="show-details"]');
            if (!btn) return;
            showDetails(btn.dataset.logId);
        });
    }

    // Escape key closes modals (audit log page)
    if (document.getElementById('details-modal') || document.getElementById('compliance-modal')) {
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDetailsModal();
                closeComplianceModal();
            }
        });
    }

    // --- Admin Activity page ---
    // Auto-submit filter form on select change
    var activityFilterForm = document.querySelector('.browse-filters form[role="search"]');
    if (activityFilterForm) {
        activityFilterForm.addEventListener('change', function(e) {
            if (e.target.matches('select')) {
                this.submit();
            }
        });
    }

    // --- Admin API Keys page ---
    var newKeyInput = document.getElementById('newKeyValue');
    if (newKeyInput) {
        newKeyInput.addEventListener('click', function() { this.select(); });
    }
    document.querySelectorAll('[data-action="copy-full-key"]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (typeof copyFullKey === 'function') copyFullKey(this);
        });
    });
    document.querySelectorAll('.api-key-prefix[data-copy-text]').forEach(function(el) {
        el.addEventListener('click', function() {
            if (typeof copyKey === 'function') copyKey(this.dataset.copyText, this);
        });
        el.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.click();
            }
        });
    });

    // --- Admin Security Headers page ---
    document.querySelectorAll('[data-action="copy-config"]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (typeof copyToClipboard === 'function') copyToClipboard(this, this.dataset.tabId);
        });
    });

    // --- Admin Storage page ---
    var storageTypeSelect = document.getElementById('storage_type');
    if (storageTypeSelect) {
        storageTypeSelect.addEventListener('change', function() {
            if (typeof toggleS3Settings === 'function') toggleS3Settings();
        });
    }

    // --- Admin Health page ---
    var refreshBtn = document.querySelector('[data-action="refresh-metrics"]');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function() {
            if (typeof refreshMetrics === 'function') refreshMetrics();
        });
    }

    // --- Admin Features page ---
    var resetDefaultsBtn = document.querySelector('[data-action="reset-defaults"]');
    if (resetDefaultsBtn) {
        resetDefaultsBtn.addEventListener('click', function() {
            if (typeof resetToDefaults === 'function') resetToDefaults();
        });
    }

    // --- Admin CLI Tools page ---
    document.querySelectorAll('[data-action="toggle-tool"]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (typeof toggleTool === 'function') toggleTool(this.dataset.toolKey);
        });
    });
});
