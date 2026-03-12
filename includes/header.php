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
    <meta name="theme-color" content="#3b82f6">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="description" content="<?= htmlspecialchars(getSetting('site_description', 'Digital Asset Manager for 3D print files')) ?>">
    <title><?= htmlspecialchars($pageTitle ?? SITE_NAME) ?> - <?= htmlspecialchars(SITE_NAME) ?></title>
    <link rel="manifest" href="<?= basePath('manifest.json') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= basePath('images/icon.svg') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= basePath('images/favicon-32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= basePath('images/favicon-16.png') ?>">
    <link rel="apple-touch-icon" href="<?= basePath('images/icon-192.png') ?>">
    <link rel="stylesheet" href="<?= basePath('css/style.css') ?>?v=8">

    <!-- Three.js for 3D model rendering -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/fflate@0.8.0/umd/index.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/STLLoader.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/3MFLoader.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/OBJLoader.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/PLYLoader.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/GLTFLoader.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/ColladaLoader.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/FBXLoader.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/TDSLoader.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/AMFLoader.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js" crossorigin="anonymous"></script>
    <script src="<?= basePath('js/viewer.js') ?>?v=15" defer></script>
    <script src="<?= basePath('js/main.js') ?>?v=8" defer></script>
    <script>
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
            var d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        // Register Service Worker for PWA
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/sw.js')
                    .then(function(registration) {
                        console.log('SW registered:', registration.scope);
                    })
                    .catch(function(error) {
                        console.log('SW registration failed:', error);
                    });
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

    function escapeHtml(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function renderDropdown(suggestions, recentMatches, browseBase) {
        var dropdown = document.getElementById('search-dropdown');
        var recentSection = document.getElementById('search-recent');
        var recentList = document.getElementById('search-recent-list');
        var resultsSection = document.getElementById('search-results');
        var resultsList = document.getElementById('search-results-list');

        // Recent searches section
        if (recentMatches.length > 0) {
            recentList.innerHTML = recentMatches.slice(0, 5).map(function(q) {
                return '<li><a href="' + escapeHtml(browseBase + '?q=' + encodeURIComponent(q)) + '" class="search-dropdown-item search-dropdown-recent">' +
                       '<span class="search-dropdown-icon">&#128336;</span>' + escapeHtml(q) + '</a></li>';
            }).join('');
            recentSection.hidden = false;
        } else {
            recentSection.hidden = true;
        }

        // Model suggestions section
        if (suggestions.length > 0) {
            resultsList.innerHTML = suggestions.map(function(s) {
                return '<li><a href="' + SILO_MODEL_BASE + s.id + '" class="search-dropdown-item">' +
                       '<span class="search-dropdown-icon">&#128196;</span>' + escapeHtml(s.name) + '</a></li>';
            }).join('');
            resultsSection.hidden = false;
        } else {
            resultsSection.hidden = true;
        }

        dropdown.hidden = recentMatches.length === 0 && suggestions.length === 0;
    }

    function closeDropdown() {
        var dropdown = document.getElementById('search-dropdown');
        if (dropdown) dropdown.hidden = true;
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
                renderDropdown([], recent, browseBase);
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
                    renderDropdown(data.suggestions || [], recentMatches, browseBase);
                })
                .catch(function() {
                    renderDropdown([], recentMatches, browseBase);
                });
            }, 300);
        });

        // Show recent searches when focused with empty input
        input.addEventListener('focus', function() {
            if (input.value.trim() === '') {
                var recent = getRecent();
                if (recent.length > 0) {
                    renderDropdown([], recent, browseBase);
                }
            }
        });

        // Close on Escape
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDropdown();
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
            <nav class="main-nav">
                <a href="<?= route('browse') ?>" <?= ($activePage ?? '') === 'browse' ? 'class="active"' : '' ?>>Browse</a>
                <?php if (isFeatureEnabled('categories')) : ?>
                <a href="<?= route('categories') ?>" <?= ($activePage ?? '') === 'categories' ? 'class="active"' : '' ?>>Categories</a>
                <?php endif; ?>
                <?php if (isFeatureEnabled('collections')) : ?>
                <a href="<?= route('collections') ?>" <?= ($activePage ?? '') === 'collections' ? 'class="active"' : '' ?>>Collections</a>
                <?php endif; ?>
                <?php if (isFeatureEnabled('tags')) : ?>
                <a href="<?= route('tags') ?>" <?= ($activePage ?? '') === 'tags' ? 'class="active"' : '' ?>>Tags</a>
                <?php endif; ?>
                <?php if (canUpload()) : ?>
                <a href="<?= route('upload') ?>" <?= ($activePage ?? '') === 'upload' ? 'class="active"' : '' ?>>Upload</a>
                <?php endif; ?>
<?php if (class_exists('PluginManager')) :
    $pluginNavItems = PluginManager::applyFilter('nav_items', []);
    foreach ($pluginNavItems as $navItem) : ?>
    <a href="<?= htmlspecialchars($navItem['url'] ?? '#') ?>" <?= !empty($navItem['active']) ? 'class="active"' : '' ?>>
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
                    <form id="search-form" action="<?= route('browse') ?>" method="get">
                        <input type="search" id="search-input" name="q" class="search-bar" placeholder="Search models..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" autocomplete="off">
                    </form>
                    <div id="search-dropdown" class="search-dropdown" hidden>
                        <div id="search-recent" class="search-dropdown-section" hidden>
                            <div class="search-dropdown-header">
                                <span>Recent</span>
                                <button type="button" id="search-clear-recent" class="search-dropdown-clear">Clear</button>
                            </div>
                            <ul id="search-recent-list" class="search-dropdown-list"></ul>
                        </div>
                        <div id="search-results" class="search-dropdown-section" hidden>
                            <div class="search-dropdown-header"><span>Models</span></div>
                            <ul id="search-results-list" class="search-dropdown-list"></ul>
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
                        <button type="button" class="user-dropdown-toggle">
                            <span class="user-name"><?= htmlspecialchars($user['username']) ?></span>
                            <span class="dropdown-arrow">&#9662;</span>
                        </button>
                        <div class="user-dropdown-menu">
                            <a href="<?= route('settings') ?>">&#9881; Settings</a>
                            <?php if (isFeatureEnabled('favorites')) : ?>
                            <a href="<?= route('favorites') ?>">&#9829; Favorites</a>
                            <?php endif; ?>
                            <div class="dropdown-divider"></div>
                            <a href="<?= route('logout') ?>">&#10140; Log Out</a>
                        </div>
                    </div>
                <?php else : ?>
                    <a href="<?= route('login') ?>" class="btn btn-primary">Log In</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main>
