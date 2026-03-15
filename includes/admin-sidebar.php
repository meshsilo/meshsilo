<?php
// Include features helper if not already loaded
if (!function_exists('isFeatureEnabled')) {
    require_once __DIR__ . '/features.php';
}
?>
            <aside class="admin-sidebar">
                <h3>Admin</h3>
                <nav class="admin-nav" aria-label="Admin navigation">
                    <div class="nav-category" data-category="system">
                        <button class="nav-section" type="button" aria-expanded="true">
                            <span>System</span>
                            <svg class="nav-toggle-icon" width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 4.5L6 7.5L9 4.5"/>
                            </svg>
                        </button>
                        <div class="nav-links">
                            <a href="<?= route('admin.health') ?>" <?= ($adminPage ?? '') === 'health' ? 'class="active" aria-current="page"' : '' ?>>System Health</a>
                            <a href="<?= route('admin.settings') ?>" <?= ($adminPage ?? '') === 'settings' ? 'class="active" aria-current="page"' : '' ?>>Site Settings</a>
                            <a href="<?= route('admin.features') ?>" <?= ($adminPage ?? '') === 'features' ? 'class="active" aria-current="page"' : '' ?>>Features</a>
                            <a href="<?= route('admin.storage') ?>" <?= ($adminPage ?? '') === 'storage' ? 'class="active" aria-current="page"' : '' ?>>Storage</a>
                            <a href="<?= route('admin.database') ?>" <?= ($adminPage ?? '') === 'database' ? 'class="active" aria-current="page"' : '' ?>>Database</a>
                            <a href="<?= route('admin.scheduler') ?>" <?= ($adminPage ?? '') === 'scheduler' ? 'class="active" aria-current="page"' : '' ?>>Scheduled Tasks</a>
                            <a href="<?= route('admin.plugins') ?>" <?= ($adminPage ?? '') === 'plugins' ? 'class="active" aria-current="page"' : '' ?>>Plugins</a>
                            <a href="<?= route('admin.routes') ?>" <?= ($adminPage ?? '') === 'routes' ? 'class="active" aria-current="page"' : '' ?>>Routes</a>
                            <a href="<?= route('admin.cli-tools') ?>" <?= ($adminPage ?? '') === 'cli-tools' ? 'class="active" aria-current="page"' : '' ?>>CLI Tools</a>
                        </div>
                    </div>

                    <div class="nav-category" data-category="users">
                        <button class="nav-section" type="button" aria-expanded="true">
                            <span>Users & Auth</span>
                            <svg class="nav-toggle-icon" width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 4.5L6 7.5L9 4.5"/>
                            </svg>
                        </button>
                        <div class="nav-links">
                            <a href="<?= route('admin.users') ?>" <?= ($adminPage ?? '') === 'users' ? 'class="active" aria-current="page"' : '' ?>>Users</a>
                            <a href="<?= route('admin.groups') ?>" <?= ($adminPage ?? '') === 'groups' ? 'class="active" aria-current="page"' : '' ?>>Groups</a>
                        </div>
                    </div>

                    <div class="nav-category" data-category="content">
                        <button class="nav-section" type="button" aria-expanded="true">
                            <span>Content</span>
                            <svg class="nav-toggle-icon" width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 4.5L6 7.5L9 4.5"/>
                            </svg>
                        </button>
                        <div class="nav-links">
                            <a href="<?= route('admin.models') ?>" <?= ($adminPage ?? '') === 'models' ? 'class="active" aria-current="page"' : '' ?>>Models</a>
                            <?php if (isFeatureEnabled('categories')) : ?>
                            <a href="<?= route('admin.categories') ?>" <?= ($adminPage ?? '') === 'categories' ? 'class="active" aria-current="page"' : '' ?>>Categories</a>
                            <?php endif; ?>
                            <?php if (isFeatureEnabled('collections')) : ?>
                            <a href="<?= route('admin.collections') ?>" <?= ($adminPage ?? '') === 'collections' ? 'class="active" aria-current="page"' : '' ?>>Collections</a>
                            <?php endif; ?>
                            <?php if (isFeatureEnabled('tags')) : ?>
                            <a href="<?= route('admin.tags') ?>" <?= ($adminPage ?? '') === 'tags' ? 'class="active" aria-current="page"' : '' ?>>Tags</a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="nav-category" data-category="security">
                        <button class="nav-section" type="button" aria-expanded="true">
                            <span>Security</span>
                            <svg class="nav-toggle-icon" width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 4.5L6 7.5L9 4.5"/>
                            </svg>
                        </button>
                        <div class="nav-links">
                            <a href="<?= route('admin.security-headers') ?>" <?= ($adminPage ?? '') === 'security-headers' ? 'class="active" aria-current="page"' : '' ?>>Security Headers</a>
                            <a href="<?= route('admin.sessions') ?>" <?= ($adminPage ?? '') === 'sessions' ? 'class="active" aria-current="page"' : '' ?>>Sessions</a>
                            <?php if (isFeatureEnabled('api_keys')) : ?>
                            <a href="<?= route('admin.api-keys') ?>" <?= ($adminPage ?? '') === 'api-keys' ? 'class="active" aria-current="page"' : '' ?>>API Keys</a>
                            <?php endif; ?>
                            <?php if (isFeatureEnabled('activity_log')) : ?>
                            <a href="<?= route('admin.activity') ?>" <?= ($adminPage ?? '') === 'activity' ? 'class="active" aria-current="page"' : '' ?>>Activity Log</a>
                            <a href="<?= route('admin.audit-log') ?>" <?= ($adminPage ?? '') === 'audit-log' ? 'class="active" aria-current="page"' : '' ?>>Audit Log</a>
                            <?php endif; ?>
                        </div>
                    </div>
<?php
if (class_exists('PluginManager')) {
    $pluginMenuGroups = PluginManager::getInstance()->getAdminMenuItems();
    foreach ($pluginMenuGroups as $category => $items) :
        ?>
                    <div class="nav-category" data-category="plugin-<?= htmlspecialchars(strtolower(str_replace(' ', '-', $category))) ?>">
                        <button class="nav-section" type="button" aria-expanded="true">
                            <span><?= htmlspecialchars($category) ?></span>
                            <svg class="nav-toggle-icon" width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 4.5L6 7.5L9 4.5"/>
                            </svg>
                        </button>
                        <div class="nav-links">
                            <?php foreach ($items as $item) : ?>
                            <a href="<?= route($item['route'] ?? 'admin.plugins') ?>" <?= ($adminPage ?? '') === $item['slug'] ? 'class="active" aria-current="page"' : '' ?>><?= htmlspecialchars($item['label']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
        <?php
    endforeach;
}
?>
                </nav>
            </aside>

            <script>
            (function() {
                const STORAGE_KEY = 'silo_admin_sidebar_collapsed';

                // Load collapsed state from localStorage
                function getCollapsedState() {
                    try {
                        return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {};
                    } catch {
                        return {};
                    }
                }

                // Save collapsed state to localStorage
                function saveCollapsedState(state) {
                    try {
                        localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
                    } catch {}
                }

                // Toggle a category
                function toggleCategory(category, forceExpand = null) {
                    const categoryEl = category.closest('.nav-category');
                    const button = category;
                    const links = categoryEl.querySelector('.nav-links');
                    const categoryId = categoryEl.dataset.category;

                    const isExpanded = button.getAttribute('aria-expanded') === 'true';
                    const shouldExpand = forceExpand !== null ? forceExpand : !isExpanded;

                    button.setAttribute('aria-expanded', shouldExpand);
                    links.style.display = shouldExpand ? 'flex' : 'none';

                    // Save state
                    const state = getCollapsedState();
                    state[categoryId] = !shouldExpand;
                    saveCollapsedState(state);
                }

                // Initialize sidebar
                function initSidebar() {
                    const categories = document.querySelectorAll('.admin-sidebar .nav-category');
                    const collapsedState = getCollapsedState();

                    categories.forEach(category => {
                        const categoryId = category.dataset.category;
                        const button = category.querySelector('.nav-section');
                        const links = category.querySelector('.nav-links');
                        const hasActiveLink = category.querySelector('a.active') !== null;

                        // Determine initial state: expand if has active link, otherwise use saved state
                        const shouldCollapse = hasActiveLink ? false : (collapsedState[categoryId] === true);

                        button.setAttribute('aria-expanded', !shouldCollapse);
                        links.style.display = shouldCollapse ? 'none' : 'flex';

                        // Add click handler
                        button.addEventListener('click', function(e) {
                            e.preventDefault();
                            toggleCategory(this);
                        });
                    });
                }

                // Initialize when DOM is ready
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initSidebar);
                } else {
                    initSidebar();
                }
            })();
            </script>
