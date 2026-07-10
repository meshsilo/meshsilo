// Main JavaScript for Silo UI enhancements
const DEBUG = localStorage.getItem('silo_debug') === '1';

// =====================
// Lazy Loading for 3D Model Viewers
// =====================
class LazyModelLoader {
    constructor() {
        this.observer = null;
        this.loadedViewers = new WeakSet();
        this.pendingElements = new Set();
        // LRU cap on live WebGL contexts. Browsers allow only ~16 total, so keep a
        // bounded working set and dispose the least-recently-visible viewer when a
        // new thumbnail needs a context (it re-creates when scrolled back). Only
        // models without a static thumbnail use a live viewer, so this is a safety net.
        this.viewers = new Map();   // thumbnail -> { viewer, container }; insertion order = LRU
        this.visible = new Set();   // thumbnails currently in the viewport (never evicted)
        this.MAX_LIVE_VIEWERS = 12;
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

        // THREE.js loads async from CDN - retry elements that fired before it was ready
        if (!window.THREE_READY) {
            window.addEventListener('three-ready', () => this.retryFailed(), { once: true });
        }
    }

    retryFailed() {
        this.pendingElements.forEach(el => {
            this.observer.unobserve(el);
            this.observer.observe(el);
        });
        this.pendingElements.clear();
    }

    handleIntersection(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                this.visible.add(entry.target);
                if (this.viewers.has(entry.target)) {
                    this.touch(entry.target); // already live: mark most-recently-used
                } else if (!this.loadedViewers.has(entry.target)) {
                    this.loadedViewers.add(entry.target);
                    this.loadViewer(entry.target);
                }
            } else {
                this.visible.delete(entry.target);
            }
        });
    }

    // Move a live viewer to the most-recently-used position (end of the Map).
    touch(thumbnail) {
        const entry = this.viewers.get(thumbnail);
        if (entry) {
            this.viewers.delete(thumbnail);
            this.viewers.set(thumbnail, entry);
        }
    }

    // At the cap, dispose the oldest off-screen viewer to free a WebGL context.
    evictIfNeeded() {
        if (this.viewers.size < this.MAX_LIVE_VIEWERS) return;
        for (const [thumb, entry] of this.viewers) {
            if (this.visible.has(thumb)) continue; // never tear down a visible viewer
            this.disposeViewer(thumb, entry);
            return;
        }
        // All live viewers are on-screen: allow going slightly over rather than
        // dispose something the user is looking at.
    }

    // Tear down a live viewer; its card re-creates one when scrolled back into view.
    disposeViewer(thumbnail, entry) {
        try { entry.viewer.dispose(); } catch (e) { /* already gone */ }
        if (entry.container && entry.container.parentNode) {
            entry.container.remove();
        }
        this.viewers.delete(thumbnail);
        this.loadedViewers.delete(thumbnail);
        thumbnail.classList.remove('has-viewer', 'loaded');
    }

    loadViewer(thumbnail) {
        const url = thumbnail.dataset.modelUrl;
        const fileType = thumbnail.dataset.fileType;
        if (!url || !fileType) return;

        // Check if required libraries are available
        if (typeof THREE === 'undefined' || typeof window.ModelViewer === 'undefined') {
            DEBUG && console.warn('3D viewer libraries not loaded');
            this.loadedViewers.delete(thumbnail);
            this.pendingElements.add(thumbnail);
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

            // Free a WebGL context slot (LRU) before creating a new one.
            this.evictIfNeeded();

            // Initialize viewer - wrap in try-catch for WebGL errors
            let viewer;
            try {
                viewer = new window.ModelViewer(viewerContainer, {
                    autoRotate: true,
                    interactive: false,
                    backgroundColor: bgColor
                });
                // Track for LRU eviction (newest = most-recently-used, at the Map end).
                this.viewers.set(thumbnail, { viewer: viewer, container: viewerContainer });
            } catch (e) {
                DEBUG && console.warn('WebGL not available for thumbnail:', e.message);
                thumbnail.classList.remove('loading', 'lazy-load-placeholder');
                thumbnail.classList.add('no-webgl');
                viewerContainer.remove();
                return;
            }

            // Load model with timeout
            const loadTimeout = setTimeout(() => {
                DEBUG && console.warn('Thumbnail load timed out:', url);
                thumbnail.classList.remove('loading', 'lazy-load-placeholder');
                thumbnail.classList.add('load-error');
                viewer.dispose();
                viewerContainer.remove();
                this.viewers.delete(thumbnail);
            }, 15000); // 15 second timeout

            viewer.loadModel(url, fileType)
                .then(() => {
                    clearTimeout(loadTimeout);
                    thumbnail.classList.remove('loading', 'lazy-load-placeholder');
                    thumbnail.classList.add('has-viewer', 'loaded');
                })
                .catch(err => {
                    clearTimeout(loadTimeout);
                    DEBUG && console.warn('Failed to load thumbnail model:', err);
                    thumbnail.classList.remove('loading', 'lazy-load-placeholder');
                    thumbnail.classList.add('load-error');
                    viewer.dispose();
                    viewerContainer.remove();
                    this.viewers.delete(thumbnail);
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
        closeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';

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
                window.location.href = window.SiloConfig.modelBase + modelId;
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
        this.btn.innerHTML = '<i class="fa-solid fa-arrow-up"></i>';
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
// window.trapFocus / window.releaseFocus relocated to public/js/ui-common.js
// (loaded before main.js in includes/header.php).

// =====================
// Initialize Everything
// =====================
document.addEventListener('DOMContentLoaded', () => {
    // Initialize UI enhancements
    window.siloUI = {
        lazyLoader: new LazyModelLoader(),
        scrollAnimations: new ScrollAnimations(),
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
                toggle.setAttribute('aria-expanded', userDropdown.classList.contains('open'));
            });
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!userDropdown.contains(e.target)) {
                userDropdown.classList.remove('open');
                if (toggle) toggle.setAttribute('aria-expanded', 'false');
            }
        });

        // Close dropdown on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                userDropdown.classList.remove('open');
                if (toggle) toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }
});

// window.showToast relocated to public/js/ui-common.js
// (loaded before main.js in includes/header.php).

// Global batch-apply helper for bulk operations
window.batchApply = async function(action, extraFields, options = {}) {
    const ids = options.getIds ? options.getIds() : [];
    if (ids.length === 0) {
        showToast(options.emptyMessage || 'Select items first', 'error');
        return null;
    }

    try {
        const formData = new FormData();
        formData.append('action', action);
        if (extraFields) {
            Object.entries(extraFields).forEach(([key, value]) => formData.append(key, value));
        }
        ids.forEach(id => formData.append('model_ids[]', id));

        const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            if (options.successMessage) {
                showToast(options.successMessage(result), 'success');
            }
            if (options.reload) location.reload();
        } else {
            showToast(result.error || 'Unknown error', 'error');
        }
        return result;
    } catch (err) {
        console.error('Batch action error:', err);
        showToast(options.errorMessage || 'Operation failed', 'error');
        return null;
    }
};

// window.escapeHtml relocated to public/js/ui-common.js
// (loaded before main.js in includes/header.php).

// Password visibility toggle (delegated)
const _eyeOpen = '<i class="fa-solid fa-eye"></i>';
const _eyeClosed = '<i class="fa-solid fa-eye-slash"></i>';
function togglePasswordVisibility(btn) {
    const input = btn.parentElement.querySelector('input');
    if (!input) return;
    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    btn.innerHTML = isPassword ? _eyeClosed : _eyeOpen;
    btn.title = isPassword ? 'Hide password' : 'Show password';
    btn.setAttribute('aria-label', btn.title);
}
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.password-toggle');
    if (btn) togglePasswordVisibility(btn);
});
