<?php
/**
 * Plugin Hooks Admin Panel
 *
 * Lists every filter/action listener currently registered by active plugins,
 * with the owning plugin and priority. The full catalog of hooks core fires
 * lives in docs/PLUGINS.md.
 */

require_once __DIR__ . '/../../includes/config.php';

if (!isLoggedIn() || !isAdmin()) {
    $_SESSION['error'] = 'You do not have permission to view hook debugging.';
    header('Location: ' . route('home'));
    exit;
}

$pageTitle = 'Hooks';
$activePage = 'admin';
$adminPage = 'hooks';

$pluginManager = PluginManager::getInstance();

/**
 * Human-readable description of a hook callback (never executes it).
 */
function describeHookCallback(mixed $callback): string
{
    if ($callback instanceof Closure) {
        return 'Closure';
    }
    if (is_string($callback)) {
        return $callback . '()';
    }
    if (is_array($callback) && count($callback) === 2) {
        $class = is_object($callback[0]) ? get_class($callback[0]) : (string)$callback[0];
        return $class . '::' . $callback[1] . '()';
    }
    return 'Callable';
}

// Flatten both registries into one sortable listener list
$listeners = [];
foreach ($pluginManager->getRegisteredFilters() as $hook => $entries) {
    foreach ($entries as $entry) {
        $listeners[] = [
            'hook' => $hook,
            'type' => 'filter',
            'plugin' => $entry['plugin'] !== '' ? $entry['plugin'] : '(core)',
            'priority' => $entry['priority'],
            'callback' => describeHookCallback($entry['callback']),
        ];
    }
}
foreach ($pluginManager->getRegisteredActions() as $event => $entries) {
    foreach ($entries as $entry) {
        $listeners[] = [
            'hook' => $event,
            'type' => 'action',
            'plugin' => $entry['plugin'] !== '' ? $entry['plugin'] : '(core)',
            'priority' => $entry['priority'],
            'callback' => describeHookCallback($entry['callback']),
        ];
    }
}
usort($listeners, fn($a, $b) => [$a['hook'], $a['type']] <=> [$b['hook'], $b['type']]);

$activePlugins = $pluginManager->getActivePlugins();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

<div class="admin-content">
    <div class="page-header">
        <h1>Hook Debugging</h1>
        <p>Filter and action listeners registered by active plugins</p>
    </div>

    <div class="settings-section">
        <h2>Summary</h2>
        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 0.5rem;">
            <div class="stat-item">
                <div class="stat-value"><?= count($listeners) ?></div>
                <div class="stat-label">Listeners</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= count(array_unique(array_column($listeners, 'hook'))) ?></div>
                <div class="stat-label">Hooks Listened To</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= count($activePlugins) ?></div>
                <div class="stat-label">Active Plugins</div>
            </div>
        </div>
        <p style="color: var(--color-text-muted); font-size: 0.8rem; margin-top: 0.5rem;">
            The catalog of hooks MeshSilo fires (with signatures) is documented in
            <code>docs/PLUGINS.md</code>. Higher priority runs first.
        </p>
    </div>

    <div class="settings-section">
        <h2>Registered Listeners</h2>
        <?php if (empty($listeners)): ?>
        <p style="color: var(--color-text-muted);">No plugin hook listeners are currently registered.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table" aria-label="Registered hook listeners">
                <thead>
                    <tr>
                        <th scope="col">Hook</th>
                        <th scope="col">Type</th>
                        <th scope="col">Plugin</th>
                        <th scope="col">Priority</th>
                        <th scope="col">Callback</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($listeners as $l): ?>
                    <tr>
                        <td><code style="font-size: 0.75rem;"><?= htmlspecialchars($l['hook']) ?></code></td>
                        <td><span class="hook-type hook-type-<?= $l['type'] ?>"><?= $l['type'] ?></span></td>
                        <td><code style="font-size: 0.75rem;"><?= htmlspecialchars($l['plugin']) ?></code></td>
                        <td><?= (int)$l['priority'] ?></td>
                        <td><code style="font-size: 0.7rem;"><?= htmlspecialchars($l['callback']) ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($activePlugins)): ?>
    <div class="settings-section">
        <h2>Features by Plugin</h2>
        <?php foreach ($activePlugins as $id => $plugin): ?>
        <details class="hook-plugin-group">
            <summary style="cursor: pointer; padding: 0.4rem 0.6rem; background: var(--color-surface); border-radius: 4px; margin-bottom: 0.25rem; font-weight: 500; font-size: 0.8rem;">
                <?= htmlspecialchars($plugin['name'] ?? $id) ?>
                <span style="color: var(--color-text-muted); font-weight: normal;">v<?= htmlspecialchars($plugin['version'] ?? '?') ?></span>
            </summary>
            <ul style="font-size: 0.8rem; margin: 0.25rem 0 0.75rem 1.25rem;">
                <?php foreach ($pluginManager->getPluginFeatures($id) as $feature): ?>
                <li><code style="font-size: 0.75rem;"><?= htmlspecialchars($feature) ?></code></li>
                <?php endforeach; ?>
            </ul>
        </details>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</div>

<style>
.hook-type {
    display: inline-block;
    padding: 0.15rem 0.35rem;
    border-radius: 3px;
    font-size: 0.65rem;
    font-weight: 600;
    text-transform: uppercase;
}
.hook-type-filter { background: var(--color-primary); color: white; }
.hook-type-action { background: var(--color-success); color: white; }
.admin-content .settings-section h2 { font-size: 0.95rem; }
.admin-content .data-table th,
.admin-content .data-table td { padding: 0.3rem 0.4rem; font-size: 0.75rem; }
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
