<?php
/**
 * Route Debugging Admin Panel
 *
 * View all registered routes, test URL matching, and manage route cache.
 */

require_once __DIR__ . '/../../includes/config.php';

// Require admin permission (route debugging is admin-only)
if (!isLoggedIn() || !isAdmin()) {
    $_SESSION['error'] = 'You do not have permission to view route debugging.';
    header('Location: ' . route('home'));
    exit;
}

$pageTitle = 'Routes';
$activePage = 'admin';
$adminPage = 'routes';

$db = getDB();
$router = Router::getInstance();
$routes = $router->getRoutes();
$namedRoutes = $router->getNamedRoutes();

// Handle actions
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    $message = 'Security validation failed. Please try again.';
    $messageType = 'error';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

<div class="admin-content">
    <div class="admin-header">
        <h1>Route Debugging</h1>
        <p class="admin-subtitle">View registered routes, test URL matching, and manage route cache</p>
    </div>

    <?php if ($message): ?>
    <div role="<?= $messageType === 'success' ? 'status' : 'alert' ?>" class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Cache Status -->
    <div class="settings-section">
        <h2>Route Cache</h2>
        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 0.5rem; margin-bottom: 0.75rem;">
            <div class="stat-item">
                <div class="stat-value"><?= $cacheStats['exists'] ? 'Yes' : 'No' ?></div>
                <div class="stat-label">Cache Exists</div>
            </div>
            <div class="stat-item">
                <div class="stat-value" style="color: <?= $cacheStats['valid'] ? 'var(--color-success)' : 'var(--color-warning)' ?>">
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
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clear_cache">
                <button type="submit" class="btn btn-secondary">Clear Cache</button>
            </form>
            <form method="post" style="display: inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="rebuild_cache">
                <button type="submit" class="btn btn-primary">Rebuild Cache</button>
            </form>
        </div>

        <div style="margin-top: 0.5rem;">
            <label style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.8rem;">
                <input type="checkbox" id="enable-caching" <?= getSetting('route_caching', '0') === '1' ? 'checked' : '' ?>>
                Enable route caching (recommended for production)
            </label>
        </div>
    </div>

    <!-- URL Tester -->
    <div class="settings-section">
        <h2>URL Tester</h2>
        <p style="color: var(--color-text-muted); margin-bottom: 0.5rem;">
            Test which route matches a given URL path.
        </p>

        <div style="display: flex; gap: 0.4rem; margin-bottom: 0.75rem;">
            <select id="test-method" style="padding: 0.3rem; font-size: 0.8rem; border-radius: 4px; border: 1px solid var(--color-border); background: var(--color-surface);" aria-label="HTTP method">
                <option value="GET">GET</option>
                <option value="POST">POST</option>
                <option value="PUT">PUT</option>
                <option value="DELETE">DELETE</option>
            </select>
            <input type="text" id="test-url" placeholder="/model/123" style="flex: 1; padding: 0.3rem; font-size: 0.8rem; border-radius: 4px; border: 1px solid var(--color-border); background: var(--color-surface);">
            <button type="button" id="test-btn" class="btn btn-primary btn-small">Test</button>
        </div>

        <div id="test-result" style="display: none; padding: 0.5rem 0.75rem; border-radius: 4px; background: var(--color-surface); font-size: 0.8rem;">
            <!-- Results shown here -->
        </div>
    </div>

    <!-- Route Statistics -->
    <div class="settings-section">
        <h2>Route Statistics</h2>
        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(90px, 1fr)); gap: 0.5rem;">
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
    <div class="settings-section">
        <h2>Registered Routes</h2>

        <div style="margin-bottom: 0.5rem;">
            <input type="search" id="route-filter" placeholder="Filter routes..." aria-label="Filter routes" style="width: 100%; max-width: 250px; padding: 0.3rem 0.5rem; font-size: 0.8rem; border-radius: 4px; border: 1px solid var(--color-border); background: var(--color-surface);">
        </div>

        <?php foreach ($groupedRoutes as $prefix => $prefixRoutes): ?>
        <details class="route-group" <?= $prefix === 'root' || $prefix === 'model' || $prefix === 'admin' ? 'open' : '' ?>>
            <summary style="cursor: pointer; padding: 0.4rem 0.6rem; background: var(--color-surface); border-radius: 4px; margin-bottom: 0.25rem; font-weight: 500; font-size: 0.8rem;">
                <?= htmlspecialchars($prefix === 'root' ? '/ (root)' : '/' . $prefix) ?>
                <span style="color: var(--color-text-muted); font-weight: normal;">(<?= count($prefixRoutes) ?> routes)</span>
            </summary>

            <div class="table-responsive" style="margin-bottom: 1rem;">
                <table class="admin-table route-table" aria-label="Registered routes">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 50px;">Method</th>
                            <th scope="col">Pattern</th>
                            <th scope="col">Name</th>
                            <th scope="col">Handler</th>
                            <th scope="col">Middleware</th>
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
                                <code style="font-size: 0.75rem;"><?= htmlspecialchars($route['pattern']) ?></code>
                            </td>
                            <td>
                                <?php if ($route['name']): ?>
                                <code style="font-size: 0.7rem; color: var(--color-primary);"><?= htmlspecialchars($route['name']) ?></code>
                                <?php else: ?>
                                <span style="color: var(--color-text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $handler = $route['handler'];
                                if (is_array($handler) && isset($handler['file'])):
                                ?>
                                <code style="font-size: 0.7rem;"><?= htmlspecialchars($handler['file']) ?></code>
                                <?php elseif (is_callable($handler)): ?>
                                <span style="color: var(--color-text-muted);">Closure</span>
                                <?php else: ?>
                                <span style="color: var(--color-text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($route['middleware'])): ?>
                                <?php foreach ($route['middleware'] as $mw): ?>
                                <span class="middleware-badge"><?= htmlspecialchars($mw) ?></span>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <span style="color: var(--color-text-muted);">-</span>
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
    <div class="settings-section">
        <h2>Named Routes Reference</h2>
        <p style="color: var(--color-text-muted); margin-bottom: 0.5rem;">
            Use these names with the <code>route()</code> helper function.
        </p>

        <div class="table-responsive">
            <table class="admin-table" aria-label="Named routes">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Pattern</th>
                        <th scope="col">Example Usage</th>
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
                        <td><code style="font-size: 0.7rem; color: var(--color-primary);"><?= htmlspecialchars($name) ?></code></td>
                        <td><code style="font-size: 0.7rem;"><?= htmlspecialchars($pattern) ?></code></td>
                        <td><code style="font-size: 0.7rem;">route('<?= htmlspecialchars($name) ?>'<?= $paramsStr ?>)</code></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>

<style>
.admin-content .settings-section h2 {
    font-size: 0.95rem;
}
.admin-content .settings-section p {
    font-size: 0.8rem;
}
.admin-content .stat-item .stat-value {
    font-size: 1.1rem;
}
.admin-content .stat-item .stat-label {
    font-size: 0.7rem;
}
.route-table th,
.route-table td {
    padding: 0.3rem 0.4rem;
    font-size: 0.75rem;
    white-space: nowrap;
}
.route-table td:nth-child(2),
.route-table td:nth-child(4) {
    white-space: normal;
    word-break: break-all;
    max-width: 180px;
}
.method-badge {
    display: inline-block;
    padding: 0.15rem 0.35rem;
    border-radius: 3px;
    font-size: 0.65rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}
.method-get { background: var(--color-success); color: white; }
.method-post { background: var(--color-primary); color: white; }
.method-put { background: var(--color-warning); color: white; }
.method-delete { background: var(--color-danger); color: white; }
.method-patch { background: #8b5cf6; color: white; }

.middleware-badge {
    display: inline-block;
    padding: 0.1rem 0.25rem;
    border-radius: 3px;
    font-size: 0.6rem;
    background: var(--color-surface-hover);
    color: var(--color-text-muted);
    margin-right: 0.15rem;
}

.route-group {
    margin-bottom: 0.35rem;
}
.route-group .table-responsive {
    margin-bottom: 0.5rem;
}

.route-row.hidden {
    display: none;
}

#test-result.success {
    border-left: 4px solid var(--color-success, #10b981);
}
#test-result.error {
    border-left: 4px solid var(--color-danger, #ef4444);
}

.route-test-warning {
    color: var(--color-warning, #f59e0b);
}
.route-test-success {
    color: var(--color-success, #10b981);
    font-weight: 500;
}
.route-test-error {
    color: var(--color-danger, #ef4444);
}

/* Named routes table compact */
.admin-content .settings-section:last-child .admin-table th,
.admin-content .settings-section:last-child .admin-table td {
    padding: 0.25rem 0.4rem;
    font-size: 0.75rem;
}
</style>

<script>
// URL Tester
document.getElementById('test-btn').addEventListener('click', function() {
    const method = document.getElementById('test-method').value;
    const url = document.getElementById('test-url').value.trim();
    const resultDiv = document.getElementById('test-result');

    if (!url) {
        resultDiv.innerHTML = '<p class="route-test-warning">Please enter a URL to test.</p>';
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
        let html = '<p class="route-test-success">&#10003; Route matched!</p>';
        html += '<table style="width: 100%; margin-top: 0.5rem;">';
        html += '<tr><td style="width: 100px; color: var(--color-text-muted);">Pattern:</td><td><code>' + matched.pattern + '</code></td></tr>';
        html += '<tr><td style="color: var(--color-text-muted);">Name:</td><td><code>' + (matched.name || '-') + '</code></td></tr>';

        if (matched.handler && matched.handler.file) {
            html += '<tr><td style="color: var(--color-text-muted);">Handler:</td><td><code>' + matched.handler.file + '</code></td></tr>';
        }

        if (Object.keys(matchedParams).length > 0) {
            html += '<tr><td style="color: var(--color-text-muted);">Params:</td><td><code>' + JSON.stringify(matchedParams) + '</code></td></tr>';
        }

        if (matched.middleware && matched.middleware.length > 0) {
            html += '<tr><td style="color: var(--color-text-muted);">Middleware:</td><td>' + matched.middleware.join(', ') + '</td></tr>';
        }

        html += '</table>';

        resultDiv.innerHTML = html;
        resultDiv.className = 'success';
    } else {
        var errorP = document.createElement('p');
        errorP.className = 'route-test-error';
        var errorCode = document.createElement('code');
        errorCode.textContent = method + ' ' + url;
        errorP.innerHTML = '&#10007; No matching route found for ';
        errorP.appendChild(errorCode);
        resultDiv.innerHTML = '';
        resultDiv.appendChild(errorP);
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
    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    fetch('<?= route('admin.settings') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken ? csrfToken.content : '' },
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
