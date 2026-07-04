// Header live-search dropdown for MeshSilo.
// Relocated verbatim from the inline <script> in includes/header.php. This is
// the sole search-suggest implementation (binds #search-input, fetches
// /actions/search-suggest). Loaded deferred as a cacheable static file.
//
// The model-detail base URL (previously the SILO_MODEL_BASE global) is read
// from window.SiloConfig.modelBase, set inline in header.php before this loads.
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
                       '<span class="search-dropdown-icon"><i class="fa-solid fa-clock"></i></span>' + highlightMatch(q, query) + '</a></li>';
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
                var icon = s.type === 'tag' ? '<i class="fa-solid fa-tag"></i>' : s.type === 'category' ? '<i class="fa-solid fa-folder"></i>' : '<i class="fa-solid fa-file-lines"></i>';
                var href = s.url ? s.url : window.SiloConfig.modelBase + s.id;
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
