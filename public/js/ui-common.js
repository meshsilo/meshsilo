// UI common helpers for MeshSilo.
// Relocated verbatim from the inline <script> in includes/header.php and from
// the window.* globals previously defined in public/js/main.js. Loaded as a
// deferred, cacheable static file. All globals below preserve their prior names
// so existing callers (onclick handlers and other JS files) keep working.
//
// PHP-templated values are read from window.SiloConfig (set inline in header.php
// before this file loads).

// =====================
// Modal Focus Trap (canonical, was window.trapFocus / window.releaseFocus in main.js)
// =====================
window.trapFocus = function(modal) {
    const focusable = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    if (focusable.length === 0) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    first.focus();
    modal._focusTrapHandler = function(e) {
        if (e.key !== 'Tab') return;
        if (e.shiftKey) {
            if (document.activeElement === first) { e.preventDefault(); last.focus(); }
        } else {
            if (document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    };
    modal.addEventListener('keydown', modal._focusTrapHandler);
};

window.releaseFocus = function(modal) {
    if (modal._focusTrapHandler) {
        modal.removeEventListener('keydown', modal._focusTrapHandler);
        delete modal._focusTrapHandler;
    }
};

// =====================
// Toast notification (canonical, was window.showToast in main.js)
// =====================
window.showToast = (message, type, duration) => {
    if (window.siloUI?.toasts) {
        window.siloUI.toasts.show(message, type, duration);
    }
};

// =====================
// Global HTML escaping utility (canonical, was window.escapeHtml in main.js)
// Safe for both text and attribute context.
// =====================
window.escapeHtml = function(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
};

// =====================
// File size formatting (canonical). Replaces the per-file copies previously
// named formatBytes (model-page.js), formatFileSize (model-attachments.js) and
// formatSize (model-parts-upload.js).
// =====================
window.formatFileSize = function(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
};

// =====================
// Model detail URL for a model id. Reads window.SiloConfig.modelBase (set inline
// in header.php) and falls back to the historically hardcoded '/model/' prefix.
// =====================
window.modelUrl = function(modelId) {
    return ((window.SiloConfig && window.SiloConfig.modelBase) || '/model/') + modelId;
};

// =====================
// Sync a "select all" master checkbox against the group it controls: checked
// when every item is selected, indeterminate on a partial selection. Callers
// keep their own side effects (counts, action bars) around this call.
// =====================
window.syncSelectAllCheckbox = function(master, total, checkedCount) {
    if (!master) return;
    master.checked = total > 0 && checkedCount === total;
    master.indeterminate = checkedCount > 0 && checkedCount < total;
};

// =====================
// Escape-to-close for modals. Closes every currently visible modal matching
// `selector` by clicking its own .modal-close control, so whatever cleanup that
// control is wired to (viewer teardown, focus release, form reset) still runs -
// never a blind display:none.
//
// #confirm-modal / #prompt-modal are always skipped: showConfirm/showPrompt own
// their Escape handling so the awaited promise resolves (cancel) instead of the
// overlay being hidden with the promise left pending forever.
// =====================
window.wireEscapeToClose = function(selector) {
    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll(selector).forEach(function(modal) {
            if (modal.id === 'confirm-modal' || modal.id === 'prompt-modal') return;
            var display = modal.style.display;
            if (!display || display === 'none') return;
            var closeBtn = modal.querySelector('.modal-close');
            if (closeBtn) closeBtn.click();
        });
    });
};

// Confirm dialog replacement (returns Promise)
function showConfirm(message) {
    return new Promise(function(resolve) {
        var existing = document.getElementById('confirm-modal');
        if (existing) existing.remove();
        var overlay = document.createElement('div');
        overlay.id = 'confirm-modal';
        overlay.className = 'modal modal-overlay';
        overlay.style.display = 'flex';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Confirm action');
        overlay.innerHTML =
            '<div class="modal-content" style="max-width:400px">' +
                '<div class="modal-header"><h3>Confirm</h3></div>' +
                '<div class="modal-body" style="padding:1.5rem"><p></p></div>' +
                '<div class="modal-footer" style="display:flex;gap:0.5rem;justify-content:flex-end;padding:1rem 1.5rem">' +
                    '<button type="button" class="btn btn-secondary" id="confirm-cancel">Cancel</button>' +
                    '<button type="button" class="btn btn-danger" id="confirm-ok">Confirm</button>' +
                '</div>' +
            '</div>';
        overlay.querySelector('.modal-body p').textContent = message;
        document.body.appendChild(overlay);
        overlay.querySelector('#confirm-cancel').focus();
        trapFocus(overlay);
        function finish(result) {
            releaseFocus(overlay);
            overlay.remove();
            document.removeEventListener('keydown', onKey);
            resolve(result);
        }
        // Escape resolves the awaited promise (as cancel). The global Escape
        // handler intentionally skips confirm/prompt so it can never hide this
        // overlay while leaving the promise pending forever.
        function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); finish(false); } }
        document.addEventListener('keydown', onKey);
        overlay.querySelector('#confirm-ok').onclick = function() { finish(true); };
        overlay.querySelector('#confirm-cancel').onclick = function() { finish(false); };
        overlay.addEventListener('click', function(e) { if (e.target === overlay) finish(false); });
    });
}

// Prompt dialog replacement (returns Promise<string|null>)
function showPrompt(message, defaultValue) {
    defaultValue = defaultValue || '';
    return new Promise(function(resolve) {
        var existing = document.getElementById('prompt-modal');
        if (existing) existing.remove();
        var overlay = document.createElement('div');
        overlay.id = 'prompt-modal';
        overlay.className = 'modal modal-overlay';
        overlay.style.display = 'flex';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Input required');
        overlay.innerHTML =
            '<div class="modal-content" style="max-width:400px">' +
                '<div class="modal-header"><h3>Input</h3></div>' +
                '<div class="modal-body" style="padding:1.5rem"><p></p>' +
                    '<input type="text" id="prompt-input" class="form-control" style="width:100%;margin-top:0.75rem;padding:0.5rem;border:1px solid var(--color-border);border-radius:var(--radius);background:var(--color-surface);color:var(--color-text)">' +
                '</div>' +
                '<div class="modal-footer" style="display:flex;gap:0.5rem;justify-content:flex-end;padding:1rem 1.5rem">' +
                    '<button type="button" class="btn btn-secondary" id="prompt-cancel">Cancel</button>' +
                    '<button type="button" class="btn btn-primary" id="prompt-ok">OK</button>' +
                '</div>' +
            '</div>';
        overlay.querySelector('.modal-body p').textContent = message;
        var input = overlay.querySelector('#prompt-input');
        input.value = defaultValue;
        document.body.appendChild(overlay);
        trapFocus(overlay);
        input.focus();
        input.select();
        function finish(value) {
            releaseFocus(overlay);
            overlay.remove();
            document.removeEventListener('keydown', onKey);
            resolve(value);
        }
        // Escape resolves the awaited promise (as cancel/null) so a prompt flow
        // can never be left pending; the global Escape handler skips this modal.
        function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); finish(null); } }
        document.addEventListener('keydown', onKey);
        overlay.querySelector('#prompt-ok').onclick = function() { finish(input.value); };
        overlay.querySelector('#prompt-cancel').onclick = function() { finish(null); };
        input.addEventListener('keydown', function(e) { if (e.key === 'Enter') finish(input.value); });
        overlay.addEventListener('click', function(e) { if (e.target === overlay) finish(null); });
    });
}

// Global data-confirm handler for forms and buttons
document.addEventListener('submit', function(e) {
    var msg = e.target.getAttribute('data-confirm');
    if (!msg) return;
    e.preventDefault();
    showConfirm(msg).then(function(ok) {
        if (ok) {
            e.target.removeAttribute('data-confirm');
            e.target.submit();
        }
    });
});
document.addEventListener('click', function(e) {
    var btn = e.target.closest('button[data-confirm], a[data-confirm]');
    if (!btn) return;
    e.preventDefault();
    showConfirm(btn.getAttribute('data-confirm')).then(function(ok) {
        if (ok) {
            if (btn.tagName === 'A') {
                window.location.href = btn.href;
            } else if (btn.type === 'submit' && btn.form) {
                btn.removeAttribute('data-confirm');
                btn.click();
            }
        }
    });
});

// Relative time formatting
function formatRelativeTime(dateStr) {
    if (!dateStr) return '';
    var now = new Date();
    // Server timestamps are UTC - treat them as such (mirrors timeAgo, which
    // appends 'Z'). Without this, formatRelativeTime parsed them as local time.
    var then = new Date(dateStr.replace(' ', 'T') + 'Z');
    if (isNaN(then)) return dateStr;
    var diff = Math.floor((now - then) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
    if (diff < 2592000) return Math.floor(diff / 604800) + 'w ago';
    return '';
}

// Apply relative timestamps on load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-timestamp]').forEach(function(el) {
        var rel = formatRelativeTime(el.dataset.timestamp);
        if (rel) {
            el.title = el.textContent.trim();
            el.textContent = rel;
        }
    });
});

// Global Escape key: close any open modal overlay. Page-specific modals that are
// not .modal-overlay register their own scope via wireEscapeToClose().
wireEscapeToClose('.modal-overlay');

// Copy current page URL to clipboard
function copyPageUrl() {
    navigator.clipboard.writeText(window.location.href).then(function() {
        showToast('Link copied to clipboard', 'success');
    }).catch(function() {
        showToast('Failed to copy link', 'error');
    });
}

// Theme toggle functionality
function toggleTheme() {
    const html = document.documentElement;
    const currentTheme = html.getAttribute('data-theme');
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';
    html.setAttribute('data-theme', newTheme);
    document.cookie = 'meshsilo_theme=' + newTheme + ';path=/;max-age=31536000';
    updateThemeIcon(newTheme);
}
function updateThemeIcon(theme) {
    const icon = document.getElementById('theme-icon');
    if (icon) {
        icon.innerHTML = theme === 'light' ? '<i class="fa-solid fa-moon"></i>' : '<i class="fa-solid fa-sun"></i>';
    }
}

// Mobile menu toggle
function toggleMobileMenu() {
    const nav = document.querySelector('.main-nav');
    const toggle = document.querySelector('.mobile-menu-toggle');
    const isOpen = nav.classList.toggle('mobile-open');
    toggle.setAttribute('aria-expanded', isOpen);
    document.body.classList.toggle('mobile-menu-open', isOpen);
}

// Close mobile menu when clicking outside
document.addEventListener('click', function(e) {
    const nav = document.querySelector('.main-nav');
    const toggle = document.querySelector('.mobile-menu-toggle');
    if (nav && nav.classList.contains('mobile-open') &&
        !nav.contains(e.target) && !toggle.contains(e.target)) {
        nav.classList.remove('mobile-open');
        toggle.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('mobile-menu-open');
    }
});

// Queue indicator
function toggleQueueDropdown() {
    var dropdown = document.getElementById('queue-dropdown');
    var indicator = document.getElementById('queue-indicator');
    if (dropdown && indicator) {
        var isOpen = indicator.classList.toggle('open');
        var btn = indicator.querySelector('.queue-btn');
        if (btn) btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
}

// =====================
// Favorite toggle (canonical). Replaces the per-page copies previously defined
// in model-actions.js and inline in app/pages/favorites.php. Lives here because
// the delegated .model-card-favorite handler below needs it on any page that
// renders a favorite button, not only the model detail page.
//
// Updates the button in place, then fires a cancellable-free `favorite:toggled`
// event on document so a page can add its own reaction (the favorites list
// removes the card) without re-implementing the request.
// =====================
window.toggleFavorite = async function(modelId, btn) {
    try {
        const response = await fetch('/actions/favorite', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'model_id=' + modelId
        });
        const data = await response.json();
        if (data.success) {
            btn.classList.toggle('favorited', data.favorited);
            btn.innerHTML = data.favorited ? '<i class="fa-solid fa-heart" aria-hidden="true"></i>' : '<i class="fa-regular fa-heart" aria-hidden="true"></i>';
            var favLabel = data.favorited ? 'Remove from favorites' : 'Add to favorites';
            btn.title = favLabel;
            btn.setAttribute('aria-label', favLabel);
            btn.setAttribute('aria-pressed', data.favorited ? 'true' : 'false');
            document.dispatchEvent(new CustomEvent('favorite:toggled', {
                detail: { modelId: modelId, favorited: data.favorited, button: btn }
            }));
        }
    } catch (err) {
        console.error('Failed to toggle favorite:', err);
    }
};

// Model-card behaviour, delegated because CSP no longer permits the inline
// onclick/onchange/onkeydown attributes the card partial used to render.
// A card only navigates when it carries data-card-url; browse.php omits it and
// drives its own selection behaviour instead.
document.addEventListener('click', function (e) {
    var favBtn = e.target.closest('.model-card-favorite[data-favorite-id]');
    if (favBtn) {
        // Was: onclick="event.stopPropagation(); toggleFavorite(id, this)"
        e.preventDefault();
        e.stopPropagation();
        if (typeof toggleFavorite === 'function') {
            toggleFavorite(parseInt(favBtn.dataset.favoriteId, 10), favBtn);
        }
        return;
    }

    var card = e.target.closest('.model-card[data-card-url]');
    if (!card) return;

    // The checkbox label used to stopPropagation so selecting never navigated.
    if (e.target.closest('.model-select-checkbox, a, button, input, label')) return;

    window.location = card.dataset.cardUrl;
});

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    var card = e.target.closest('.model-card[data-card-url]');
    // Only the card itself is focusable; ignore Enter aimed at inner controls.
    if (!card || e.target !== card) return;
    window.location = card.dataset.cardUrl;
});

document.addEventListener('change', function (e) {
    if (!e.target.closest('.model-checkbox')) return;
    if (typeof updateBatchSelection === 'function') {
        updateBatchSelection();
    }
});

// Header control dispatch. These buttons used inline onclick="" attributes,
// which CSP blocks now that script-src is nonce-based (a nonce authorises a
// <script> element, never an event-handler attribute), so the header markup
// carries data-action and the mapping lives here.
document.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-action]');
    if (!trigger) return;

    var actions = {
        'toggle-theme': toggleTheme,
        'toggle-mobile-menu': toggleMobileMenu,
        'toggle-queue-dropdown': toggleQueueDropdown
    };

    var handler = actions[trigger.dataset.action];
    if (handler) {
        e.preventDefault();
        handler();
    }
});

var _lastConversionRemaining = -1;
var _lastQueueHtml = null;
var _anyIndicatorActive = false;

function refreshQueueStatus() {
    if (document.hidden) return;
    fetch('/actions/queue-status', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var badge = document.getElementById('queue-badge');
        var body = document.getElementById('queue-dropdown-body');
        if (!badge || !body) return;

        var convRemaining = data.conversions ? data.conversions.remaining : 0;
        var hasActivity = data.active > 0 || convRemaining > 0;

        var html;
        if (hasActivity) {
            badge.textContent = data.active;
            badge.style.display = '';
            html = '';

            // Show conversion progress if active
            if (data.conversions && convRemaining > 0) {
                var c = data.conversions;
                html += '<div class="queue-job queue-job-processing">' +
                    '<span class="queue-job-status"><i class="fa-solid fa-gear"></i></span>' +
                    '<span class="queue-job-name">Converting: ' + c.completed + '/' + c.total + ' completed</span>' +
                    (c.failed > 0 ? '<span class="queue-job-time" style="color:var(--color-danger)">' + c.failed + ' failed</span>' : '') +
                    '</div>';
            }

            // Show other jobs
            data.jobs.forEach(function(job) {
                if (job.name === 'Convert Stl To3mf') return;
                var statusClass = job.status === 'processing' ? 'queue-job-processing' : 'queue-job-pending';
                var statusIcon = job.status === 'processing' ? '<i class="fa-solid fa-play"></i>' : '<i class="fa-solid fa-circle"></i>';
                var ago = timeAgo(job.created_at);
                html += '<div class="queue-job ' + statusClass + '">' +
                    '<span class="queue-job-status">' + statusIcon + '</span>' +
                    '<span class="queue-job-name">' + escapeHtml(job.name) + '</span>' +
                    '<span class="queue-job-time">' + ago + '</span>' +
                    '</div>';
            });
        } else {
            badge.style.display = 'none';
            html = '<div class="queue-empty">No active tasks</div>';
        }

        // Only touch the DOM when the rendered markup actually changed (idle path is a no-op).
        if (html !== _lastQueueHtml) {
            body.innerHTML = html;
            _lastQueueHtml = html;
        }

        // Detect conversion completion: was converting, now done
        if (_lastConversionRemaining > 0 && convRemaining === 0) {
            showToast('All conversions completed', 'success', 3000);
            // Auto-reload model pages so file types update
            if (document.querySelector('.parts-section')) {
                setTimeout(function() { location.reload(); }, 1500);
            }
        }
        _lastConversionRemaining = convRemaining;

        // Apply/remove loading spinners on model cards and part items
        updateConvertingIndicators(data.converting_model_ids || [], data.converting_part_ids || []);
    })
    .catch(function() {});
}

function updateConvertingIndicators(modelIds, partIds) {
    // Common idle path: nothing converting now and nothing marked last tick -> no DOM query.
    if (modelIds.length === 0 && partIds.length === 0 && !_anyIndicatorActive) return;
    _anyIndicatorActive = modelIds.length > 0 || partIds.length > 0;

    // Model cards on browse/home/search/category/collection pages (grid and list views)
    document.querySelectorAll('.model-card[data-model-id], .model-list-item[data-model-id]').forEach(function(card) {
        var id = parseInt(card.dataset.modelId);
        card.classList.toggle('converting', modelIds.indexOf(id) !== -1);
    });

    // Part items on model page
    document.querySelectorAll('.part-item[data-part-id]').forEach(function(item) {
        var id = parseInt(item.dataset.partId);
        var nameEl = item.querySelector('.part-name');
        if (partIds.indexOf(id) !== -1) {
            if (nameEl) nameEl.classList.add('part-converting');
        } else {
            if (nameEl) nameEl.classList.remove('part-converting');
        }
    });
}

function timeAgo(dateStr) {
    var now = new Date();
    var then = new Date(dateStr.replace(' ', 'T') + 'Z');
    var diff = Math.floor((now - then) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
}

// Register Service Worker for PWA
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        var swVersion = (window.SiloConfig && window.SiloConfig.swVersion) || '0';
        navigator.serviceWorker.register('/sw.js?v=' + swVersion)
            .catch(function() {});
    });
}
