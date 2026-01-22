<?php
/**
 * Route Debugging Admin Panel
 *
 * View all registered routes, test URL matching, and manage route cache.
 */

require_once __DIR__ . '/../includes/config.php';
requirePermission(PERM_ADMIN);

$pageTitle = 'Routes';
$activePage = 'admin';

$db = getDB();
$router = Router::getInstance();
$routes = $router->getRoutes();
$namedRoutes = $router->getNamedRoutes();

// Handle actions
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'clear_cache':
            if (Router::clearCache()) {
                $message = 'Route cache cleared successfully.';
            } else {
                $message = 'Failed to clear route cache.';
                $messageType = 'error';
            }
            break;

        case 'rebuild_cache':
            Router::clearCache();
            if ($router->saveToCache()) {
                $message = 'Route cache rebuilt successfully.';
            } else {
                $message = 'Failed to rebuild route cache.';
                $messageType = 'error';
            }
            break;

        case 'test_url':
            // Handled via JavaScript/AJAX below
            break;
    }
}

// Get cache stats
$cacheStats = Router::getCacheStats();

// Group routes by prefix
$groupedRoutes = [];
foreach ($routes as $route) {
    $pattern = $route['pattern'];
    if (preg_match('#^/([^/]+)#', $pattern, $matches)) {
        $prefix = $matches[1];
    } else {
        $prefix = 'root';
    }
    $groupedRoutes[$prefix][] = $route;
}
ksort($groupedRoutes);

// Get request profiling data if available
$profilingEnabled = getSetting('route_profiling', '0') === '1';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/admin-sidebar.php';
?>

<div class="admin-content">
    <div class="admin-header">
        <h1>Route Debugging</h1>
        <p class="admin-subtitle">View registered routes, test URL matching, and manage route cache</p>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Cache Status -->
    <div class="admin-card">
        <h2>Route Cache</h2>
        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
            <div class="stat-item">
                <div class="stat-value"><?= $cacheStats['exists'] ? 'Yes' : 'No' ?></div>
                <div class="stat-label">Cache Exists</div>
            </div>
            <div class="stat-item">
                <div class="stat-value" style="color: <?= $cacheStats['valid'] ? 'var(--success)' : 'var(--warning)' ?>">
                    <?= $cacheStats['valid'] ? 'Valid' : 'Stale' ?>
                </div>
                <div class="stat-label">Cache Status</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= $cacheStats['routes'] ?></div>
                <div class="stat-label">Cached Routes</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= $cacheStats['size'] ? round($cacheStats['size'] / 1024, 1) . ' KB' : '-' ?></div>
                <div class="stat-label">Cache Size</div>
            </div>
            <?php if ($cacheStats['age'] !== null): ?>
            <div class="stat-item">
                <div class="stat-value"><?= gmdate('H:i:s', $cacheStats['age']) ?></div>
                <div class="stat-label">Cache Age</div>
            </div>
            <?php endif; ?>
        </div>

        <div class="button-group">
            <form method="post" style="display: inline;">
                <input type="hidden" name="action" value="clear_cache">
                <button type="submit" class="btn btn-secondary">Clear Cache</button>
            </form>
            <form method="post" style="display: inline;">
                <input type="hidden" name="action" value="rebuild_cache">
                <button type="submit" class="btn btn-primary">Rebuild Cache</button>
            </form>
        </div>

        <div style="margin-top: 1rem;">
            <label style="display: flex; align-items: center; gap: 0.5rem;">
                <input type="checkbox" id="enable-caching" <?= getSetting('route_caching', '0') === '1' ? 'checked' : '' ?>>
                Enable route caching (recommended for production)
            </label>
        </div>
    </div>

    <!-- URL Tester -->
    <div class="admin-card">
        <h2>URL Tester</h2>
        <p style="color: var(--text-secondary); margin-bottom: 1rem;">
            Test which route matches a given URL path.
        </p>

        <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem;">
            <select id="test-method" style="padding: 0.5rem; border-radius: 4px; border: 1px solid var(--border); background: var(--bg-secondary);">
                <option value="GET">GET</option>
                <option value="POST">POST</option>
                <option value="PUT">PUT</option>
                <option value="DELETE">DELETE</option>
            </select>
            <input type="text" id="test-url" placeholder="/model/123" style="flex: 1; padding: 0.5rem; border-radius: 4px; border: 1px solid var(--border); background: var(--bg-secondary);">
            <button type="button" id="test-btn" class="btn btn-primary">Test</button>
        </div>

        <div id="test-result" style="display: none; padding: 1rem; border-radius: 4px; background: var(--bg-secondary);">
            <!-- Results shown here -->
        </div>
    </div>

    <!-- Route Statistics -->
    <div class="admin-card">
        <h2>Route Statistics</h2>
        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 1rem;">
            <div class="stat-item">
                <div class="stat-value"><?= count($routes) ?></div>
                <div class="stat-label">Total Routes</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= count($namedRoutes) ?></div>
                <div class="stat-label">Named Routes</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= count(array_filter($routes, fn($r) => $r['method'] === 'GET')) ?></div>
                <div class="stat-label">GET Routes</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= count(array_filter($routes, fn($r) => $r['method'] === 'POST')) ?></div>
                <div class="stat-label">POST Routes</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= count($groupedRoutes) ?></div>
                <div class="stat-label">Route Groups</div>
            </div>
        </div>
    </div>

    <!-- Route List -->
    <div class="admin-card">
        <h2>Registered Routes</h2>

        <div style="margin-bottom: 1rem;">
            <input type="text" id="route-filter" placeholder="Filter routes..." style="width: 100%; max-width: 300px; padding: 0.5rem; border-radius: 4px; border: 1px solid var(--border); background: var(--bg-secondary);">
        </div>

        <?php foreach ($groupedRoutes as $prefix => $prefixRoutes): ?>
        <details class="route-group" <?= $prefix === 'root' || $prefix === 'model' || $prefix === 'admin' ? 'open' : '' ?>>
            <summary style="cursor: pointer; padding: 0.75rem; background: var(--bg-secondary); border-radius: 4px; margin-bottom: 0.5rem; font-weight: 500;">
                <?= htmlspecialchars($prefix === 'root' ? '/ (root)' : '/' . $prefix) ?>
                <span style="color: var(--text-secondary); font-weight: normal;">(<?= count($prefixRoutes) ?> routes)</span>
            </summary>

            <div class="table-responsive" style="margin-bottom: 1rem;">
                <table class="admin-table route-table">
                    <thead>
                        <tr>
                            <th style="width: 80px;">Method</th>
                            <th>Pattern</th>
                            <th>Name</th>
                            <th>Handler</th>
                            <th>Middleware</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prefixRoutes as $route): ?>
                        <tr class="route-row" data-pattern="<?= htmlspecialchars($route['pattern']) ?>" data-name="<?= htmlspecialchars($route['name'] ?? '') ?>">
                            <td>
                                <span class="method-badge method-<?= strtolower($route['method']) ?>">
                                    <?= htmlspecialchars($route['method']) ?>
                                </span>
                            </td>
                            <td>
                                <code style="font-size: 0.875rem;"><?= htmlspecialchars($route['pattern']) ?></code>
                            </td>
                            <td>
                                <?php if ($route['name']): ?>
                                <code style="font-size: 0.8rem; color: var(--primary);"><?= htmlspecialchars($route['name']) ?></code>
                                <?php else: ?>
                                <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $handler = $route['handler'];
                                if (is_array($handler) && isset($handler['file'])):
                                ?>
                                <code style="font-size: 0.8rem;"><?= htmlspecialchars($handler['file']) ?></code>
                                <?php elseif (is_callable($handler)): ?>
                                <span style="color: var(--text-muted);">Closure</span>
                                <?php else: ?>
                                <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($route['middleware'])): ?>
                                <?php foreach ($route['middleware'] as $mw): ?>
                                <span class="middleware-badge"><?= htmlspecialchars($mw) ?></span>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endforeach; ?>
    </div>

    <!-- Named Routes Reference -->
    <div class="admin-card">
        <h2>Named Routes Reference</h2>
        <p style="color: var(--text-secondary); margin-bottom: 1rem;">
            Use these names with the <code>route()</code> helper function.
        </p>

        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Pattern</th>
                        <th>Example Usage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sortedNamed = $namedRoutes;
                    ksort($sortedNamed);
                    foreach ($sortedNamed as $name => $pattern):
                        // Generate example params
                        $exampleParams = [];
                        if (preg_match_all('/\{([^:}]+)/', $pattern, $matches)) {
                            foreach ($matches[1] as $param) {
                                $exampleParams[$param] = $param === 'id' ? '123' : "'value'";
                            }
                        }
                        $paramsStr = empty($exampleParams) ? '' : ', ' . json_encode($exampleParams);
                    ?>
                    <tr>
                        <td><code style="color: var(--primary);"><?= htmlspecialchars($name) ?></code></td>
                        <td><code><?= htmlspecialchars($pattern) ?></code></td>
                        <td><code style="font-size: 0.8rem;">route('<?= htmlspecialchars($name) ?>'<?= $paramsStr ?>)</code></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.method-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}
.method-get { background: #10b981; color: white; }
.method-post { background: #3b82f6; color: white; }
.method-put { background: #f59e0b; color: white; }
.method-delete { background: #ef4444; color: white; }
.method-patch { background: #8b5cf6; color: white; }

.middleware-badge {
    display: inline-block;
    padding: 0.125rem 0.375rem;
    border-radius: 3px;
    font-size: 0.7rem;
    background: var(--bg-tertiary);
    color: var(--text-secondary);
    margin-right: 0.25rem;
}

.route-group {
    margin-bottom: 0.5rem;
}

.route-row.hidden {
    display: none;
}

#test-result.success {
    border-left: 4px solid var(--success);
}
#test-result.error {
    border-left: 4px solid var(--danger);
}
</style>

<script>
// URL Tester
document.getElementById('test-btn').addEventListener('click', function() {
    const method = document.getElementById('test-method').value;
    const url = document.getElementById('test-url').value.trim();
    const resultDiv = document.getElementById('test-result');

    if (!url) {
        resultDiv.innerHTML = '<p style="color: var(--warning);">Please enter a URL to test.</p>';
        resultDiv.style.display = 'block';
        resultDiv.className = 'error';
        return;
    }

    // Find matching route
    const routes = <?= json_encode($routes) ?>;
    let matched = null;
    let matchedParams = {};

    for (const route of routes) {
        if (route.method !== method) continue;

        // Convert pattern to regex (simplified version)
        let regexPattern = route.pattern
            .replace(/\{([^:}]+):([^}]+)\}/g, '(?<$1>$2)')
            .replace(/\{([^}]+)\}/g, '(?<$1>[^/]+)');
        regexPattern = '^' + regexPattern + '/?$';

        const regex = new RegExp(regexPattern);
        const match = url.match(regex);

        if (match) {
            matched = route;
            matchedParams = match.groups || {};
            break;
        }
    }

    if (matched) {
        let html = '<p style="color: var(--success); font-weight: 500;">&#10003; Route matched!</p>';
        html += '<table style="width: 100%; margin-top: 0.5rem;">';
        html += '<tr><td style="width: 100px; color: var(--text-secondary);">Pattern:</td><td><code>' + matched.pattern + '</code></td></tr>';
        html += '<tr><td style="color: var(--text-secondary);">Name:</td><td><code>' + (matched.name || '-') + '</code></td></tr>';

        if (matched.handler && matched.handler.file) {
            html += '<tr><td style="color: var(--text-secondary);">Handler:</td><td><code>' + matched.handler.file + '</code></td></tr>';
        }

        if (Object.keys(matchedParams).length > 0) {
            html += '<tr><td style="color: var(--text-secondary);">Params:</td><td><code>' + JSON.stringify(matchedParams) + '</code></td></tr>';
        }

        if (matched.middleware && matched.middleware.length > 0) {
            html += '<tr><td style="color: var(--text-secondary);">Middleware:</td><td>' + matched.middleware.join(', ') + '</td></tr>';
        }

        html += '</table>';

        resultDiv.innerHTML = html;
        resultDiv.className = 'success';
    } else {
        resultDiv.innerHTML = '<p style="color: var(--danger);">&#10007; No matching route found for <code>' + method + ' ' + url + '</code></p>';
        resultDiv.className = 'error';
    }

    resultDiv.style.display = 'block';
});

// Route filter
document.getElementById('route-filter').addEventListener('input', function() {
    const filter = this.value.toLowerCase();
    document.querySelectorAll('.route-row').forEach(row => {
        const pattern = row.dataset.pattern.toLowerCase();
        const name = row.dataset.name.toLowerCase();
        const matches = pattern.includes(filter) || name.includes(filter);
        row.classList.toggle('hidden', !matches);
    });
});

// Enable caching toggle
document.getElementById('enable-caching').addEventListener('change', function() {
    fetch('<?= route('admin.settings') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'setting_route_caching=' + (this.checked ? '1' : '0')
    });
});

// Allow Enter key in URL tester
document.getElementById('test-url').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        document.getElementById('test-btn').click();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
