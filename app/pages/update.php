<?php
/**
 * Standalone Database Update Page
 *
 * Allows authorized users to run database migrations without CLI access.
 * Unauthorized users are silently redirected (treated as 404).
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/migrations.php';

// Check permission - treat unauthorized as 404 (silent redirect)
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . route('home'));
    exit;
}

$db = getDB();
$dbType = $db->getType();

// Get migration list
$migrations = getMigrationList();

// Check migration status
$appliedCount = 0;
$pendingCount = 0;
$migrationStatus = [];

foreach ($migrations as $m) {
    $isApplied = $m['check']($db);
    $migrationStatus[] = [
        'name' => $m['name'],
        'description' => $m['description'] ?? '',
        'applied' => $isApplied
    ];
    if ($isApplied) {
        $appliedCount++;
    } else {
        $pendingCount++;
    }
}

// Handle migration action
$message = '';
$error = '';
$migrationsRun = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token if available
    if (function_exists('verifyCsrfToken') && !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } elseif (isset($_POST['repair_rate_limits'])) {
        // Repair rate_limits table schema
        try {
            $db->exec('DROP TABLE IF EXISTS rate_limits');

            if ($dbType === 'mysql') {
                $db->exec('CREATE TABLE rate_limits (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    key_name VARCHAR(255) NOT NULL UNIQUE,
                    data TEXT,
                    expires_at INT NOT NULL,
                    INDEX idx_rate_limits_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            } else {
                $db->exec('CREATE TABLE rate_limits (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    key_name TEXT NOT NULL UNIQUE,
                    data TEXT,
                    expires_at INTEGER NOT NULL
                )');
                $db->exec('CREATE INDEX idx_rate_limits_expires ON rate_limits(expires_at)');
            }

            $message = 'Rate limits table has been recreated with correct schema.';
        } catch (Exception $e) {
            $error = 'Failed to repair rate limits table: ' . $e->getMessage();
        }
    } elseif (isset($_POST['run_migrations'])) {
        $applied = 0;
        $errors = 0;

        foreach ($migrations as $migration) {
            if (!$migration['check']($db)) {
                try {
                    $migration['apply']($db);
                    $migrationsRun[] = ['name' => $migration['name'], 'success' => true];
                    $applied++;
                } catch (Exception $e) {
                    $migrationsRun[] = ['name' => $migration['name'], 'success' => false, 'error' => $e->getMessage()];
                    $errors++;
                    // Stop on first error to prevent cascading issues
                    break;
                }
            }
        }

        if ($errors === 0 && $applied > 0) {
            $message = "Successfully applied $applied migration(s).";
            setSetting('schema_version', date('Y-m-d H:i:s'));
            setSetting('last_migration', date('Y-m-d H:i:s'));
        } elseif ($applied === 0) {
            $message = "Database is already up to date.";
        } else {
            $error = "Completed with $errors error(s). $applied migration(s) applied successfully before the error.";
        }

        // Refresh migration status
        $appliedCount = 0;
        $pendingCount = 0;
        $migrationStatus = [];
        foreach ($migrations as $m) {
            $isApplied = $m['check']($db);
            $migrationStatus[] = [
                'name' => $m['name'],
                'description' => $m['description'] ?? '',
                'applied' => $isApplied
            ];
            if ($isApplied) {
                $appliedCount++;
            } else {
                $pendingCount++;
            }
        }
    }
}

// Get CSRF token if available
$csrfToken = function_exists('getCsrfToken') ? getCsrfToken() : '';

// Get site settings for theme
$siteName = getSetting('site_name', 'Silo');
$theme = $_COOKIE['silo_theme'] ?? getSetting('default_theme', 'dark');
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Database - <?= htmlspecialchars($siteName) ?></title>
    <style>
        :root {
            --bg-primary: #0f172a;
            --bg-secondary: #1e293b;
            --bg-tertiary: #334155;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --border-color: #475569;
            --success: #22c55e;
            --warning: #f59e0b;
            --error: #ef4444;
            --primary: #6366f1;
        }

        [data-theme="light"] {
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-tertiary: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
            --border-color: #cbd5e1;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: var(--bg-primary);
            color: var(--color-text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 2rem;
        }

        .container {
            max-width: 700px;
            width: 100%;
        }

        .header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .header h1 {
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }

        .header p {
            color: var(--color-text-muted);
        }

        .card {
            background: var(--color-surface);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--color-border);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .card-header h2 {
            font-size: 1.125rem;
            font-weight: 600;
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-success {
            background: color-mix(in srgb, var(--color-success) 15%, transparent);
            color: var(--color-success);
        }

        .badge-warning {
            background: color-mix(in srgb, var(--color-warning) 15%, transparent);
            color: var(--color-warning);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat {
            text-align: center;
            padding: 1rem;
            background: var(--color-surface-hover);
            border-radius: 8px;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--color-primary);
        }

        .stat-value.success { color: var(--color-success); }
        .stat-value.warning { color: var(--color-warning); }

        .stat-label {
            font-size: 0.75rem;
            color: var(--color-text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 0.25rem;
        }

        .migration-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            max-height: 300px;
            overflow-y: auto;
            padding-right: 0.5rem;
        }

        .migration-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.75rem;
            background: var(--color-surface-hover);
            border-radius: 6px;
            border-left: 3px solid var(--color-border);
        }

        .migration-item.applied {
            border-left-color: var(--color-success);
        }

        .migration-item.pending {
            border-left-color: var(--color-warning);
        }

        .migration-icon {
            font-size: 1rem;
            min-width: 1.5rem;
        }

        .migration-item.applied .migration-icon { color: var(--color-success); }
        .migration-item.pending .migration-icon { color: var(--color-warning); }

        .migration-name {
            font-weight: 500;
            font-size: 0.875rem;
        }

        .migration-desc {
            font-size: 0.75rem;
            color: var(--color-text-muted);
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background: color-mix(in srgb, var(--color-success) 10%, transparent);
            border: 1px solid var(--color-success);
            color: var(--color-success);
        }

        .alert-error {
            background: color-mix(in srgb, var(--color-danger) 10%, transparent);
            border: 1px solid var(--color-danger);
            color: var(--color-danger);
        }

        .alert-warning {
            background: color-mix(in srgb, var(--color-warning) 10%, transparent);
            border: 1px solid var(--color-warning);
            color: var(--color-warning);
        }

        .results {
            margin-bottom: 1.5rem;
        }

        .results h3 {
            font-size: 0.875rem;
            margin-bottom: 0.75rem;
        }

        .result-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--color-border);
            font-size: 0.875rem;
        }

        .result-item:last-child {
            border-bottom: none;
        }

        .result-item.success { color: var(--color-success); }
        .result-item.error { color: var(--color-danger); }

        .result-error {
            font-size: 0.75rem;
            color: var(--color-text-muted);
            margin-left: 1.5rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--color-primary);
            color: white;
        }

        .btn-primary:hover {
            filter: brightness(1.1);
        }

        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-secondary {
            background: var(--color-surface-hover);
            color: var(--color-text);
        }

        .btn-secondary:hover {
            background: var(--color-border);
        }

        .actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1.5rem;
        }

        .footer {
            margin-top: 2rem;
            text-align: center;
            color: var(--color-text-muted);
            font-size: 0.75rem;
        }

        .footer a {
            color: var(--color-primary);
            text-decoration: none;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        @media (max-width: 600px) {
            .stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Database Update</h1>
            <p>Run database migrations to update your Silo installation</p>
        </div>

        <?php if ($message): ?>
            <div role="status" class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div role="alert" class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($migrationsRun)): ?>
            <div class="card results">
                <h3>Migration Results</h3>
                <?php foreach ($migrationsRun as $result): ?>
                    <div class="result-item <?= $result['success'] ? 'success' : 'error' ?>">
                        <?= $result['success'] ? '✓' : '✗' ?>
                        <?= htmlspecialchars($result['name']) ?>
                    </div>
                    <?php if (!$result['success'] && isset($result['error'])): ?>
                        <div class="result-error"><?= htmlspecialchars($result['error']) ?></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="stats">
                <div class="stat">
                    <div class="stat-value"><?= strtoupper($dbType) ?></div>
                    <div class="stat-label">Database</div>
                </div>
                <div class="stat">
                    <div class="stat-value success"><?= $appliedCount ?></div>
                    <div class="stat-label">Applied</div>
                </div>
                <div class="stat">
                    <div class="stat-value <?= $pendingCount > 0 ? 'warning' : 'success' ?>"><?= $pendingCount ?></div>
                    <div class="stat-label">Pending</div>
                </div>
            </div>

            <?php if ($pendingCount > 0): ?>
                <div role="alert" class="alert alert-warning">
                    <?= $pendingCount ?> migration(s) pending. Click the button below to update your database.
                </div>
            <?php else: ?>
                <div role="status" class="alert alert-success">
                    Your database is up to date. No migrations needed.
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Migration Status</h2>
                <span class="badge <?= $pendingCount > 0 ? 'badge-warning' : 'badge-success' ?>">
                    <?= $pendingCount > 0 ? $pendingCount . ' pending' : 'Up to date' ?>
                </span>
            </div>

            <div class="migration-list">
                <?php foreach ($migrationStatus as $m): ?>
                    <div class="migration-item <?= $m['applied'] ? 'applied' : 'pending' ?>">
                        <span class="migration-icon" aria-hidden="true"><?= $m['applied'] ? '✓' : '○' ?></span>
                        <div>
                            <div class="migration-name"><?= htmlspecialchars($m['name']) ?></div>
                            <?php if ($m['description']): ?>
                                <div class="migration-desc"><?= htmlspecialchars($m['description']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="actions">
            <?php if ($pendingCount > 0): ?>
                <form method="post" data-confirm="Run all pending migrations? This will modify your database schema.">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button type="submit" name="run_migrations" class="btn btn-primary">
                        Run <?= $pendingCount ?> Migration(s)
                    </button>
                </form>
            <?php else: ?>
                <button type="button" class="btn btn-primary" disabled>
                    No Migrations Needed
                </button>
            <?php endif; ?>
            <a href="<?= route('home') ?>" class="btn btn-secondary">Return Home</a>
        </div>

        <div class="card" style="margin-top: 1.5rem;">
            <div class="card-header">
                <h2>Schema Repairs</h2>
                <span class="badge badge-warning">Troubleshooting</span>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <h3 style="font-size: 0.875rem; margin-bottom: 0.5rem;">Rate Limits Table</h3>
                <p style="color: var(--color-text-muted); font-size: 0.875rem; margin-bottom: 0.75rem;">
                    If you encounter "Unknown column 'expires_at'" or similar rate limiting errors, use this to fix the table schema.
                </p>
                <form method="post" style="display: inline;" data-confirm="This will drop and recreate the rate_limits table. Continue?">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button type="submit" name="repair_rate_limits" class="btn btn-secondary">
                        Repair Rate Limits Table
                    </button>
                </form>
            </div>

        </div>

        <div class="footer">
            <p>Logged in as <?= htmlspecialchars(getCurrentUser()['username'] ?? 'Admin') ?></p>
            <p><a href="<?= route('admin.database') ?>">Advanced Database Management</a></p>
        </div>
    </div>
</body>
</html>
