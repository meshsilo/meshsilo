<?php
// Include features helper if not already loaded
if (!function_exists('isFeatureEnabled')) {
    require_once __DIR__ . '/features.php';
}

// Determine current theme
$defaultTheme = getSetting('default_theme', 'dark');
$allowUserTheme = getSetting('allow_user_theme', '1') === '1';
$currentTheme = $defaultTheme;
if ($allowUserTheme && isset($_COOKIE['meshsilo_theme'])) {
    $currentTheme = $_COOKIE['meshsilo_theme'] === 'light' ? 'light' : 'dark';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($currentTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?= $currentTheme === 'dark' ? '#0f172a' : '#3b82f6' ?>">
    <meta name="color-scheme" content="light dark">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="description" content="<?= htmlspecialchars($metaDescription ?? getSetting('site_description', 'Digital Asset Manager for 3D print files')) ?>">
    <title><?= htmlspecialchars($pageTitle ?? SITE_NAME) ?> - <?= htmlspecialchars(SITE_NAME) ?></title>
<?php
// Build absolute base URL for OG tags
$_ogScheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$_ogBase = $_ogScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$_ogUrl = $_ogBase . ($_SERVER['REQUEST_URI'] ?? '/');
$_ogTitle = $pageTitle ?? SITE_NAME;
$_ogDescription = $metaDescription ?? getSetting('site_description', 'Digital Asset Manager for 3D print files');
$_ogType = $ogType ?? 'website';
$_ogImageAbsolute = isset($ogImage) ? $_ogBase . $ogImage : null;
?>
    <!-- Open Graph -->
    <meta property="og:site_name" content="<?= htmlspecialchars(SITE_NAME) ?>">
    <meta property="og:type" content="<?= htmlspecialchars($_ogType) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($_ogTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($_ogDescription) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($_ogUrl) ?>">
<?php if ($_ogImageAbsolute): ?>
    <meta property="og:image" content="<?= htmlspecialchars($_ogImageAbsolute) ?>">
<?php endif; ?>
    <!-- Twitter Card -->
    <meta name="twitter:card" content="<?= $_ogImageAbsolute ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= htmlspecialchars($_ogTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($_ogDescription) ?>">
<?php if ($_ogImageAbsolute): ?>
    <meta name="twitter:image" content="<?= htmlspecialchars($_ogImageAbsolute) ?>">
<?php endif; ?>
    <link rel="manifest" href="<?= basePath('manifest.json') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= basePath('images/icon.svg') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= basePath('images/favicon-32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= basePath('images/favicon-16.png') ?>">
    <link rel="apple-touch-icon" href="<?= basePath('images/icon-192.png') ?>">
    <link rel="stylesheet" href="<?= basePath('css/style.css') ?>?v=8">

    <!-- CDN preconnect hints (dns-prefetch as fallback for older browsers) -->
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">

    <?php if (!empty($needsViewer)): ?>
    <!-- Three.js for 3D model rendering (defer preserves execution order) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js" crossorigin="anonymous" integrity="sha384-CI3ELBVUz9XQO+97x6nwMDPosPR5XvsxW2ua7N1Xeygeh1IxtgqtCkGfQY9WWdHu" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/fflate@0.8.0/umd/index.js" crossorigin="anonymous" integrity="sha384-eIxjswljUW1AHMlmZkz6yMIzTVOAC/1WfeIlG5Vt70kjZqYo5deE+nMKU/r6GrZR" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/STLLoader.js" crossorigin="anonymous" integrity="sha384-QF8EmP6pyNE+i7WmcltzC4ddzFVKDxfn5WD5gXyKTSE4SCw0R25TI+q0LUlnf7tq" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/3MFLoader.js" crossorigin="anonymous" integrity="sha384-Qf3iW6qbvjv2SYq9fcW25m3HbcU4WOSbvQGkrr4V7LWFhtgBewCtl3w7IMdY8o6o" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/OBJLoader.js" crossorigin="anonymous" integrity="sha384-UWFC8mrevmKCZhKbJ/8/dqLrRAvHArRwJCKjwruJuXyhsebGMFsIK5zrn+R9r+fT" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/PLYLoader.js" crossorigin="anonymous" integrity="sha384-TRjDrMoP2Iw2zIithJ7Pm10f16V6yXxbUwTEYL5urkonr6Zr+xZ2WDOj2ONVpnSd" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/GLTFLoader.js" crossorigin="anonymous" integrity="sha384-fljlqkjWlmSFjkESkQvm77heIZpoWmXEOzlCA7kOpGUH+95Zk0yGfQieWM2q136E" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/ColladaLoader.js" crossorigin="anonymous" integrity="sha384-ElElVDG/OItkfG6FCh/mbHubpjXL/jWdxkSI0pYvE+aTwfV1Uw3Kq3gZf4qux00x" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/FBXLoader.js" crossorigin="anonymous" integrity="sha384-2p/UEtsvNhL+wOAYuEC0nPIxmadBIxZnUrgBcwTle8Ur/abmFqGeiNvkFDHGocOM" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/TDSLoader.js" crossorigin="anonymous" integrity="sha384-4wpQ8AgXEeR0Ac4yCctD9EllVESYdcfZeCJ1khJD54VCdMMklOF9kiIEh+kE8uKz" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/AMFLoader.js" crossorigin="anonymous" integrity="sha384-6h4mEeJoEKWavyB6eukhkTtWKr5TarGFVDfb4ZuVMZ043TVZJPHRZst/B8b65web" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js" crossorigin="anonymous" integrity="sha384-wagZhIFgY4hD+7awjQjR4e2E294y6J2HSnd8eTNc15ZubTeQeVRZwhQJ+W6hnBsf" defer></script>
    <script src="<?= basePath('js/viewer.js') ?>?v=15" defer></script>
    <?php endif; ?>
    <script src="<?= basePath('js/main.js') ?>?v=8" defer></script>
    <script>
        // Focus trap for modals (accessibility)
        function trapFocus(el) {
            el.addEventListener('keydown', function(e) {
                if (e.key !== 'Tab') return;
                var focusable = el.querySelectorAll('button, input, select, textarea, [tabindex]:not([tabindex="-1"])');
                if (focusable.length === 0) return;
                var first = focusable[0], last = focusable[focusable.length - 1];
                if (e.shiftKey) { if (document.activeElement === first) { e.preventDefault(); last.focus(); } }
                else { if (document.activeElement === last) { e.preventDefault(); first.focus(); } }
            });
        }

        // Toast notification helper (works before main.js loads)
        function showToast(message, type) {
            type = type || 'info';
            if (window.siloUI && window.siloUI.toasts) {
                window.siloUI.toasts.show(message, type);
                return;
            }
            var container = document.querySelector('.toast-container');
            if (!container) {
                container = document.createElement('div');
                container.className = 'toast-container';
                container.setAttribute('aria-live', 'polite');
                container.setAttribute('role', 'status');
                document.body.appendChild(container);
            }
            var toast = document.createElement('div');
            toast.className = 'toast toast-' + type;
            toast.innerHTML = '<span class="toast-message"></span><button type="button" class="toast-close" aria-label="Close">&times;</button>';
            toast.querySelector('.toast-message').textContent = message;
            toast.querySelector('.toast-close').onclick = function() {
                toast.classList.remove('show');
                setTimeout(function() { toast.remove(); }, 300);
            };
            container.appendChild(toast);
            requestAnimationFrame(function() { toast.classList.add('show'); });
            setTimeout(function() {
                toast.classList.remove('show');
                setTimeout(function() { toast.remove(); }, 300);
            }, 4000);
        }

        // Confirm dialog replacement (returns Promise)
        function showConfirm(message) {
            return new Promise(function(resolve) {
                var existing = document.getElementById('confirm-modal');
                if (existing) existing.remove();
                var overlay = document.createElement('div');
                overlay.id = 'confirm-modal';
                overlay.className = 'modal-overlay';
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
                overlay.querySelector('#confirm-ok').onclick = function() { releaseFocus(overlay); overlay.remove(); resolve(true); };
                overlay.querySelector('#confirm-cancel').onclick = function() { releaseFocus(overlay); overlay.remove(); resolve(false); };
                overlay.addEventListener('click', function(e) { if (e.target === overlay) { releaseFocus(overlay); overlay.remove(); resolve(false); } });
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
                overlay.className = 'modal-overlay';
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
                function submit() { var v = input.value; releaseFocus(overlay); overlay.remove(); resolve(v); }
                overlay.querySelector('#prompt-ok').onclick = submit;
                overlay.querySelector('#prompt-cancel').onclick = function() { releaseFocus(overlay); overlay.remove(); resolve(null); };
                input.addEventListener('keydown', function(e) { if (e.key === 'Enter') submit(); if (e.key === 'Escape') { releaseFocus(overlay); overlay.remove(); resolve(null); } });
                overlay.addEventListener('click', function(e) { if (e.target === overlay) { releaseFocus(overlay); overlay.remove(); resolve(null); } });
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

        // Password visibility toggle
        var _eyeOpen = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        var _eyeClosed = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
        function togglePasswordVisibility(btn) {
            var input = btn.parentElement.querySelector('input');
            if (!input) return;
            var isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            btn.innerHTML = isPassword ? _eyeClosed : _eyeOpen;
            btn.title = isPassword ? 'Hide password' : 'Show password';
            btn.setAttribute('aria-label', btn.title);
        }

        // Relative time formatting
        function formatRelativeTime(dateStr) {
            if (!dateStr) return '';
            var now = new Date();
            var then = new Date(dateStr.replace(' ', 'T'));
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

        // Global Escape key: close any open modal overlay
        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;
            var modals = document.querySelectorAll('.modal-overlay[style*="flex"], .modal-overlay[style*="block"]');
            modals.forEach(function(m) { m.style.display = 'none'; });
        });

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
                icon.textContent = theme === 'light' ? '\u263E' : '\u2600';
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
                indicator.classList.toggle('open');
            }
        }

        var _lastConversionRemaining = -1;

        function refreshQueueStatus() {
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

                if (hasActivity) {
                    badge.textContent = data.active;
                    badge.style.display = '';
                    var html = '';

                    // Show conversion progress if active
                    if (data.conversions && convRemaining > 0) {
                        var c = data.conversions;
                        html += '<div class="queue-job queue-job-processing">' +
                            '<span class="queue-job-status">&#9881;</span>' +
                            '<span class="queue-job-name">Converting: ' + c.completed + '/' + c.total + ' completed</span>' +
                            (c.failed > 0 ? '<span class="queue-job-time" style="color:var(--color-danger)">' + c.failed + ' failed</span>' : '') +
                            '</div>';
                    }

                    // Show other jobs
                    data.jobs.forEach(function(job) {
                        if (job.name === 'Convert Stl To3mf') return;
                        var statusClass = job.status === 'processing' ? 'queue-job-processing' : 'queue-job-pending';
                        var statusIcon = job.status === 'processing' ? '&#9654;' : '&#9679;';
                        var ago = timeAgo(job.created_at);
                        html += '<div class="queue-job ' + statusClass + '">' +
                            '<span class="queue-job-status">' + statusIcon + '</span>' +
                            '<span class="queue-job-name">' + escapeHtml(job.name) + '</span>' +
                            '<span class="queue-job-time">' + ago + '</span>' +
                            '</div>';
                    });
                    body.innerHTML = html;
                } else {
                    badge.style.display = 'none';
                    body.innerHTML = '<div class="queue-empty">No active tasks</div>';
                }

                // Detect conversion completion: was converting, now done
                if (_lastConversionRemaining > 0 && convRemaining === 0) {
                    showConversionToast('All conversions completed');
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

        function showConversionToast(message) {
            var toast = document.createElement('div');
            toast.textContent = message;
            toast.style.cssText = 'position:fixed;bottom:2rem;left:50%;transform:translateX(-50%);background:var(--color-success,#22c55e);color:#fff;padding:0.75rem 1.5rem;border-radius:var(--radius,0.5rem);z-index:9999;font-weight:500;box-shadow:0 4px 12px rgba(0,0,0,0.3);transition:opacity 0.5s;';
            document.body.appendChild(toast);
            setTimeout(function() { toast.style.opacity = '0'; }, 2500);
            setTimeout(function() { toast.remove(); }, 3000);
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

        function escapeHtml(str) {
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
        }

        // Register Service Worker for PWA
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/sw.js')
                    .catch(function() {});
            });
        }
    </script>
    <?php if (isLoggedIn()) : ?>
        <?= Csrf::metaTag() ?>
        <?= Csrf::ajaxSetupScript() ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                refreshQueueStatus();
                setInterval(refreshQueueStatus, 15000);
            });
            document.addEventListener('click', function(e) {
                var indicator = document.getElementById('queue-indicator');
                if (indicator && indicator.classList.contains('open') && !indicator.contains(e.target)) {
                    indicator.classList.remove('open');
                }
            });
        </script>
    <?php endif; ?>
<?php if (class_exists('PluginManager')) : ?>
    <?= PluginManager::getInstance()->renderStyles() ?>
<?php endif; ?>
<?php if (class_exists('PluginManager')) : ?>
    <?= PluginManager::applyFilter('head_tags', '') ?>
<?php endif; ?>
<script>
var SILO_MODEL_BASE = '<?= htmlspecialchars(rtrim(route('model.show', ['id' => 0]), '0'), ENT_QUOTES) ?>';
(function() {
    'use strict';

    var RECENT_KEY = 'silo_recent_searches';
    var MAX_RECENT = 10;
    var debounceTimer = null;

    function getRecent() {
        try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); }
        catch(e) { return []; }
    }

    function saveRecent(query) {
        if (!query.trim()) return;
        var list = getRecent().filter(function(q) { return q !== query; });
        list.unshift(query);
        if (list.length > MAX_RECENT) list = list.slice(0, MAX_RECENT);
        try { localStorage.setItem(RECENT_KEY, JSON.stringify(list)); }
        catch(e) {}
    }

    function clearRecent() {
        try { localStorage.removeItem(RECENT_KEY); }
        catch(e) {}
    }

    var activeIndex = -1;

    function escapeHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    function highlightMatch(text, query) {
        if (!query) return escapeHtml(text);
        var escaped = escapeHtml(text);
        var re = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        return escaped.replace(re, '<strong>$1</strong>');
    }

    function renderDropdown(suggestions, recentMatches, browseBase, query) {
        var dropdown = document.getElementById('search-dropdown');
        var recentSection = document.getElementById('search-recent');
        var recentList = document.getElementById('search-recent-list');
        var resultsSection = document.getElementById('search-results');
        var resultsList = document.getElementById('search-results-list');

        // Recent searches section
        if (recentMatches.length > 0) {
            recentList.innerHTML = recentMatches.slice(0, 5).map(function(q) {
                return '<li role="option"><a href="' + escapeHtml(browseBase + '?q=' + encodeURIComponent(q)) + '" class="search-dropdown-item search-dropdown-recent">' +
                       '<span class="search-dropdown-icon">&#128336;</span>' + highlightMatch(q, query) + '</a></li>';
            }).join('');
            recentSection.hidden = false;
        } else {
            recentSection.hidden = true;
        }

        // Model suggestions section
        if (suggestions.length > 0) {
            resultsList.innerHTML = suggestions.map(function(s) {
                var badge = s.match === 'part' ? ' <span class="search-match-badge">part match</span>'
                          : s.type === 'tag' ? ' <span class="search-match-badge">tag</span>'
                          : s.type === 'category' ? ' <span class="search-match-badge">category</span>' : '';
                var icon = s.type === 'tag' ? '&#127991;' : s.type === 'category' ? '&#128193;' : '&#128196;';
                var href = s.url ? s.url : SILO_MODEL_BASE + s.id;
                return '<li role="option"><a href="' + href + '" class="search-dropdown-item">' +
                       '<span class="search-dropdown-icon">' + icon + '</span>' + highlightMatch(s.name, query) + badge + '</a></li>';
            }).join('');
            resultsSection.hidden = false;
        } else {
            resultsSection.hidden = true;
        }

        // "Search for ..." link at bottom
        var searchLink = document.getElementById('search-browse-link');
        if (query && query.length > 0) {
            if (searchLink) {
                searchLink.href = browseBase + '?q=' + encodeURIComponent(query);
                searchLink.querySelector('.search-browse-query').textContent = query;
                searchLink.parentElement.hidden = false;
            }
        } else {
            if (searchLink) searchLink.parentElement.hidden = true;
        }

        var hasContent = recentMatches.length > 0 || suggestions.length > 0 || (query && query.length > 0);
        dropdown.hidden = !hasContent;
        input.setAttribute('aria-expanded', hasContent ? 'true' : 'false');
        activeIndex = -1;
    }

    function closeDropdown() {
        var dropdown = document.getElementById('search-dropdown');
        if (dropdown) dropdown.hidden = true;
        var input = document.getElementById('search-input');
        if (input) input.setAttribute('aria-expanded', 'false');
    }

    document.addEventListener('DOMContentLoaded', function() {
        var input = document.getElementById('search-input');
        var form = document.getElementById('search-form');
        var dropdown = document.getElementById('search-dropdown');
        var clearBtn = document.getElementById('search-clear-recent');
        var browseBase = form ? form.getAttribute('action') : '/browse';

        if (!input || !dropdown) return;

        // Save search to recent on form submit
        form && form.addEventListener('submit', function() {
            saveRecent(input.value.trim());
        });

        // Clear recent searches
        clearBtn && clearBtn.addEventListener('click', function(e) {
            e.preventDefault();
            clearRecent();
            closeDropdown();
        });

        // Input: debounce and fetch suggestions
        input.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            var q = input.value.trim();

            if (q.length === 0) {
                // Show only recent searches when no query
                var recent = getRecent();
                renderDropdown([], recent, browseBase, '');
                return;
            }

            // Filter recent searches client-side as user types
            var recentMatches = getRecent().filter(function(r) {
                return r.toLowerCase().includes(q.toLowerCase());
            });

            debounceTimer = setTimeout(function() {
                fetch('/actions/search-suggest?q=' + encodeURIComponent(q), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    renderDropdown(data.suggestions || [], recentMatches, browseBase, q);
                })
                .catch(function() {
                    renderDropdown([], recentMatches, browseBase, q);
                });
            }, 300);
        });

        // Show recent searches when focused with empty input
        input.addEventListener('focus', function() {
            if (input.value.trim() === '') {
                var recent = getRecent();
                if (recent.length > 0) {
                    renderDropdown([], recent, browseBase, '');
                }
            }
        });

        // Keyboard navigation
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { closeDropdown(); return; }
            var items = dropdown.querySelectorAll('.search-dropdown-item');
            if (!items.length || dropdown.hidden) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIndex = (activeIndex + 1) % items.length;
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = activeIndex <= 0 ? items.length - 1 : activeIndex - 1;
            } else if (e.key === 'Enter' && activeIndex >= 0) {
                e.preventDefault();
                items[activeIndex].click();
                return;
            } else { return; }
            items.forEach(function(el, i) { el.classList.toggle('active', i === activeIndex); });
        });

        // Close on click outside
        document.addEventListener('click', function(e) {
            var container = input.closest('.search-container');
            if (container && !container.contains(e.target)) {
                closeDropdown();
            }
        });
    });
})();
</script>
</head>
<body>
    <a href="#main-content" class="skip-to-content">Skip to content</a>
    <header class="site-header">
        <div class="header-content">
            <a href="<?= route('home') ?>" class="logo">
                <?php $logoPath = getSetting('logo_path', ''); if ($logoPath) : ?>
                <img src="<?= rtrim(defined('SITE_URL') ? SITE_URL : '', '/') ?>/assets/<?= htmlspecialchars($logoPath) ?>" alt="<?= htmlspecialchars(SITE_NAME) ?>" class="logo-img">
                <?php endif; ?>
                <span class="logo-text"><?= htmlspecialchars(SITE_NAME) ?></span>
            </a>
            <button type="button" class="mobile-menu-toggle" onclick="toggleMobileMenu()" aria-label="Toggle menu" aria-expanded="false">
                <span class="hamburger-icon"></span>
            </button>
            <nav class="main-nav" aria-label="Main navigation">
                <a href="<?= route('browse') ?>" <?= ($activePage ?? '') === 'browse' ? 'class="active" aria-current="page"' : '' ?>>Browse</a>
                <?php if (isFeatureEnabled('categories')) : ?>
                <a href="<?= route('categories') ?>" <?= ($activePage ?? '') === 'categories' ? 'class="active" aria-current="page"' : '' ?>>Categories</a>
                <?php endif; ?>
                <?php if (isFeatureEnabled('collections')) : ?>
                <a href="<?= route('collections') ?>" <?= ($activePage ?? '') === 'collections' ? 'class="active" aria-current="page"' : '' ?>>Collections</a>
                <?php endif; ?>
                <?php if (isFeatureEnabled('tags')) : ?>
                <a href="<?= route('tags') ?>" <?= ($activePage ?? '') === 'tags' ? 'class="active" aria-current="page"' : '' ?>>Tags</a>
                <?php endif; ?>
                <?php if (canUpload()) : ?>
                <a href="<?= route('upload') ?>" <?= ($activePage ?? '') === 'upload' ? 'class="active" aria-current="page"' : '' ?>>Upload</a>
                <?php endif; ?>
<?php if (class_exists('PluginManager')) :
    $pluginNavItems = PluginManager::applyFilter('nav_items', []);
    foreach ($pluginNavItems as $navItem) : ?>
    <a href="<?= htmlspecialchars($navItem['url'] ?? '#') ?>" <?= !empty($navItem['active']) ? 'class="active" aria-current="page"' : '' ?>>
        <?php if (!empty($navItem['icon'])) :
            ?><i data-feather="<?= htmlspecialchars($navItem['icon']) ?>"></i> <?php
        endif; ?>
        <?= htmlspecialchars($navItem['label'] ?? '') ?>
    </a>
    <?php endforeach;
endif; ?>
            </nav>
            <div class="header-actions">
                <div class="search-container" style="position: relative;">
                    <form id="search-form" action="<?= route('browse') ?>" method="get" role="search">
                        <input type="search" id="search-input" name="q" class="search-bar" placeholder="Search models..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" autocomplete="off" aria-label="Search models" aria-expanded="false" aria-controls="search-dropdown" enterkeyhint="search">
                    </form>
                    <div id="search-dropdown" class="search-dropdown" hidden>
                        <div id="search-recent" class="search-dropdown-section" hidden>
                            <div class="search-dropdown-header">
                                <span>Recent</span>
                                <button type="button" id="search-clear-recent" class="search-dropdown-clear">Clear</button>
                            </div>
                            <ul id="search-recent-list" class="search-dropdown-list" role="listbox"></ul>
                        </div>
                        <div id="search-results" class="search-dropdown-section" hidden>
                            <div class="search-dropdown-header"><span>Models</span></div>
                            <ul id="search-results-list" class="search-dropdown-list" role="listbox"></ul>
                        </div>
                        <div class="search-dropdown-section" hidden>
                            <a id="search-browse-link" href="<?= route('browse') ?>" class="search-dropdown-item search-browse-all">
                                <span class="search-dropdown-icon">&#128269;</span>Search for "<span class="search-browse-query"></span>"
                            </a>
                        </div>
                    </div>
                </div>
                <?php if (isFeatureEnabled('dark_theme') && $allowUserTheme) : ?>
                <button type="button" class="theme-toggle" onclick="toggleTheme()" title="Toggle theme">
                    <span id="theme-icon"><?= $currentTheme === 'light' ? '&#9790;' : '&#9728;' ?></span>
                </button>
                <?php endif; ?>
                <?php if (isLoggedIn()) : ?>
                    <?php $user = getCurrentUser(); ?>
                    <?php if (isFeatureEnabled('favorites')) : ?>
                    <a href="<?= route('favorites') ?>" class="btn btn-secondary" title="My Favorites">&#9829;</a>
                    <?php endif; ?>
                    <div class="queue-indicator" id="queue-indicator" title="Background Tasks">
                        <button type="button" class="btn btn-secondary queue-btn" onclick="toggleQueueDropdown()">
                            &#9881;<span class="queue-badge" id="queue-badge" style="display:none;">0</span>
                        </button>
                        <div class="queue-dropdown" id="queue-dropdown">
                            <div class="queue-dropdown-header">Background Tasks</div>
                            <div class="queue-dropdown-body" id="queue-dropdown-body">
                                <div class="queue-empty">No active tasks</div>
                            </div>
                        </div>
                    </div>
                    <?php if ($user['is_admin']) : ?>
                        <a href="<?= route('admin.settings') ?>" class="btn btn-secondary">Admin</a>
                    <?php endif; ?>
                    <div class="user-dropdown">
                        <button type="button" class="user-dropdown-toggle" aria-haspopup="true" aria-expanded="false">
                            <span class="user-name"><?= htmlspecialchars($user['username']) ?></span>
                            <span class="dropdown-arrow">&#9662;</span>
                        </button>
                        <div class="user-dropdown-menu" role="menu">
                            <a href="<?= route('settings') ?>" role="menuitem">&#9881; Settings</a>
                            <?php if (isFeatureEnabled('favorites')) : ?>
                            <a href="<?= route('favorites') ?>" role="menuitem">&#9829; Favorites</a>
                            <?php endif; ?>
                            <div class="dropdown-divider" role="separator"></div>
                            <a href="<?= route('logout') ?>" role="menuitem">&#10140; Log Out</a>
                        </div>
                    </div>
                <?php else : ?>
                    <a href="<?= route('login') ?>" class="btn btn-primary">Log In</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main id="main-content">
