<?php
require_once __DIR__ . '/../../includes/config.php';

// Require admin permission
if (!isLoggedIn() || !isAdmin()) {
    $_SESSION['error'] = 'You do not have permission to manage plugins.';
    header('Location: ' . route('home'));
    exit;
}

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    $error = 'Invalid request. Please refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'enable':
            $pluginId = preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['plugin_id'] ?? ''));
            if ($pluginId !== '' && $pluginManager->enablePlugin($pluginId)) {
                $message = 'Plugin enabled successfully.';
                logInfo('Plugin enabled', ['plugin' => $pluginId, 'by' => getCurrentUser()['username']]);
            } else {
                $error = 'Failed to enable plugin. Check that all dependencies are met and the minimum version requirement is satisfied.';
            }
            break;

        case 'disable':
            $pluginId = preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['plugin_id'] ?? ''));
            if ($pluginId !== '' && $pluginManager->disablePlugin($pluginId)) {
                $message = 'Plugin disabled successfully.';
                logInfo('Plugin disabled', ['plugin' => $pluginId, 'by' => getCurrentUser()['username']]);
            } else {
                $error = 'Failed to disable plugin. Another active plugin may depend on it.';
            }
            break;

        case 'uninstall':
            $pluginId = preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['plugin_id'] ?? ''));
            if ($pluginId !== '' && $pluginManager->uninstallPlugin($pluginId)) {
                $message = 'Plugin uninstalled successfully.';
                logInfo('Plugin uninstalled', ['plugin' => $pluginId, 'by' => getCurrentUser()['username']]);
            } else {
                $error = 'Failed to uninstall plugin.';
            }
            break;

        case 'install-upload':
            if (isset($_FILES['plugin_zip']) && $_FILES['plugin_zip']['error'] === UPLOAD_ERR_OK) {
                $tmpPath = $_FILES['plugin_zip']['tmp_name'];
                $result = $pluginManager->installPlugin($tmpPath);
                if ($result['success']) {
                    $pluginName = htmlspecialchars($result['plugin']['name'] ?? 'Unknown');
                    $message = "Plugin \"$pluginName\" installed successfully.";
                    logInfo('Plugin installed from upload', ['plugin' => $result['plugin']['id'] ?? 'unknown', 'by' => getCurrentUser()['username']]);
                } else {
                    $error = 'Installation failed: ' . ($result['error'] ?? 'Unknown error');
                }
            } else {
                $uploadError = $_FILES['plugin_zip']['error'] ?? UPLOAD_ERR_NO_FILE;
                $error = match ($uploadError) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the maximum file size.',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                    UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
                    default => 'File upload failed. Please try again.',
                };
            }
            break;

        case 'install-repo':
            $pluginId = preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['plugin_id'] ?? ''));
            $source = json_decode($_POST['plugin_source'] ?? '{}', true);
            if ($pluginId !== '' && is_array($source) && !empty($source['repo'])) {
                $result = $pluginManager->installFromRepo($pluginId, $source);
                if ($result['success']) {
                    $message = 'Plugin installed from repository successfully.';
                    logInfo('Plugin installed from repo', ['plugin' => $pluginId, 'by' => getCurrentUser()['username']]);
                } else {
                    $error = 'Installation failed: ' . ($result['error'] ?? 'Unknown error');
                }
            } else {
                $error = 'Invalid plugin ID or source configuration.';
            }
            break;

        case 'run-migrations':
            $pluginId = preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['plugin_id'] ?? ''));
            if ($pluginId !== '') {
                $stats = $pluginManager->runPluginMigrations($pluginId);
                $message = "Migrations complete: {$stats['run']} applied, {$stats['skipped']} already up to date.";
                logInfo('Plugin migrations run', ['plugin' => $pluginId, 'stats' => $stats, 'by' => getCurrentUser()['username']]);
            } else {
                $error = 'Invalid plugin ID.';
            }
            break;

        case 'add-repo':
            $repoName = trim($_POST['repo_name'] ?? '');
            $repoUrl = trim($_POST['repo_url'] ?? '');
            if ($repoName === '' || $repoUrl === '') {
                $error = 'Repository name and URL are required.';
            } elseif ($pluginManager->addRepository($repoName, $repoUrl)) {
                $message = 'Repository added successfully.';
                logInfo('Plugin repository added', ['name' => $repoName, 'url' => $repoUrl, 'by' => getCurrentUser()['username']]);
            } else {
                $error = 'Failed to add repository. Please check the URL is valid.';
            }
            break;

        case 'remove-repo':
            $repoId = (int)($_POST['repo_id'] ?? 0);
            if ($repoId > 0 && $pluginManager->removeRepository($repoId)) {
                $message = 'Repository removed successfully.';
                logInfo('Plugin repository removed', ['repo_id' => $repoId, 'by' => getCurrentUser()['username']]);
            } else {
                $error = 'Failed to remove repository.';
            }
            break;

        case 'refresh-repos':
            $repos = $pluginManager->getRepositories();
            $refreshed = 0;
            foreach ($repos as $repo) {
                $result = $pluginManager->fetchRegistry($repo['url']);
                if ($result !== null) {
                    $refreshed++;
                }
            }
            $message = "Refreshed $refreshed of " . count($repos) . " repositories.";
            logInfo('Plugin repositories refreshed', ['refreshed' => $refreshed, 'total' => count($repos), 'by' => getCurrentUser()['username']]);
            break;

        case 'check-updates':
            // Handled below after redirect check since we need to display results
            break;
    }

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

if ($activeTab === 'browse') {
    $availablePlugins = $pluginManager->getAvailablePlugins();
    $updates = $pluginManager->checkUpdates();
} elseif ($activeTab === 'repositories') {
    $repositories = $pluginManager->getRepositories();
} elseif ($activeTab === 'installed') {
    $updates = $pluginManager->checkUpdates();
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
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
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
            <h3>Installed Plugins</h3>
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
                ?>
                <div class="plugin-card <?= $isActive ? 'active' : 'inactive' ?>">
                    <div class="plugin-card-header">
                        <div class="plugin-info">
                            <div class="plugin-title-row">
                                <span class="plugin-name"><?= htmlspecialchars($plugin['name']) ?></span>
                                <span class="plugin-version">v<?= htmlspecialchars($plugin['version']) ?></span>
                                <span class="plugin-status-badge <?= $isActive ? 'badge-active' : 'badge-inactive' ?>">
                                    <?= $isActive ? 'Active' : 'Inactive' ?>
                                </span>
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
                    <div class="plugin-actions">
                        <?php if (!$isActive): ?>
                        <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="enable">
                            <input type="hidden" name="plugin_id" value="<?= htmlspecialchars($id) ?>">
                            <button type="submit" class="btn btn-primary btn-sm">Enable</button>
                        </form>
                        <?php else: ?>
                        <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="disable">
                            <input type="hidden" name="plugin_id" value="<?= htmlspecialchars($id) ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">Disable</button>
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
                        <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="uninstall">
                            <input type="hidden" name="plugin_id" value="<?= htmlspecialchars($id) ?>">
                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to uninstall this plugin? All plugin files will be removed.')">Uninstall</button>
                        </form>
                    </div>
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
                        <?php if (!empty($plugin['category'])): ?>
                        <span class="plugin-meta-item"><?= htmlspecialchars($plugin['category']) ?></span>
                        <?php endif; ?>
                        <span class="plugin-meta-item">Source: <?= htmlspecialchars($plugin['_repo'] ?? 'Unknown') ?></span>
                    </div>
                    <div class="plugin-browse-actions">
                        <?php if ($isInstalled && !$hasUpdate): ?>
                        <span class="plugin-status-badge badge-installed">Installed</span>
                        <?php elseif ($hasUpdate): ?>
                        <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="install-repo">
                            <input type="hidden" name="plugin_id" value="<?= htmlspecialchars($id) ?>">
                            <input type="hidden" name="plugin_source" value="<?= htmlspecialchars(json_encode($plugin['_source'] ?? null)) ?>">
                            <button type="submit" class="btn btn-primary btn-sm">Update to v<?= htmlspecialchars($updates[$id]['available_version']) ?></button>
                        </form>
                        <?php else: ?>
                        <form method="post" action="<?= route('admin.plugins') . '?tab=' . urlencode($activeTab) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="install-repo">
                            <input type="hidden" name="plugin_id" value="<?= htmlspecialchars($id) ?>">
                            <input type="hidden" name="plugin_source" value="<?= htmlspecialchars(json_encode($plugin['_source'] ?? null)) ?>">
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
            <table class="data-table">
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
                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Remove this repository?')">Remove</button>
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
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Add Repository</button>
                    </div>
                </form>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<style>
/* Tab navigation */
.plugin-tabs {
    display: flex;
    gap: 0;
    border-bottom: 2px solid var(--color-border);
    margin-bottom: 1.5rem;
}

.plugin-tab {
    padding: 0.75rem 1.25rem;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-muted);
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: color 0.2s, border-color 0.2s;
}

.plugin-tab:hover {
    color: var(--color-text);
}

.plugin-tab.active {
    color: var(--color-primary);
    border-bottom-color: var(--color-primary);
    font-weight: 600;
}

/* Tab content */
.plugin-tab-content {
    min-height: 200px;
}

.plugin-tab-content h3 {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 1rem;
    color: var(--color-text);
}

/* Upload section */
.plugin-upload-section {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 8px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
}

.plugin-upload-section h3 {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 0.75rem;
    color: var(--color-text);
}

.upload-row {
    display: flex;
    gap: 0.75rem;
    align-items: center;
    flex-wrap: wrap;
}

.upload-input {
    max-width: 400px;
}

/* Plugin card list */
.plugin-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.plugin-card {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 8px;
    padding: 1rem 1.25rem;
    transition: border-color 0.2s ease;
}

.plugin-card.active {
    border-left: 3px solid var(--color-success);
}

.plugin-card.inactive {
    border-left: 3px solid var(--color-text-muted);
    opacity: 0.85;
}

.plugin-card:hover {
    border-color: var(--color-primary);
}

.plugin-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
}

.plugin-info {
    flex: 1;
    min-width: 0;
}

.plugin-title-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-bottom: 0.25rem;
}

.plugin-name {
    font-weight: 600;
    color: var(--color-text);
    font-size: 1rem;
}

.plugin-version {
    font-size: 0.8rem;
    color: var(--color-text-muted);
    background: var(--color-surface-hover);
    padding: 0.1rem 0.4rem;
    border-radius: 4px;
}

.plugin-author {
    font-size: 0.85rem;
    color: var(--color-text-muted);
}

.plugin-description {
    font-size: 0.85rem;
    color: var(--color-text-muted);
    margin: 0.5rem 0;
    line-height: 1.4;
}

.plugin-dependencies {
    font-size: 0.75rem;
    color: var(--color-warning);
    margin: 0.25rem 0 0.5rem;
    padding: 0.25rem 0.5rem;
    background: rgba(245, 158, 11, 0.1);
    border-radius: 4px;
    display: inline-block;
}

.plugin-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--color-border);
}

/* Status badges */
.plugin-status-badge {
    font-size: 0.7rem;
    padding: 0.15rem 0.5rem;
    border-radius: 10px;
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 0.02em;
}

.badge-active {
    background: var(--color-success);
    color: white;
}

.badge-inactive {
    background: var(--color-surface-hover);
    color: var(--color-text-muted);
}

.badge-update {
    background: var(--color-warning);
    color: white;
}

.badge-installed {
    background: var(--color-success);
    color: white;
}

/* Inline forms */
.inline-form {
    display: inline-block;
}

.btn-sm {
    padding: 0.35rem 0.75rem;
    font-size: 0.85rem;
}

/* Browse tab */
.browse-header {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 1.5rem;
}

.plugin-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1rem;
}

.plugin-browse-card {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 8px;
    padding: 1.25rem;
    display: flex;
    flex-direction: column;
    transition: border-color 0.2s ease;
}

.plugin-browse-card:hover {
    border-color: var(--color-primary);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.plugin-browse-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.plugin-browse-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: auto;
    padding-top: 0.75rem;
}

.plugin-meta-item {
    font-size: 0.75rem;
    color: var(--color-text-muted);
    background: var(--color-surface-hover);
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
}

.plugin-browse-actions {
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--color-border);
}

/* Repositories tab */
.repo-url {
    font-size: 0.8rem;
    word-break: break-all;
}

.text-muted {
    color: var(--color-text-muted);
}

.add-repo-section {
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--color-border);
}

.add-repo-section h3 {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 1rem;
    color: var(--color-text);
}

.add-repo-form .form-row {
    display: flex;
    gap: 1rem;
}

.add-repo-form .form-row .form-group {
    flex: 1;
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 2rem;
    color: var(--color-text-muted);
}

.empty-state a {
    color: var(--color-primary);
    text-decoration: underline;
}

/* Form help text */
.form-help {
    font-size: 0.8rem;
    color: var(--color-text-muted);
    margin-top: 0.35rem;
}

/* Data table (repos) */
.data-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 8px;
    overflow: hidden;
}

.data-table th,
.data-table td {
    padding: 0.75rem 1rem;
    text-align: left;
    border-bottom: 1px solid var(--color-border);
}

.data-table th {
    font-weight: 600;
    color: var(--color-text-muted);
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    background: var(--color-surface-hover);
}

.data-table tbody tr:hover {
    background: var(--color-surface-hover);
}

.data-table tbody tr:last-child td {
    border-bottom: none;
}

/* Responsive */
@media (max-width: 768px) {
    .plugin-tabs {
        overflow-x: auto;
    }

    .plugin-tab {
        white-space: nowrap;
        padding: 0.6rem 1rem;
    }

    .plugin-grid {
        grid-template-columns: 1fr;
    }

    .upload-row {
        flex-direction: column;
        align-items: stretch;
    }

    .upload-input {
        max-width: none;
    }

    .plugin-title-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.25rem;
    }

    .plugin-actions {
        flex-direction: column;
    }

    .plugin-actions .inline-form {
        display: block;
    }

    .plugin-actions .btn-sm {
        width: 100%;
    }

    .add-repo-form .form-row {
        flex-direction: column;
        gap: 0;
    }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
