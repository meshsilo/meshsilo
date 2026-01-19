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

        document.querySelectorAll('.model-thumbnail[data-model-url]').forEach(el => {
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

        // Add loading state
        thumbnail.classList.add('loading');

        // Create viewer container
        const viewerContainer = document.createElement('div');
        viewerContainer.className = 'thumbnail-viewer';
        viewerContainer.style.cssText = 'position: absolute; top: 0; left: 0; width: 100%; height: 100%;';
        thumbnail.appendChild(viewerContainer);

        // Initialize viewer
        const viewer = new ModelViewer(viewerContainer, {
            autoRotate: true,
            interactive: false,
            backgroundColor: 0x1e293b
        });

        // Load model
        viewer.loadModel(url, fileType)
            .then(() => {
                thumbnail.classList.remove('loading');
                thumbnail.classList.add('has-viewer', 'loaded');
            })
            .catch(err => {
                console.warn('Failed to load thumbnail model:', err);
                thumbnail.classList.remove('loading');
                thumbnail.classList.add('load-error');
                viewerContainer.remove();
            });
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
            const response = await fetch(`search.php?q=${encodeURIComponent(query)}&limit=5`);
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
            <a href="model.php?id=${model.id}" class="search-result-item">
                <span class="search-result-name">${this.highlight(model.name, query)}</span>
                ${model.creator ? `<span class="search-result-creator">by ${this.escapeHtml(model.creator)}</span>` : ''}
            </a>
        `).join('');

        this.searchResults.innerHTML = html + `
            <a href="index.php?search=${encodeURIComponent(query)}" class="search-view-all">
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
                window.location.href = `index.php?search=${encodeURIComponent(query)}`;
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
        toast.innerHTML = `
            <span class="toast-message">${message}</span>
            <button class="toast-close">&times;</button>
        `;

        toast.querySelector('.toast-close').addEventListener('click', () => {
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

    handleMouseLeave(e) {
        // Reset on leave if needed
    }
}

// =====================
// Initialize Everything
// =====================
document.addEventListener('DOMContentLoaded', () => {
    // Initialize UI enhancements
    // Note: LazyModelLoader is disabled as viewer.js handles 3D model initialization
    window.siloUI = {
        // lazyLoader: new LazyModelLoader(),
        scrollAnimations: new ScrollAnimations(),
        search: new SearchHandler(),
        toasts: new ToastManager(),
        cardEffects: new CardEffects()
    };

    // Add smooth scrolling
    document.documentElement.style.scrollBehavior = 'smooth';
});

// Expose toast function globally for use in other scripts
window.showToast = (message, type, duration) => {
    if (window.siloUI?.toasts) {
        window.siloUI.toasts.show(message, type, duration);
    }
};
