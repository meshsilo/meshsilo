<?php
/**
 * Standalone Database Update Page
 *
 * Allows authorized users to run database migrations without CLI access.
 * Unauthorized users are silently redirected (treated as 404).
 */

// Emergency rate_limits table fix - runs before config.php to avoid middleware errors
// Access via: /update?fix_rate_limits=1
if (isset($_GET['fix_rate_limits']) && $_GET['fix_rate_limits'] === '1') {
    // Minimal database setup without loading full config (avoids middleware)
    // Try multiple possible config file locations
    $possiblePaths = [
        __DIR__ . '/../../config.local.php',
        dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/config.local.php',
        realpath(__DIR__ . '/../..') . '/config.local.php',
        getcwd() . '/config.local.php',
    ];

    $configFile = null;
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $configFile = $path;
            break;
        }
    }

    if ($configFile) {
        $localConfig = require $configFile;

        try {
            // Create minimal PDO connection
            if (isset($localConfig['db_type']) && $localConfig['db_type'] === 'mysql') {
                $dsn = "mysql:host={$localConfig['db_host']};dbname={$localConfig['db_name']};charset=utf8mb4";
                $pdo = new PDO($dsn, $localConfig['db_user'], $localConfig['db_pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                // Fix rate_limits table for MySQL
                $pdo->exec('DROP TABLE IF EXISTS rate_limits');
                $pdo->exec('CREATE TABLE rate_limits (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    key_name VARCHAR(255) NOT NULL UNIQUE,
                    data TEXT,
                    expires_at INT NOT NULL,
                    INDEX idx_rate_limits_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

                echo "<h2>Rate limits table fixed successfully!</h2>";
                echo "<p>The rate_limits table has been recreated with the correct schema.</p>";
                echo "<p><a href='update'>Return to Update Page</a></p>";
                exit;
            } else {
                // SQLite
                $dbPath = $localConfig['db_path'] ?? __DIR__ . '/../../storage/db/silo.sqlite';
                $pdo = new PDO("sqlite:$dbPath", null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                // Fix rate_limits table for SQLite
                $pdo->exec('DROP TABLE IF EXISTS rate_limits');
                $pdo->exec('CREATE TABLE rate_limits (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    key_name TEXT NOT NULL UNIQUE,
                    data TEXT,
                    expires_at INTEGER NOT NULL
                )');
                $pdo->exec('CREATE INDEX idx_rate_limits_expires ON rate_limits(expires_at)');

                echo "<h2>Rate limits table fixed successfully!</h2>";
                echo "<p>The rate_limits table has been recreated with the correct schema.</p>";
                echo "<p><a href='update'>Return to Update Page</a></p>";
                exit;
            }
        } catch (PDOException $e) {
            echo "<h2>Error fixing rate_limits table</h2>";
            echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
            exit;
        }
    } else {
        echo "<h2>Configuration file not found</h2>";
        echo "<p>Could not locate config.local.php in any of the expected locations:</p>";
        echo "<ul>";
        foreach ($possiblePaths as $path) {
            echo "<li>" . htmlspecialchars($path) . "</li>";
        }
        echo "</ul>";
        echo "<p>Current directory: " . htmlspecialchars(getcwd()) . "</p>";
        echo "<p>Script directory: " . htmlspecialchars(__DIR__) . "</p>";
        exit;
    }
}

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
    } elseif (isset($_POST['repair_oauth_tables'])) {
        // Repair OAuth tables for MySQL compatibility
        try {
            $db->exec('DROP TABLE IF EXISTS oauth_refresh_tokens');
            $db->exec('DROP TABLE IF EXISTS oauth_access_tokens');
            $db->exec('DROP TABLE IF EXISTS oauth_authorization_codes');
            $db->exec('DROP TABLE IF EXISTS oauth_clients');

            // Force recreation by loading OAuth2Provider
            if (file_exists(__DIR__ . '/../../includes/OAuth2Provider.php')) {
                require_once __DIR__ . '/../../includes/OAuth2Provider.php';
                // Trigger table creation by calling a static method that initializes
                if (class_exists('OAuth2Provider') && method_exists('OAuth2Provider', 'getClients')) {
                    OAuth2Provider::getClients();
                }
            }

            $message = 'OAuth tables have been recreated with correct schema.';
        } catch (Exception $e) {
            $error = 'Failed to repair OAuth tables: ' . $e->getMessage();
        }
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
            color: var(--text-primary);
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
            color: var(--text-secondary);
        }

        .card {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--border-color);
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
            background: rgba(34, 197, 94, 0.15);
            color: var(--success);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning);
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
            background: var(--bg-tertiary);
            border-radius: 8px;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-value.success { color: var(--success); }
        .stat-value.warning { color: var(--warning); }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-muted);
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
            background: var(--bg-tertiary);
            border-radius: 6px;
            border-left: 3px solid var(--border-color);
        }

        .migration-item.applied {
            border-left-color: var(--success);
        }

        .migration-item.pending {
            border-left-color: var(--warning);
        }

        .migration-icon {
            font-size: 1rem;
            min-width: 1.5rem;
        }

        .migration-item.applied .migration-icon { color: var(--success); }
        .migration-item.pending .migration-icon { color: var(--warning); }

        .migration-name {
            font-weight: 500;
            font-size: 0.875rem;
        }

        .migration-desc {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid var(--success);
            color: var(--success);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--error);
            color: var(--error);
        }

        .alert-warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid var(--warning);
            color: var(--warning);
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
            border-bottom: 1px solid var(--border-color);
            font-size: 0.875rem;
        }

        .result-item:last-child {
            border-bottom: none;
        }

        .result-item.success { color: var(--success); }
        .result-item.error { color: var(--error); }

        .result-error {
            font-size: 0.75rem;
            color: var(--text-muted);
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
            background: var(--primary);
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
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .btn-secondary:hover {
            background: var(--border-color);
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
            color: var(--text-muted);
            font-size: 0.75rem;
        }

        .footer a {
            color: var(--primary);
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
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
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
                <div class="alert alert-warning">
                    <?= $pendingCount ?> migration(s) pending. Click the button below to update your database.
                </div>
            <?php else: ?>
                <div class="alert alert-success">
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
                        <span class="migration-icon"><?= $m['applied'] ? '✓' : '○' ?></span>
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
                <form method="post" onsubmit="return confirm('Run all pending migrations? This will modify your database schema.');">
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
                <p style="color: var(--text-secondary); font-size: 0.875rem; margin-bottom: 0.75rem;">
                    If you encounter "Unknown column 'expires_at'" or similar rate limiting errors, use this to fix the table schema.
                </p>
                <form method="post" style="display: inline;" onsubmit="return confirm('This will drop and recreate the rate_limits table. Continue?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button type="submit" name="repair_rate_limits" class="btn btn-secondary">
                        Repair Rate Limits Table
                    </button>
                </form>
            </div>

            <?php if ($dbType === 'mysql'): ?>
            <div style="border-top: 1px solid var(--border-color); padding-top: 1rem;">
                <h3 style="font-size: 0.875rem; margin-bottom: 0.5rem;">OAuth Tables (MySQL)</h3>
                <p style="color: var(--text-secondary); font-size: 0.875rem; margin-bottom: 0.75rem;">
                    If you encounter "BLOB/TEXT column used in key specification" errors, use this repair option
                    to recreate the OAuth tables with the correct MySQL-compatible schema.
                </p>
                <form method="post" style="display: inline;" onsubmit="return confirm('This will drop and recreate the OAuth tables. Any existing OAuth clients and tokens will be deleted. Continue?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button type="submit" name="repair_oauth_tables" class="btn btn-secondary">
                        Repair OAuth Tables
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <div class="footer">
            <p>Logged in as <?= htmlspecialchars(getCurrentUser()['username'] ?? 'Admin') ?></p>
            <p><a href="<?= route('admin.database') ?>">Advanced Database Management</a></p>
        </div>
    </div>
</body>
</html>
