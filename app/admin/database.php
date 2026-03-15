<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/migrations.php';

// Require admin permission (database management is admin-only)
if (!isLoggedIn() || !isAdmin()) {
    $_SESSION['error'] = 'You do not have permission to manage the database.';
    header('Location: ' . route('home'));
    exit;
}

$pageTitle = 'Database Management';
$activePage = '';
$adminPage = 'database';

$db = getDB();
$dbType = $db->getType();

// Get migration list (same as CLI tool)
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

// Handle actions
$message = '';
$error = '';
$migrationsRun = [];

// CSRF protection for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    $error = 'Invalid request. Please refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['run_migrations'])) {
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
            $error = "Completed with $errors error(s). $applied migration(s) applied.";
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
    } elseif (isset($_POST['backup_db'])) {
        if ($dbType === 'sqlite') {
            $dbPath = DB_PATH;
            $backupPath = dirname($dbPath) . '/backup_' . date('Y-m-d_H-i-s') . '.db';
            if (copy($dbPath, $backupPath)) {
                $message = 'Database backed up to: ' . basename($backupPath);
            } else {
                $error = 'Failed to create backup.';
            }
        } else {
            $error = 'Backup from web UI is only supported for SQLite databases.';
        }
    } elseif (isset($_POST['optimize_db'])) {
        try {
            if ($dbType === 'sqlite') {
                $db->exec('VACUUM');
                $db->exec('ANALYZE');
                $message = 'Database optimized successfully.';
            } else {
                // For MySQL, optimize all tables
                $tables = $db->query("SHOW TABLES");
                while ($row = $tables->fetch(PDO::FETCH_NUM)) {
                    // SECURITY: Validate table name contains only safe characters (alphanumeric and underscores)
                    // to prevent SQL injection. Table names from SHOW TABLES should be safe, but we validate
                    // as defense-in-depth since the name is interpolated into the query.
                    $tableName = $row[0];
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
                        logWarning('Skipping table with invalid name during optimization', ['table' => $tableName]);
                        continue;
                    }
                    $db->exec("OPTIMIZE TABLE `{$tableName}`");
                }
                $message = 'Database tables optimized successfully.';
            }
        } catch (Exception $e) {
            $error = 'Optimization failed: ' . $e->getMessage();
        }
    }
}

// Get database info
$schemaVersion = getSetting('schema_version', 'Unknown');
$lastMigration = getSetting('last_migration', 'Never');

// Get table counts
$tableCounts = [];
$coreTables = ['users', 'models', 'categories', 'collections', 'tags', 'favorites', 'activity_log'];
foreach ($coreTables as $table) {
    if (tableExists($db, $table)) {
        try {
            $count = $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            $tableCounts[$table] = (int)$count;
        } catch (Exception $e) {
            $tableCounts[$table] = 'Error';
        }
    }
}

// Get database size (SQLite only)
$dbSize = null;
if ($dbType === 'sqlite' && defined('DB_PATH') && file_exists(DB_PATH)) {
    $dbSize = filesize(DB_PATH);
}

require_once __DIR__ . '/../../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

            <div class="admin-content">
                <div class="page-header">
                    <h1>Database Management</h1>
                    <p>Manage database migrations and maintenance</p>
                </div>

        <?php if ($message): ?>
            <div role="status" class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div role="alert" class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($migrationsRun)): ?>
            <div class="migration-results">
                <h3>Migration Results</h3>
                <ul>
                    <?php foreach ($migrationsRun as $result): ?>
                        <li class="<?= $result['success'] ? 'success' : 'error' ?>">
                            <?= $result['success'] ? '✓' : '✗' ?>
                            <?= htmlspecialchars($result['name']) ?>
                            <?php if (!$result['success'] && isset($result['error'])): ?>
                                <br><small><?= htmlspecialchars($result['error']) ?></small>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= strtoupper($dbType) ?></div>
                <div class="stat-label">Database Type</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $appliedCount ?></div>
                <div class="stat-label">Applied Migrations</div>
            </div>
            <div class="stat-card <?= $pendingCount > 0 ? 'stat-card-warning' : 'stat-card-success' ?>">
                <div class="stat-value"><?= $pendingCount ?></div>
                <div class="stat-label">Pending Migrations</div>
            </div>
            <?php if ($dbSize !== null): ?>
            <div class="stat-card">
                <div class="stat-value"><?= formatBytes($dbSize) ?></div>
                <div class="stat-label">Database Size</div>
            </div>
            <?php endif; ?>
        </div>

        <details class="settings-section" open>
            <summary><h2>Database Information</h2></summary>
            <table class="data-table" aria-label="Database information">
                <tr>
                    <th scope="col">Schema Version</th>
                    <td><?= htmlspecialchars($schemaVersion) ?></td>
                </tr>
                <tr>
                    <th scope="col">Last Migration</th>
                    <td><?= htmlspecialchars($lastMigration) ?></td>
                </tr>
                <tr>
                    <th scope="col">Database Type</th>
                    <td><?= strtoupper($dbType) ?></td>
                </tr>
                <?php if ($dbType === 'sqlite' && defined('DB_PATH')): ?>
                <tr>
                    <th scope="col">Database Path</th>
                    <td><code><?= htmlspecialchars(DB_PATH) ?></code></td>
                </tr>
                <?php endif; ?>
            </table>
        </details>

        <details class="settings-section">
            <summary><h2>Table Statistics</h2></summary>
            <table class="data-table" aria-label="Table statistics">
                <thead>
                    <tr>
                        <th scope="col">Table</th>
                        <th scope="col">Row Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tableCounts as $table => $count): ?>
                    <tr>
                        <td><?= htmlspecialchars($table) ?></td>
                        <td><?= is_numeric($count) ? number_format($count) : $count ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>

        <details class="settings-section" open>
            <summary><h2>Migration Status</h2></summary>
            <?php if ($pendingCount > 0): ?>
                <div role="alert" class="alert alert-warning" style="margin-bottom: 1rem;">
                    <?= $pendingCount ?> migration(s) pending. Run migrations to update your database schema.
                </div>
            <?php else: ?>
                <div role="status" class="alert alert-success" style="margin-bottom: 1rem;">
                    Database schema is up to date.
                </div>
            <?php endif; ?>

            <div class="migration-list">
                <?php foreach ($migrationStatus as $m): ?>
                    <div class="migration-item <?= $m['applied'] ? 'applied' : 'pending' ?>">
                        <span class="migration-status">
                            <?= $m['applied'] ? '✓' : '○' ?>
                        </span>
                        <span class="migration-name"><?= htmlspecialchars($m['name']) ?></span>
                        <?php if ($m['description']): ?>
                            <span class="migration-desc"><?= htmlspecialchars($m['description']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($pendingCount > 0): ?>
                <form method="post" style="margin-top: 1rem;" data-confirm="Run all pending migrations? This will modify your database schema.">
                    <?= csrf_field() ?>
                    <button type="submit" name="run_migrations" class="btn btn-primary">
                        Run <?= $pendingCount ?> Pending Migration(s)
                    </button>
                </form>
            <?php endif; ?>
        </details>

        <details class="settings-section">
            <summary><h2>Database Maintenance</h2></summary>
            <div class="button-group">
                <?php if ($dbType === 'sqlite'): ?>
                <form method="post" style="display: inline;">
                    <?= csrf_field() ?>
                    <button type="submit" name="backup_db" class="btn btn-secondary">
                        Create Backup
                    </button>
                </form>
                <?php endif; ?>
                <form method="post" style="display: inline;" data-confirm="Optimize database? This may take a moment.">
                    <?= csrf_field() ?>
                    <button type="submit" name="optimize_db" class="btn btn-secondary">
                        Optimize Database
                    </button>
                </form>
            </div>
            <p class="form-hint" style="margin-top: 0.5rem;">
                <?php if ($dbType === 'sqlite'): ?>
                    Backup creates a copy of the database file. Optimize runs VACUUM and ANALYZE to reclaim space and update statistics.
                <?php else: ?>
                    Optimize runs OPTIMIZE TABLE on all tables to reclaim space and update statistics.
                <?php endif; ?>
            </p>
        </details>

        <details class="settings-section">
            <summary><h2>CLI Tool</h2></summary>
            <p>For automated updates and scripting, use the CLI migration tool:</p>
            <pre style="background: var(--bg-tertiary); padding: 1rem; border-radius: 8px; overflow-x: auto;"><code># Check migration status
php cli/migrate.php --status

# Run migrations with backup
php cli/migrate.php --backup

# Dry run (preview changes)
php cli/migrate.php --dry-run

# Run via bin/meshsilo
./bin/meshsilo migrate</code></pre>
                </details>
            </div>
        </div>

<style>
.migration-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
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
    border-left-color: var(--success-color, #22c55e);
}

.migration-item.pending {
    border-left-color: var(--warning-color, #f59e0b);
}

.migration-status {
    font-size: 1rem;
    min-width: 1.5rem;
}

.migration-item.applied .migration-status {
    color: var(--success-color, #22c55e);
}

.migration-item.pending .migration-status {
    color: var(--warning-color, #f59e0b);
}

.migration-name {
    font-weight: 500;
    flex-shrink: 0;
}

.migration-desc {
    color: var(--text-muted);
    font-size: 0.875rem;
    margin-left: auto;
}

.migration-results {
    background: var(--bg-tertiary);
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.migration-results h3 {
    margin: 0 0 0.75rem 0;
    font-size: 1rem;
}

.migration-results ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.migration-results li {
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--border-color);
}

.migration-results li:last-child {
    border-bottom: none;
}

.migration-results li.success {
    color: var(--success-color, #22c55e);
}

.migration-results li.error {
    color: var(--error-color, #ef4444);
}

.stat-card-warning {
    border-color: var(--warning-color, #f59e0b);
}

.stat-card-warning .stat-value {
    color: var(--warning-color, #f59e0b);
}

.stat-card-success .stat-value {
    color: var(--success-color, #22c55e);
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th,
.data-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}

.data-table th {
    font-weight: 500;
    color: var(--text-muted);
    width: 200px;
}

.alert {
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.alert-success {
    background: rgba(34, 197, 94, 0.1);
    color: var(--success-color, #22c55e);
    border: 1px solid var(--success-color, #22c55e);
}

.alert-warning {
    background: rgba(245, 158, 11, 0.1);
    color: var(--warning-color, #f59e0b);
    border: 1px solid var(--warning-color, #f59e0b);
}

.alert-error {
    background: rgba(239, 68, 68, 0.1);
    color: var(--error-color, #ef4444);
    border: 1px solid var(--error-color, #ef4444);
}
</style>

<?php
require_once __DIR__ . '/../../includes/footer.php';

// formatBytes is defined in includes/helpers.php
