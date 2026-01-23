<?php
require_once __DIR__ . '/../includes/config.php';
// Set baseDir based on how we're accessed (router vs direct)
// Router loads from root context, direct access needs ../
$baseDir = isset($_SERVER['ROUTE_NAME']) ? '' : '../';

// Require admin permission
requirePermission(PERM_ADMIN, $baseDir . 'index.php');

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                    $db->exec("OPTIMIZE TABLE `{$row[0]}`");
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

require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-container">
    <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>

    <main class="admin-main">
        <h1>Database Management</h1>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
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

        <section class="settings-section">
            <h2>Database Information</h2>
            <table class="data-table">
                <tr>
                    <th>Schema Version</th>
                    <td><?= htmlspecialchars($schemaVersion) ?></td>
                </tr>
                <tr>
                    <th>Last Migration</th>
                    <td><?= htmlspecialchars($lastMigration) ?></td>
                </tr>
                <tr>
                    <th>Database Type</th>
                    <td><?= strtoupper($dbType) ?></td>
                </tr>
                <?php if ($dbType === 'sqlite' && defined('DB_PATH')): ?>
                <tr>
                    <th>Database Path</th>
                    <td><code><?= htmlspecialchars(DB_PATH) ?></code></td>
                </tr>
                <?php endif; ?>
            </table>
        </section>

        <section class="settings-section">
            <h2>Table Statistics</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Table</th>
                        <th>Row Count</th>
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
        </section>

        <section class="settings-section">
            <h2>Migration Status</h2>
            <?php if ($pendingCount > 0): ?>
                <div class="alert alert-warning" style="margin-bottom: 1rem;">
                    <?= $pendingCount ?> migration(s) pending. Run migrations to update your database schema.
                </div>
            <?php else: ?>
                <div class="alert alert-success" style="margin-bottom: 1rem;">
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
                <form method="post" style="margin-top: 1rem;" onsubmit="return confirm('Run all pending migrations? This will modify your database schema.');">
                    <button type="submit" name="run_migrations" class="btn btn-primary">
                        Run <?= $pendingCount ?> Pending Migration(s)
                    </button>
                </form>
            <?php endif; ?>
        </section>

        <section class="settings-section">
            <h2>Database Maintenance</h2>
            <div class="button-group">
                <?php if ($dbType === 'sqlite'): ?>
                <form method="post" style="display: inline;">
                    <button type="submit" name="backup_db" class="btn btn-secondary">
                        Create Backup
                    </button>
                </form>
                <?php endif; ?>
                <form method="post" style="display: inline;" onsubmit="return confirm('Optimize database? This may take a moment.');">
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
        </section>

        <section class="settings-section">
            <h2>CLI Tool</h2>
            <p>For automated updates and scripting, use the CLI migration tool:</p>
            <pre style="background: var(--bg-tertiary); padding: 1rem; border-radius: 8px; overflow-x: auto;"><code># Check migration status
php cli/migrate.php --status

# Run migrations with backup
php cli/migrate.php --backup

# Dry run (preview changes)
php cli/migrate.php --dry-run

# Run via bin/silo
./bin/silo migrate</code></pre>
        </section>
    </main>
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
require_once __DIR__ . '/../includes/footer.php';

// Helper function for formatting bytes
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

// Get migration list - mirrors cli/migrate.php
function getMigrationList() {
    return [
        [
            'name' => 'Tags table',
            'description' => 'Tag names and colors for model organization',
            'check' => fn($db) => tableExists($db, 'tags'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE tags (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(100) NOT NULL UNIQUE,
                        color VARCHAR(7) DEFAULT "#6366f1",
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                } else {
                    $db->exec('CREATE TABLE tags (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT NOT NULL UNIQUE,
                        color TEXT DEFAULT "#6366f1",
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )');
                }
            }
        ],
        [
            'name' => 'Model-Tags junction table',
            'description' => 'Links models to tags',
            'check' => fn($db) => tableExists($db, 'model_tags'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE model_tags (
                        model_id INT NOT NULL,
                        tag_id INT NOT NULL,
                        PRIMARY KEY (model_id, tag_id)
                    ) ENGINE=InnoDB');
                } else {
                    $db->exec('CREATE TABLE model_tags (
                        model_id INTEGER NOT NULL,
                        tag_id INTEGER NOT NULL,
                        PRIMARY KEY (model_id, tag_id)
                    )');
                }
            }
        ],
        [
            'name' => 'Favorites table',
            'description' => 'User bookmarks',
            'check' => fn($db) => tableExists($db, 'favorites'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE favorites (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        model_id INT NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY (user_id, model_id)
                    ) ENGINE=InnoDB');
                } else {
                    $db->exec('CREATE TABLE favorites (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        model_id INTEGER NOT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE (user_id, model_id)
                    )');
                }
            }
        ],
        [
            'name' => 'Activity log table',
            'description' => 'Audit trail',
            'check' => fn($db) => tableExists($db, 'activity_log'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE activity_log (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT,
                        action VARCHAR(50) NOT NULL,
                        entity_type VARCHAR(50),
                        entity_id INT,
                        entity_name VARCHAR(255),
                        details TEXT,
                        ip_address VARCHAR(45),
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB');
                } else {
                    $db->exec('CREATE TABLE activity_log (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER,
                        action TEXT NOT NULL,
                        entity_type TEXT,
                        entity_id INTEGER,
                        entity_name TEXT,
                        details TEXT,
                        ip_address TEXT,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )');
                }
            }
        ],
        [
            'name' => 'Recently viewed table',
            'description' => 'Track view history',
            'check' => fn($db) => tableExists($db, 'recently_viewed'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE recently_viewed (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT,
                        session_id VARCHAR(64),
                        model_id INT NOT NULL,
                        viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB');
                } else {
                    $db->exec('CREATE TABLE recently_viewed (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER,
                        session_id TEXT,
                        model_id INTEGER NOT NULL,
                        viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )');
                }
            }
        ],
        [
            'name' => 'Print queue table',
            'description' => 'Print queue management',
            'check' => fn($db) => tableExists($db, 'print_queue'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE print_queue (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        model_id INT NOT NULL,
                        part_id INT,
                        priority INT DEFAULT 0,
                        status VARCHAR(20) DEFAULT "queued",
                        notes TEXT,
                        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB');
                } else {
                    $db->exec('CREATE TABLE print_queue (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        model_id INTEGER NOT NULL,
                        part_id INTEGER,
                        priority INTEGER DEFAULT 0,
                        status TEXT DEFAULT "queued",
                        notes TEXT,
                        added_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )');
                }
            }
        ],
        [
            'name' => 'Models: download_count',
            'description' => 'Download tracking',
            'check' => fn($db) => columnExists($db, 'models', 'download_count'),
            'apply' => fn($db) => $db->exec('ALTER TABLE models ADD COLUMN download_count INTEGER DEFAULT 0')
        ],
        [
            'name' => 'Models: license',
            'description' => 'License type',
            'check' => fn($db) => columnExists($db, 'models', 'license'),
            'apply' => fn($db) => $db->exec('ALTER TABLE models ADD COLUMN license TEXT')
        ],
        [
            'name' => 'Models: is_archived',
            'description' => 'Soft archive',
            'check' => fn($db) => columnExists($db, 'models', 'is_archived'),
            'apply' => fn($db) => $db->exec('ALTER TABLE models ADD COLUMN is_archived INTEGER DEFAULT 0')
        ],
        [
            'name' => 'Models: print tracking',
            'description' => 'Printed status and notes',
            'check' => fn($db) => columnExists($db, 'models', 'is_printed'),
            'apply' => function($db) {
                $db->exec('ALTER TABLE models ADD COLUMN is_printed INTEGER DEFAULT 0');
                $db->exec('ALTER TABLE models ADD COLUMN printed_at DATETIME');
                $db->exec('ALTER TABLE models ADD COLUMN notes TEXT');
            }
        ],
        [
            'name' => 'Models: dimensions',
            'description' => 'Calculated dimensions',
            'check' => fn($db) => columnExists($db, 'models', 'dim_x'),
            'apply' => function($db) {
                $db->exec('ALTER TABLE models ADD COLUMN dim_x REAL');
                $db->exec('ALTER TABLE models ADD COLUMN dim_y REAL');
                $db->exec('ALTER TABLE models ADD COLUMN dim_z REAL');
                $db->exec('ALTER TABLE models ADD COLUMN volume REAL');
            }
        ],
        [
            'name' => 'API keys table',
            'description' => 'API authentication',
            'check' => fn($db) => tableExists($db, 'api_keys'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE api_keys (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        name VARCHAR(255) NOT NULL,
                        key_hash VARCHAR(64) NOT NULL UNIQUE,
                        key_prefix VARCHAR(12) NOT NULL,
                        permissions TEXT,
                        is_active TINYINT DEFAULT 1,
                        expires_at TIMESTAMP NULL,
                        last_used_at TIMESTAMP NULL,
                        request_count INT DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB');
                } else {
                    $db->exec('CREATE TABLE api_keys (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        name TEXT NOT NULL,
                        key_hash TEXT NOT NULL UNIQUE,
                        key_prefix TEXT NOT NULL,
                        permissions TEXT,
                        is_active INTEGER DEFAULT 1,
                        expires_at DATETIME,
                        last_used_at DATETIME,
                        request_count INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )');
                }
            }
        ],
        [
            'name' => 'API request log table',
            'description' => 'API analytics',
            'check' => fn($db) => tableExists($db, 'api_request_log'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE api_request_log (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        api_key_id INT NOT NULL,
                        method VARCHAR(10) NOT NULL,
                        endpoint VARCHAR(255) NOT NULL,
                        ip_address VARCHAR(45),
                        user_agent VARCHAR(500),
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB');
                } else {
                    $db->exec('CREATE TABLE api_request_log (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        api_key_id INTEGER NOT NULL,
                        method TEXT NOT NULL,
                        endpoint TEXT NOT NULL,
                        ip_address TEXT,
                        user_agent TEXT,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )');
                }
            }
        ],
        [
            'name' => 'Webhooks table',
            'description' => 'Webhook endpoints',
            'check' => fn($db) => tableExists($db, 'webhooks'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE webhooks (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(255),
                        url VARCHAR(500) NOT NULL,
                        secret VARCHAR(255),
                        events TEXT NOT NULL,
                        is_active TINYINT DEFAULT 1,
                        last_triggered_at TIMESTAMP NULL,
                        last_status_code INT,
                        failure_count INT DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB');
                } else {
                    $db->exec('CREATE TABLE webhooks (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT,
                        url TEXT NOT NULL,
                        secret TEXT,
                        events TEXT NOT NULL,
                        is_active INTEGER DEFAULT 1,
                        last_triggered_at DATETIME,
                        last_status_code INTEGER,
                        failure_count INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )');
                }
            }
        ],
        [
            'name' => 'Webhook deliveries table',
            'description' => 'Delivery log',
            'check' => fn($db) => tableExists($db, 'webhook_deliveries'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE webhook_deliveries (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        webhook_id INT NOT NULL,
                        event VARCHAR(100) NOT NULL,
                        payload TEXT NOT NULL,
                        response_code INT,
                        response_body TEXT,
                        success TINYINT DEFAULT 0,
                        duration_ms INT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB');
                } else {
                    $db->exec('CREATE TABLE webhook_deliveries (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        webhook_id INTEGER NOT NULL,
                        event TEXT NOT NULL,
                        payload TEXT NOT NULL,
                        response_code INTEGER,
                        response_body TEXT,
                        success INTEGER DEFAULT 0,
                        duration_ms INTEGER,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )');
                }
            }
        ],
        [
            'name' => 'Print photos table',
            'description' => 'Photos of prints',
            'check' => fn($db) => tableExists($db, 'print_photos'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE print_photos (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        model_id INT NOT NULL,
                        user_id INT,
                        filename VARCHAR(255) NOT NULL,
                        file_path VARCHAR(500) NOT NULL,
                        caption TEXT,
                        is_primary TINYINT DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB');
                } else {
                    $db->exec('CREATE TABLE print_photos (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        model_id INTEGER NOT NULL,
                        user_id INTEGER,
                        filename TEXT NOT NULL,
                        file_path TEXT NOT NULL,
                        caption TEXT,
                        is_primary INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )');
                }
            }
        ],
        [
            'name' => 'Printers table',
            'description' => 'Printer profiles',
            'check' => fn($db) => tableExists($db, 'printers'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE printers (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT,
                        name VARCHAR(255) NOT NULL,
                        manufacturer VARCHAR(255),
                        model VARCHAR(255),
                        bed_x DECIMAL(10,2),
                        bed_y DECIMAL(10,2),
                        bed_z DECIMAL(10,2),
                        print_type VARCHAR(50) DEFAULT "fdm",
                        is_default TINYINT DEFAULT 0,
                        notes TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB');
                } else {
                    $db->exec('CREATE TABLE printers (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER,
                        name TEXT NOT NULL,
                        manufacturer TEXT,
                        model TEXT,
                        bed_x REAL,
                        bed_y REAL,
                        bed_z REAL,
                        print_type TEXT DEFAULT "fdm",
                        is_default INTEGER DEFAULT 0,
                        notes TEXT,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )');
                }
            }
        ],
        [
            'name' => 'Model ratings table',
            'description' => 'User ratings',
            'check' => fn($db) => tableExists($db, 'model_ratings'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE model_ratings (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        model_id INT NOT NULL,
                        user_id INT NOT NULL,
                        rating TINYINT NOT NULL,
                        review TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY (model_id, user_id)
                    ) ENGINE=InnoDB');
                } else {
                    $db->exec('CREATE TABLE model_ratings (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        model_id INTEGER NOT NULL,
                        user_id INTEGER NOT NULL,
                        rating INTEGER NOT NULL,
                        review TEXT,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE (model_id, user_id)
                    )');
                }
            }
        ],
        [
            'name' => 'Folders table',
            'description' => 'Model organization',
            'check' => fn($db) => tableExists($db, 'folders'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE folders (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        parent_id INT,
                        user_id INT,
                        name VARCHAR(255) NOT NULL,
                        description TEXT,
                        color VARCHAR(7),
                        sort_order INT DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB');
                } else {
                    $db->exec('CREATE TABLE folders (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        parent_id INTEGER,
                        user_id INTEGER,
                        name TEXT NOT NULL,
                        description TEXT,
                        color TEXT,
                        sort_order INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )');
                }
            }
        ],
        [
            'name' => 'Models: folder_id',
            'description' => 'Folder assignment',
            'check' => fn($db) => columnExists($db, 'models', 'folder_id'),
            'apply' => fn($db) => $db->exec('ALTER TABLE models ADD COLUMN folder_id INTEGER')
        ],
        [
            'name' => 'Models: approval columns',
            'description' => 'Upload approval workflow',
            'check' => fn($db) => columnExists($db, 'models', 'approval_status'),
            'apply' => function($db) {
                $db->exec("ALTER TABLE models ADD COLUMN approval_status TEXT DEFAULT 'approved'");
                $db->exec('ALTER TABLE models ADD COLUMN approved_by INTEGER');
                $db->exec('ALTER TABLE models ADD COLUMN approved_at DATETIME');
            }
        ],
        [
            'name' => 'Users: storage limits',
            'description' => 'Per-user limits',
            'check' => fn($db) => columnExists($db, 'users', 'storage_limit_mb'),
            'apply' => function($db) {
                $db->exec('ALTER TABLE users ADD COLUMN storage_limit_mb INTEGER DEFAULT 0');
                $db->exec('ALTER TABLE users ADD COLUMN model_limit INTEGER DEFAULT 0');
            }
        ],
        [
            'name' => 'Teams table',
            'description' => 'Team workspaces',
            'check' => fn($db) => tableExists($db, 'teams'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE teams (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(255) NOT NULL,
                        description TEXT,
                        owner_id INT NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB');
                } else {
                    $db->exec('CREATE TABLE teams (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT NOT NULL,
                        description TEXT,
                        owner_id INTEGER NOT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )');
                }
            }
        ],
        [
            'name' => 'Team members table',
            'description' => 'Team membership',
            'check' => fn($db) => tableExists($db, 'team_members'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE team_members (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        team_id INT NOT NULL,
                        user_id INT NOT NULL,
                        role VARCHAR(50) DEFAULT "member",
                        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY (team_id, user_id)
                    ) ENGINE=InnoDB');
                } else {
                    $db->exec('CREATE TABLE team_members (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        team_id INTEGER NOT NULL,
                        user_id INTEGER NOT NULL,
                        role TEXT DEFAULT "member",
                        joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE (team_id, user_id)
                    )');
                }
            }
        ],
        [
            'name' => 'Team models table',
            'description' => 'Shared models',
            'check' => fn($db) => tableExists($db, 'team_models'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE team_models (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        team_id INT NOT NULL,
                        model_id INT NOT NULL,
                        shared_by INT NOT NULL,
                        permissions VARCHAR(50) DEFAULT "read",
                        shared_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY (team_id, model_id)
                    ) ENGINE=InnoDB');
                } else {
                    $db->exec('CREATE TABLE team_models (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        team_id INTEGER NOT NULL,
                        model_id INTEGER NOT NULL,
                        shared_by INTEGER NOT NULL,
                        permissions TEXT DEFAULT "read",
                        shared_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE (team_id, model_id)
                    )');
                }
            }
        ],
        [
            'name' => 'Team invites table',
            'description' => 'Pending invitations',
            'check' => fn($db) => tableExists($db, 'team_invites'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE team_invites (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        team_id INT NOT NULL,
                        email VARCHAR(255) NOT NULL,
                        role VARCHAR(50) DEFAULT "member",
                        token VARCHAR(64) NOT NULL UNIQUE,
                        invited_by INT NOT NULL,
                        status VARCHAR(20) DEFAULT "pending",
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB');
                } else {
                    $db->exec('CREATE TABLE team_invites (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        team_id INTEGER NOT NULL,
                        email TEXT NOT NULL,
                        role TEXT DEFAULT "member",
                        token TEXT NOT NULL UNIQUE,
                        invited_by INTEGER NOT NULL,
                        status TEXT DEFAULT "pending",
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )');
                }
            }
        ],
    ];
}
?>
