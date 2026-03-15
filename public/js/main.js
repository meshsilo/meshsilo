// Main JavaScript for Silo UI enhancements

// =====================
// Lazy Loading for 3D Model Viewers
// =====================
class LazyModelLoader {
    constructor() {
        this.observer = null;
        this.loadedViewers = new WeakSet();
        this.init();
    }

    init() {
        this.observer = new IntersectionObserver(
            (entries) => this.handleIntersection(entries),
            { rootMargin: '100px', threshold: 0.1 }
        );

        // Observe grid, list, and detail view thumbnails
        document.querySelectorAll('.model-thumbnail[data-model-url], .model-list-thumbnail[data-model-url], .model-detail-thumbnail[data-model-url]').forEach(el => {
            this.observer.observe(el);
        });
    }

    handleIntersection(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting && !this.loadedViewers.has(entry.target)) {
                this.loadedViewers.add(entry.target);
                this.loadViewer(entry.target);
            }
        });
    }

    loadViewer(thumbnail) {
        const url = thumbnail.dataset.modelUrl;
        const fileType = thumbnail.dataset.fileType;
        if (!url || !fileType) return;

        // Check if required libraries are available
        if (typeof THREE === 'undefined' || typeof ModelViewer === 'undefined') {
            console.warn('3D viewer libraries not loaded');
            return;
        }

        // Add loading state - use lazy placeholder effect
        thumbnail.classList.add('loading', 'lazy-load-placeholder');

        // Small delay before loading to avoid loading models that are quickly scrolled past
        setTimeout(() => {
            // Check if still in viewport
            const rect = thumbnail.getBoundingClientRect();
            const inViewport = rect.top < window.innerHeight && rect.bottom > 0;
            if (!inViewport) {
                thumbnail.classList.remove('loading', 'lazy-load-placeholder');
                this.loadedViewers.delete(thumbnail);
                return;
            }

            // Create viewer container
            const viewerContainer = document.createElement('div');
            viewerContainer.className = 'thumbnail-viewer';
            viewerContainer.style.cssText = 'position: absolute; top: 0; left: 0; width: 100%; height: 100%;';
            thumbnail.appendChild(viewerContainer);

            // Get background color based on current theme
            const isLightTheme = document.documentElement.getAttribute('data-theme') === 'light';
            const bgColor = isLightTheme ? 0xf8fafc : 0x1e293b;

            // Initialize viewer - wrap in try-catch for WebGL errors
            let viewer;
            try {
                viewer = new ModelViewer(viewerContainer, {
                    autoRotate: true,
                    interactive: false,
                    backgroundColor: bgColor
                });
            } catch (e) {
                console.warn('WebGL not available for thumbnail:', e.message);
                thumbnail.classList.remove('loading', 'lazy-load-placeholder');
                thumbnail.classList.add('no-webgl');
                viewerContainer.remove();
                return;
            }

            // Load model with timeout
            const loadTimeout = setTimeout(() => {
                console.warn('Thumbnail load timed out:', url);
                thumbnail.classList.remove('loading', 'lazy-load-placeholder');
                thumbnail.classList.add('load-error');
                viewerContainer.remove();
            }, 15000); // 15 second timeout

            viewer.loadModel(url, fileType)
                .then(() => {
                    clearTimeout(loadTimeout);
                    thumbnail.classList.remove('loading', 'lazy-load-placeholder');
                    thumbnail.classList.add('has-viewer', 'loaded');
                })
                .catch(err => {
                    clearTimeout(loadTimeout);
                    console.warn('Failed to load thumbnail model:', err);
                    thumbnail.classList.remove('loading', 'lazy-load-placeholder');
                    thumbnail.classList.add('load-error');
                    viewerContainer.remove();
                });
        }, 200); // 200ms delay
    }
}

// =====================
// Scroll Animations
// =====================
class ScrollAnimations {
    constructor() {
        this.observer = null;
        this.init();
    }

    init() {
        this.observer = new IntersectionObserver(
            (entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('animate-in');
                        this.observer.unobserve(entry.target);
                    }
                });
            },
            { threshold: 0.1, rootMargin: '0px 0px -50px 0px' }
        );

        // Observe cards and sections
        document.querySelectorAll('.model-card, .category-card-large, .collection-card, .stat-card').forEach((el, index) => {
            el.style.setProperty('--animation-delay', `${index * 50}ms`);
            el.classList.add('animate-target');
            this.observer.observe(el);
        });
    }
}

// =====================
// Search Functionality
// =====================
class SearchHandler {
    constructor() {
        this.searchBar = document.querySelector('.search-bar');
        this.searchResults = null;
        this.debounceTimer = null;
        this.init();
    }

    init() {
        if (!this.searchBar) return;

        // Create search results dropdown
        this.searchResults = document.createElement('div');
        this.searchResults.className = 'search-results';
        this.searchBar.parentNode.style.position = 'relative';
        this.searchBar.parentNode.appendChild(this.searchResults);

        // Bind events
        this.searchBar.addEventListener('input', (e) => this.handleInput(e));
        this.searchBar.addEventListener('focus', () => this.showResults());
        this.searchBar.addEventListener('keydown', (e) => this.handleKeydown(e));
        document.addEventListener('click', (e) => this.handleClickOutside(e));
    }

    handleInput(e) {
        const query = e.target.value.trim();

        clearTimeout(this.debounceTimer);

        if (query.length < 2) {
            this.hideResults();
            return;
        }

        this.debounceTimer = setTimeout(() => this.search(query), 300);
    }

    async search(query) {
        try {
            const response = await fetch(`/actions/search-suggest?q=${encodeURIComponent(query)}&limit=5`);
            if (!response.ok) throw new Error('Search failed');

            const results = await response.json();
            this.renderResults(results, query);
        } catch (err) {
            console.warn('Search error:', err);
            this.searchResults.innerHTML = '<div class="search-no-results">Search unavailable</div>';
            this.showResults();
        }
    }

    renderResults(results, query) {
        if (!results || results.length === 0) {
            this.searchResults.innerHTML = '<div class="search-no-results">No results found</div>';
            this.showResults();
            return;
        }

        const html = results.map(model => `
            <a href="${SILO_MODEL_BASE}${model.id}" class="search-result-item">
                <span class="search-result-name">${this.highlight(model.name, query)}</span>
                ${model.creator ? `<span class="search-result-creator">by ${this.escapeHtml(model.creator)}</span>` : ''}
            </a>
        `).join('');

        this.searchResults.innerHTML = html + `
            <a href="/browse?q=${encodeURIComponent(query)}" class="search-view-all">
                View all results
            </a>
        `;
        this.showResults();
    }

    highlight(text, query) {
        const escaped = this.escapeHtml(text);
        const regex = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
        return escaped.replace(regex, '<mark>$1</mark>');
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    handleKeydown(e) {
        if (e.key === 'Escape') {
            this.hideResults();
            this.searchBar.blur();
        } else if (e.key === 'Enter') {
            const query = this.searchBar.value.trim();
            if (query) {
                window.location.href = `/browse?q=${encodeURIComponent(query)}`;
            }
        }
    }

    handleClickOutside(e) {
        if (!this.searchBar.contains(e.target) && !this.searchResults.contains(e.target)) {
            this.hideResults();
        }
    }

    showResults() {
        if (this.searchResults.innerHTML) {
            this.searchResults.classList.add('active');
        }
    }

    hideResults() {
        this.searchResults.classList.remove('active');
    }
}

// =====================
// Toast Notifications
// =====================
class ToastManager {
    constructor() {
        this.container = null;
        this.init();
    }

    init() {
        this.container = document.createElement('div');
        this.container.className = 'toast-container';
        this.container.setAttribute('aria-live', 'polite');
        this.container.setAttribute('role', 'status');
        document.body.appendChild(this.container);

        // Auto-convert alert messages to toasts
        document.querySelectorAll('.alert').forEach(alert => {
            const type = alert.classList.contains('alert-success') ? 'success' :
                        alert.classList.contains('alert-error') ? 'error' : 'info';
            this.show(alert.textContent, type);
            alert.style.display = 'none';
        });
    }

    show(message, type = 'info', duration = 4000) {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;

        const messageSpan = document.createElement('span');
        messageSpan.className = 'toast-message';
        messageSpan.textContent = message;

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'toast-close';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.innerHTML = '&times;';

        toast.appendChild(messageSpan);
        toast.appendChild(closeBtn);

        closeBtn.addEventListener('click', () => {
            this.dismiss(toast);
        });

        this.container.appendChild(toast);

        // Trigger animation
        requestAnimationFrame(() => {
            toast.classList.add('show');
        });

        // Auto dismiss
        if (duration > 0) {
            setTimeout(() => this.dismiss(toast), duration);
        }
    }

    dismiss(toast) {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }
}

// =====================
// Card Hover Effects
// =====================
class CardEffects {
    constructor() {
        this.init();
    }

    init() {
        document.querySelectorAll('.model-card').forEach(card => {
            card.addEventListener('mouseenter', (e) => this.handleMouseEnter(e));
            card.addEventListener('mouseleave', (e) => this.handleMouseLeave(e));
        });
    }

    handleMouseEnter(e) {
        const card = e.currentTarget;
        const rect = card.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;

        card.style.setProperty('--mouse-x', `${x}px`);
        card.style.setProperty('--mouse-y', `${y}px`);
    }

    handleMouseLeave(e) {}
}

// =====================
// Keyboard Shortcuts
// =====================
class KeyboardShortcuts {
    constructor() {
        this.currentIndex = -1;
        this.modelCards = [];
        this.shortcuts = {
            'j': { description: 'Next model', action: () => this.navigate(1) },
            'k': { description: 'Previous model', action: () => this.navigate(-1) },
            'o': { description: 'Open selected', action: () => this.openSelected() },
            'Enter': { description: 'Open selected', action: () => this.openSelected() },
            'f': { description: 'Toggle favorite', action: () => this.toggleFavorite() },
            '/': { description: 'Focus search', action: (e) => { e.preventDefault(); this.focusSearch(); } },
            'Escape': { description: 'Clear focus', action: () => this.clearFocus() },
            '?': { description: 'Show shortcuts', action: () => this.showHelp() },
            'g h': { description: 'Go home', action: () => window.location.href = '/' },
            'g b': { description: 'Go browse', action: () => window.location.href = '/browse' },
            'g f': { description: 'Go favorites', action: () => window.location.href = '/favorites' },
        };
        this.pendingKey = null;
        this.helpModal = null;
        this.init();
    }

    init() {
        this.updateModelCards();

        document.addEventListener('keydown', (e) => this.handleKeydown(e));

        // Re-index cards on page updates (debounced, scoped to main content)
        let updateTimer = null;
        const observer = new MutationObserver(() => {
            clearTimeout(updateTimer);
            updateTimer = setTimeout(() => this.updateModelCards(), 200);
        });
        const mainContent = document.querySelector('.main-content, .model-grid, main') || document.body;
        observer.observe(mainContent, { childList: true, subtree: true });

        // Create help button
        this.createHelpButton();
    }

    updateModelCards() {
        this.modelCards = Array.from(document.querySelectorAll('.model-card, .model-list-item'));
    }

    handleKeydown(e) {
        // Ignore if typing in input fields
        if (e.target.matches('input, textarea, select, [contenteditable]')) {
            if (e.key === 'Escape') {
                e.target.blur();
            }
            return;
        }

        // Handle two-key shortcuts (g + key)
        if (this.pendingKey === 'g') {
            const combo = `g ${e.key}`;
            if (this.shortcuts[combo]) {
                e.preventDefault();
                this.shortcuts[combo].action(e);
            }
            this.pendingKey = null;
            return;
        }

        if (e.key === 'g' && !e.ctrlKey && !e.metaKey && !e.altKey) {
            this.pendingKey = 'g';
            setTimeout(() => { this.pendingKey = null; }, 1000);
            return;
        }

        // Handle single-key shortcuts
        const key = e.shiftKey && e.key === '/' ? '?' : e.key;
        if (this.shortcuts[key] && !e.ctrlKey && !e.metaKey && !e.altKey) {
            this.shortcuts[key].action(e);
        }
    }

    navigate(direction) {
        if (this.modelCards.length === 0) return;

        // Remove current highlight
        if (this.currentIndex >= 0 && this.modelCards[this.currentIndex]) {
            this.modelCards[this.currentIndex].classList.remove('keyboard-selected');
        }

        // Update index
        this.currentIndex += direction;
        if (this.currentIndex < 0) this.currentIndex = this.modelCards.length - 1;
        if (this.currentIndex >= this.modelCards.length) this.currentIndex = 0;

        // Highlight new card
        const card = this.modelCards[this.currentIndex];
        card.classList.add('keyboard-selected');
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    openSelected() {
        if (this.currentIndex >= 0 && this.modelCards[this.currentIndex]) {
            const card = this.modelCards[this.currentIndex];
            const link = card.querySelector('a[href*="/model/"]') || card;
            const modelId = card.dataset.modelId;
            if (modelId) {
                window.location.href = SILO_MODEL_BASE + modelId;
            } else if (link.href) {
                window.location.href = link.href;
            }
        }
    }

    toggleFavorite() {
        if (this.currentIndex >= 0 && this.modelCards[this.currentIndex]) {
            const card = this.modelCards[this.currentIndex];
            const favoriteBtn = card.querySelector('.favorite-btn, .model-card-favorite');
            if (favoriteBtn) {
                favoriteBtn.click();
            } else {
                // If on model page, try page-level favorite
                const pageFavoriteBtn = document.querySelector('.favorite-btn');
                if (pageFavoriteBtn) pageFavoriteBtn.click();
            }
        } else {
            // Model page shortcut
            const favoriteBtn = document.querySelector('.favorite-btn');
            if (favoriteBtn) favoriteBtn.click();
        }
    }

    focusSearch() {
        const searchBar = document.querySelector('.search-bar');
        if (searchBar) {
            searchBar.focus();
            searchBar.select();
        }
    }

    clearFocus() {
        if (this.currentIndex >= 0 && this.modelCards[this.currentIndex]) {
            this.modelCards[this.currentIndex].classList.remove('keyboard-selected');
        }
        this.currentIndex = -1;
        this.hideHelp();
    }

    createHelpButton() {
        const container = document.createElement('div');
        container.className = 'keyboard-shortcuts-help';
        container.innerHTML = `
            <button type="button" class="keyboard-shortcuts-btn" title="Keyboard shortcuts (?)">?</button>
        `;
        container.querySelector('button').addEventListener('click', () => this.showHelp());
        document.body.appendChild(container);
    }

    showHelp() {
        if (this.helpModal) {
            this.hideHelp();
            return;
        }

        this.helpModal = document.createElement('div');
        this.helpModal.className = 'keyboard-shortcuts-modal';
        this.helpModal.innerHTML = `
            <div class="keyboard-shortcuts-content">
                <h3>Keyboard Shortcuts</h3>
                <div class="shortcut-item"><span>Navigation</span><span class="shortcut-key">j</span> / <span class="shortcut-key">k</span></div>
                <div class="shortcut-item"><span>Open model</span><span class="shortcut-key">o</span> or <span class="shortcut-key">Enter</span></div>
                <div class="shortcut-item"><span>Toggle favorite</span><span class="shortcut-key">f</span></div>
                <div class="shortcut-item"><span>Focus search</span><span class="shortcut-key">/</span></div>
                <div class="shortcut-item"><span>Clear selection</span><span class="shortcut-key">Esc</span></div>
                <div class="shortcut-item"><span>Go to Home</span><span class="shortcut-key">g h</span></div>
                <div class="shortcut-item"><span>Go to Browse</span><span class="shortcut-key">g b</span></div>
                <div class="shortcut-item"><span>Go to Favorites</span><span class="shortcut-key">g f</span></div>
                <div class="shortcut-item"><span>Show this help</span><span class="shortcut-key">?</span></div>
            </div>
        `;
        this.helpModal.addEventListener('click', (e) => {
            if (e.target === this.helpModal) this.hideHelp();
        });
        document.body.appendChild(this.helpModal);
    }

    hideHelp() {
        if (this.helpModal) {
            this.helpModal.remove();
            this.helpModal = null;
        }
    }
}

// =====================
// Back to Top Button
// =====================
class BackToTop {
    constructor() {
        this.btn = document.createElement('button');
        this.btn.className = 'back-to-top';
        this.btn.innerHTML = '&#8679;';
        this.btn.title = 'Back to top';
        this.btn.setAttribute('aria-label', 'Scroll to top');
        this.btn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
        document.body.appendChild(this.btn);
        window.addEventListener('scroll', () => {
            this.btn.classList.toggle('visible', window.scrollY > 400);
        }, { passive: true });
    }
}

// =====================
// Modal Focus Trap
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
// Initialize Everything
// =====================
document.addEventListener('DOMContentLoaded', () => {
    // Initialize UI enhancements
    window.siloUI = {
        lazyLoader: new LazyModelLoader(),
        scrollAnimations: new ScrollAnimations(),
        search: new SearchHandler(),
        toasts: new ToastManager(),
        cardEffects: new CardEffects(),
        keyboard: new KeyboardShortcuts(),
        backToTop: new BackToTop()
    };

    // Add smooth scrolling
    document.documentElement.style.scrollBehavior = 'smooth';

    // User dropdown menu toggle
    const userDropdown = document.querySelector('.user-dropdown');
    if (userDropdown) {
        const toggle = userDropdown.querySelector('.user-dropdown-toggle');
        if (toggle) {
            toggle.addEventListener('click', (e) => {
                e.stopPropagation();
                userDropdown.classList.toggle('open');
            });
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!userDropdown.contains(e.target)) {
                userDropdown.classList.remove('open');
            }
        });

        // Close dropdown on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                userDropdown.classList.remove('open');
            }
        });
    }
});

// Expose toast function globally for use in other scripts
window.showToast = (message, type, duration) => {
    if (window.siloUI?.toasts) {
        window.siloUI.toasts.show(message, type, duration);
    }
};
