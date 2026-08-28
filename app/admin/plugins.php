<?php
require_once __DIR__ . '/../../includes/config.php';

// Require admin permission
requireAdminPage('isAdmin', 'You do not have permission to manage plugins.');

$pageTitle = 'Plugin Management';
$activePage = '';
$adminPage = 'plugins';

$message = '';
$error = '';

$pluginManager = PluginManager::getInstance();

// Get active tab
$activeTab = $_GET['tab'] ?? 'installed';
$validTabs = ['installed', 'browse', 'repositories'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'installed';
}

// Handle POST actions
if (($csrfError = Csrf::postError()) !== null) {
    $error = $csrfError;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $result = (new PluginAdminActions($pluginManager))->handle($action, $_POST, $_FILES);
    $message = $result['message'];
    $error = $result['error'];

    // PRG redirect to prevent double submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'check-updates') {
        if (!empty($message)) {
            $_SESSION['plugin_message'] = $message;
        }
        if (!empty($error)) {
            $_SESSION['plugin_error'] = $error;
        }
        header('Location: ' . route('admin.plugins') . '?tab=' . urlencode($activeTab));
        exit;
    }
}

// Pick up flash messages
if (isset($_SESSION['plugin_message'])) {
    $message = $_SESSION['plugin_message'];
    unset($_SESSION['plugin_message']);
}
if (isset($_SESSION['plugin_error'])) {
    $error = $_SESSION['plugin_error'];
    unset($_SESSION['plugin_error']);
}

// Gather data for the active tab
$allPlugins = $pluginManager->getAllPlugins();
$availablePlugins = [];
$repositories = [];
$updates = [];

// Auto-refresh stale registries (older than 1 hour or never fetched).
// Fetched concurrently: this runs during page load, so wall time is the
// slowest single registry rather than the sum of all of them.
$repos = $pluginManager->getRepositories();
$staleUrls = [];
foreach ($repos as $repo) {
    $lastFetched = $repo['last_fetched'] ?? null;
    $isStale = empty($lastFetched) || strtotime($lastFetched) < time() - 3600;
    if (empty($repo['registry_cache']) || $isStale) {
        $staleUrls[] = $repo['url'];
    }
}
if ($staleUrls !== []) {
    $pluginManager->fetchRegistries($staleUrls);
}

// Always load available plugins for source info (needed for update/reinstall buttons)
$availablePlugins = $pluginManager->getAvailablePlugins();
$updates = $pluginManager->checkUpdates();

if ($activeTab === 'repositories') {
    $repositories = $pluginManager->getRepositories();
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <h1>Plugin Management</h1>
            <p>Install, configure, and manage plugins to extend MeshSilo</p>
        </div>

        <?php if ($message): ?>
        <div role="status" class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div role="alert" class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="plugin-tabs">
            <a href="?tab=installed" class="plugin-tab <?= $activeTab === 'installed' ? 'active' : '' ?>">Installed</a>
            <a href="?tab=browse" class="plugin-tab <?= $activeTab === 'browse' ? 'active' : '' ?>">Browse</a>
            <a href="?tab=repositories" class="plugin-tab <?= $activeTab === 'repositories' ? 'active' : '' ?>">Repositories</a>
        </div>

        <!-- Tab Content -->
        <div class="plugin-tab-content">

            <?php if ($activeTab === 'installed'): ?>
            <!-- Upload Plugin -->
            <div class="plugin-upload-section">
                <h3>Upload Plugin</h3>
                <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>" enctype="multipart/form-data" class="plugin-upload-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="install-upload">
                    <div class="upload-row">
                        <input type="file" name="plugin_zip" accept=".zip" required class="form-input upload-input">
                        <button type="submit" class="btn btn-primary">Upload &amp; Install</button>
                    </div>
                    <small class="form-help">Upload a plugin as a .zip file containing a valid plugin.json manifest.</small>
                </form>
            </div>

            <!-- Installed Plugins List -->
            <div class="installed-plugins-header">
                <h3>Installed Plugins</h3>
                <div class="installed-plugins-actions">
                    <?php if (!empty($updates)): ?>
                    <span class="plugin-status-badge badge-update"><?= count($updates) ?> update<?= count($updates) !== 1 ? 's' : '' ?> available</span>
                    <?php endif; ?>
                    <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="refresh-repos">
                        <button type="submit" class="btn btn-secondary btn-sm">Check for Updates</button>
                    </form>
                </div>
            </div>
            <?php if (empty($allPlugins)): ?>
            <div class="empty-state">
                <p>No plugins are installed.</p>
                <p>Upload a plugin above or browse the <a href="?tab=browse">plugin repository</a> to get started.</p>
            </div>
            <?php else: ?>
            <div class="plugin-list">
                <?php foreach ($allPlugins as $id => $plugin):
                    $isActive = !empty($plugin['is_active']);
                    $hasUpdate = isset($updates[$id]);
                    $hasMigrations = is_file($pluginManager->getPluginsDir() . '/' . ($plugin['_dir'] ?? $id) . '/migrations.php');
                    $bootError = $pluginManager->getBootError($id);
                ?>
                <div class="plugin-card <?= $isActive ? 'active' : 'inactive' ?><?= $bootError ? ' has-error' : '' ?>">
                    <div class="plugin-card-header">
                        <div class="plugin-info">
                            <div class="plugin-title-row">
                                <span class="plugin-name"><?= htmlspecialchars($plugin['name']) ?></span>
                                <span class="plugin-version">v<?= htmlspecialchars($plugin['version']) ?></span>
                                <span class="plugin-status-badge <?= $isActive ? 'badge-active' : 'badge-inactive' ?>">
                                    <?= $isActive ? 'Active' : 'Inactive' ?>
                                </span>
                                <?php if ($bootError): ?>
                                <span class="plugin-status-badge badge-danger">Error</span>
                                <?php endif; ?>
                                <?php if ($hasUpdate): ?>
                                <span class="plugin-status-badge badge-update">Update Available (v<?= htmlspecialchars($updates[$id]['available_version']) ?>)</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($plugin['author'])): ?>
                            <span class="plugin-author">by <?= htmlspecialchars($plugin['author']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($plugin['description'])): ?>
                    <p class="plugin-description"><?= htmlspecialchars($plugin['description']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($plugin['requires_plugins']) && is_array($plugin['requires_plugins'])): ?>
                    <p class="plugin-dependencies">Requires: <?= htmlspecialchars(implode(', ', $plugin['requires_plugins'])) ?></p>
                    <?php endif; ?>
                    <?php if ($bootError): ?>
                    <div class="plugin-error" style="background: color-mix(in srgb, var(--color-danger) 10%, transparent); border: 1px solid var(--color-danger); border-radius: var(--radius); padding: 0.5rem 0.75rem; margin-bottom: 0.5rem; font-size: 0.85rem; color: var(--color-danger);">
                        <strong>Boot Error:</strong> <?= htmlspecialchars($bootError) ?>
                    </div>
                    <?php endif; ?>
                    <div class="plugin-actions">
                        <?php if (!$isActive): ?>
                        <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="enable">
                            <input type="hidden" name="plugin_id" value="<?= htmlspecialchars($id) ?>">
                            <button type="submit" class="btn btn-primary btn-sm">Enable</button>
                        </form>
                        <?php else: ?>
                        <?php
                            $pluginFeatures = $pluginManager->getPluginFeatures($id);
                            $disableConfirm = !empty($pluginFeatures)
                                ? 'Disable ' . htmlspecialchars($plugin['name']) . '? This will remove: ' . htmlspecialchars(implode(', ', array_slice($pluginFeatures, 0, 5))) . (count($pluginFeatures) > 5 ? ' and ' . (count($pluginFeatures) - 5) . ' more' : '')
                                : 'Disable ' . htmlspecialchars($plugin['name']) . '?';
                        ?>
                        <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="disable">
                            <input type="hidden" name="plugin_id" value="<?= htmlspecialchars($id) ?>">
                            <button type="submit" class="btn btn-secondary btn-sm" data-confirm="<?= $disableConfirm ?>">Disable</button>
                        </form>
                        <?php if (!empty($plugin['settings']) && is_array($plugin['settings'])): ?>
                        <a href="?tab=<?= urlencode($activeTab) ?>&settings=<?= urlencode($id) ?>" class="btn btn-secondary btn-sm">Settings</a>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($hasUpdate && !empty($updates[$id]['_source'])): ?>
                        <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update-plugin">
                            <input type="hidden" name="plugin_id" value="<?= htmlspecialchars($id) ?>">
                            <input type="hidden" name="plugin_source" value="<?= htmlspecialchars(json_encode($updates[$id]['_source'])) ?>">
                            <button type="submit" class="btn btn-warning btn-sm">Update to v<?= htmlspecialchars($updates[$id]['available_version']) ?></button>
                        </form>
                        <?php endif; ?>
                        <?php if ($hasMigrations): ?>
                        <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="run-migrations">
                            <input type="hidden" name="plugin_id" value="<?= htmlspecialchars($id) ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">Run Migrations</button>
                        </form>
                        <?php endif; ?>
                        <?php $pluginSource = $availablePlugins[$id]['_source'] ?? null; ?>
                        <?php if ($pluginSource): ?>
                        <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reinstall">
                            <input type="hidden" name="plugin_id" value="<?= htmlspecialchars($id) ?>">
                            <input type="hidden" name="plugin_source" value="<?= htmlspecialchars(json_encode($pluginSource)) ?>">
                            <button type="submit" class="btn btn-secondary btn-sm" title="Re-download and replace all plugin files from the repository" data-confirm="Force update <?= htmlspecialchars($plugin['name']) ?>? This will re-download and replace all plugin files from the repository.">Force Update</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="uninstall">
                            <input type="hidden" name="plugin_id" value="<?= htmlspecialchars($id) ?>">
                            <button type="submit" class="btn btn-danger btn-sm" data-confirm="Are you sure you want to uninstall this plugin? All plugin files will be removed.">Uninstall</button>
                        </form>
                    </div>
                    <?php
                        $changelog = $pluginManager->getChangelog($id);
                        if ($changelog && isset($_GET['changelog']) && $_GET['changelog'] === $id):
                    ?>
                    <div class="plugin-changelog" style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--color-border);">
                        <h4 style="margin-bottom: 0.5rem;">Changelog</h4>
                        <pre style="font-size: 0.8rem; max-height: 200px; overflow-y: auto; background: var(--color-bg); padding: 0.75rem; border-radius: var(--radius); white-space: pre-wrap;"><?= htmlspecialchars($changelog) ?></pre>
                        <a href="?tab=<?= urlencode($activeTab) ?>" class="btn btn-secondary btn-sm" style="margin-top: 0.5rem;">Close</a>
                    </div>
                    <?php elseif ($changelog): ?>
                    <a href="?tab=<?= urlencode($activeTab) ?>&changelog=<?= urlencode($id) ?>" style="font-size: 0.8rem; color: var(--color-text-muted); margin-top: 0.5rem; display: inline-block;">View changelog</a>
                    <?php endif; ?>
                    <?php if (isset($_GET['settings']) && $_GET['settings'] === $id && $isActive && !empty($plugin['settings'])): ?>
                    <div class="plugin-settings-inline" style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--color-border);">
                        <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save-settings">
                            <input type="hidden" name="plugin_id" value="<?= htmlspecialchars($id) ?>">
                            <?= $pluginManager->renderPluginSettingsForm($id) ?>
                            <div class="form-actions" style="margin-top: 1rem; border-top: none; padding-top: 0;">
                                <button type="submit" class="btn btn-primary btn-sm">Save Settings</button>
                                <a href="?tab=<?= urlencode($activeTab) ?>" class="btn btn-secondary btn-sm">Cancel</a>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php elseif ($activeTab === 'browse'): ?>
            <!-- Browse Available Plugins -->
            <div class="browse-header">
                <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="refresh-repos">
                    <button type="submit" class="btn btn-secondary">Refresh Repositories</button>
                </form>
            </div>

            <?php if (empty($availablePlugins)): ?>
            <div class="empty-state">
                <p>No plugins are available from configured repositories.</p>
                <p>Add a repository in the <a href="?tab=repositories">Repositories</a> tab, then refresh to see available plugins.</p>
            </div>
            <?php else: ?>
            <div class="plugin-grid">
                <?php foreach ($availablePlugins as $id => $plugin):
                    $isInstalled = !empty($plugin['_installed']);
                    $hasUpdate = isset($updates[$id]);
                ?>
                <div class="plugin-browse-card">
                    <div class="plugin-browse-header">
                        <span class="plugin-name"><?= htmlspecialchars($plugin['name'] ?? $id) ?></span>
                        <span class="plugin-version">v<?= htmlspecialchars($plugin['version'] ?? '0.0.0') ?></span>
                    </div>
                    <?php if (!empty($plugin['description'])): ?>
                    <p class="plugin-description"><?= htmlspecialchars($plugin['description']) ?></p>
                    <?php endif; ?>
                    <div class="plugin-browse-meta">
                        <?php if (!empty($plugin['author'])): ?>
                        <span class="plugin-meta-item">by <?= htmlspecialchars($plugin['author']) ?></span>
                        <?php endif; ?>
                        <?php if ($isInstalled): ?>
                        <span class="plugin-meta-item">Installed: v<?= htmlspecialchars($plugin['_installed_version'] ?? '?') ?></span>
                        <?php endif; ?>
                        <span class="plugin-meta-item">Source: <?= htmlspecialchars($plugin['_repo'] ?? 'Unknown') ?></span>
                    </div>
                    <div class="plugin-browse-actions">
                        <?php if ($isInstalled && !($plugin['_update_available'] ?? false)): ?>
                        <span class="plugin-status-badge badge-installed">Up to Date</span>
                        <?php elseif ($isInstalled && ($plugin['_update_available'] ?? false) && !empty($plugin['_source'])): ?>
                        <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="install-repo">
                            <input type="hidden" name="plugin_id" value="<?= htmlspecialchars($id) ?>">
                            <input type="hidden" name="plugin_source" value="<?= htmlspecialchars(json_encode($plugin['_source'])) ?>">
                            <button type="submit" class="btn btn-warning btn-sm">Update to v<?= htmlspecialchars($plugin['version'] ?? '') ?></button>
                        </form>
                        <?php elseif (!$isInstalled && !empty($plugin['_source'])): ?>
                        <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="install-repo">
                            <input type="hidden" name="plugin_id" value="<?= htmlspecialchars($id) ?>">
                            <input type="hidden" name="plugin_source" value="<?= htmlspecialchars(json_encode($plugin['_source'])) ?>">
                            <button type="submit" class="btn btn-primary btn-sm">Install</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php elseif ($activeTab === 'repositories'): ?>
            <!-- Repository Management -->
            <h3>Configured Repositories</h3>
            <?php if (empty($repositories)): ?>
            <div class="empty-state">
                <p>No plugin repositories are configured.</p>
                <p>Add a repository below to browse and install plugins from external sources.</p>
            </div>
            <?php else: ?>
            <table class="data-table" aria-label="Plugin repositories">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">URL</th>
                        <th scope="col">Official</th>
                        <th scope="col">Last Fetched</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($repositories as $repo): ?>
                    <tr>
                        <td><?= htmlspecialchars($repo['name'] ?? '') ?></td>
                        <td><code class="repo-url"><?= htmlspecialchars($repo['url'] ?? '') ?></code></td>
                        <td><?= !empty($repo['is_official']) ? 'Yes' : 'No' ?></td>
                        <td><?= !empty($repo['last_fetched']) ? htmlspecialchars($repo['last_fetched']) : 'Never' ?></td>
                        <td>
                            <?php if (empty($repo['is_official'])): ?>
                            <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="remove-repo">
                                <input type="hidden" name="repo_id" value="<?= (int)$repo['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" data-confirm="Remove this repository?">Remove</button>
                            </form>
                            <?php else: ?>
                            <span class="text-muted">--</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <!-- Add Repository -->
            <div class="add-repo-section">
                <h3>Add Repository</h3>
                <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>" class="add-repo-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add-repo">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="repo-name">Name</label>
                            <input type="text" id="repo-name" name="repo_name" class="form-input" placeholder="My Plugin Repo" required>
                        </div>
                        <div class="form-group">
                            <label for="repo-url">URL</label>
                            <input type="url" id="repo-url" name="repo_url" class="form-input" placeholder="https://example.com/plugins/registry.json" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="repo-token">Access Token <span class="text-muted">(optional, for private repositories)</span></label>
                        <input type="password" id="repo-token" name="repo_token" class="form-input" autocomplete="off"
                               placeholder="GitHub PAT, GitLab/Gitea token...">
                        <p class="form-help">Sent as an Authorization: Bearer header when fetching this registry and downloading its plugins. Encrypted at rest when encryption is configured. To change a token, remove and re-add the repository.</p>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Add Repository</button>
                    </div>
                </form>
            </div>

            <div class="add-repo-section">
                <h3>Private Network Hosts</h3>
                <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="repos-private-hosts">
                    <div class="form-group">
                        <label class="toggle-label">
                            <input type="checkbox" name="allow_private_hosts" value="1" <?= getSetting('plugin_repos_allow_private_hosts', '0') === '1' ? 'checked' : '' ?>>
                            <span>Allow repositories on private/LAN hosts (self-hosted Gitea, GitLab, etc.)</span>
                        </label>
                        <p class="form-help">By default, repository URLs must resolve to public addresses (SSRF protection). Enable this only if your plugin repositories live on your local network.</p>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-secondary">Save</button>
                    </div>
                </form>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
