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
    } elseif (isset($_POST['resync_from_sqlite'])) {
        // Re-copy a specific table from the SQLite backup after a MySQL migration
        $sqlitePath = defined('DB_PATH') ? DB_PATH : (dirname(__DIR__, 2) . '/storage/db/meshsilo.db');
        if (!file_exists($sqlitePath)) {
            // Try backup path
            $configBackup = dirname(__DIR__, 2) . '/storage/db/config.local.php.sqlite.bak';
            if (file_exists($configBackup)) {
                $bak = file_get_contents($configBackup);
                if (preg_match("/DB_PATH.*'([^']+)'/", $bak, $m)) {
                    $sqlitePath = $m[1];
                }
            }
        }
        // Also check default location
        if (!file_exists($sqlitePath)) {
            $sqlitePath = dirname(__DIR__, 2) . '/storage/db/meshsilo.db';
        }
        if (!file_exists($sqlitePath)) {
            $error = 'SQLite database file not found. Cannot re-sync.';
        } else {
            try {
                $sqlite = new PDO('sqlite:' . $sqlitePath, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $mysqlPdo = $db->getPDO();
                $tableName = $_POST['sync_table'] ?? '';
                $allowedTables = ['model_attachments', 'model_links', 'model_annotations', 'share_links', 'model_versions', 'related_models', 'activity_log'];
                if (!in_array($tableName, $allowedTables)) {
                    $error = 'Invalid table for re-sync.';
                } else {
                    $quotedTable = $tableName;
                    // Get existing count in MySQL
                    $mysqlCount = (int)$mysqlPdo->query("SELECT COUNT(*) FROM {$quotedTable}")->fetchColumn();

                    // Get SQLite data
                    $sqliteRows = $sqlite->query("SELECT * FROM \"{$tableName}\"")->fetchAll();
                    $sqliteCount = count($sqliteRows);

                    if ($sqliteCount === 0) {
                        $error = "No data found in SQLite table '{$tableName}'.";
                    } elseif ($mysqlCount >= $sqliteCount) {
                        $message = "MySQL already has {$mysqlCount} rows (SQLite has {$sqliteCount}). No sync needed.";
                    } else {
                        // Get columns that exist in both
                        $sqliteCols = array_keys($sqliteRows[0]);
                        $mysqlCols = [];
                        $colResult = $mysqlPdo->query("DESCRIBE {$quotedTable}");
                        while ($c = $colResult->fetch()) { $mysqlCols[] = $c['Field']; }
                        $columns = array_values(array_intersect($sqliteCols, $mysqlCols));

                        // Get MySQL column types for type coercion
                        $mysqlTypes = [];
                        $typeResult = $mysqlPdo->query("DESCRIBE {$quotedTable}");
                        while ($c = $typeResult->fetch()) { $mysqlTypes[$c['Field']] = $c['Type']; }

                        $colList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
                        $ph = implode(', ', array_fill(0, count($columns), '?'));

                        $mysqlPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                        $mysqlPdo->exec("DELETE FROM {$quotedTable}");
                        $stmt = $mysqlPdo->prepare("INSERT INTO {$quotedTable} ({$colList}) VALUES ({$ph})");
                        $copied = 0;
                        foreach ($sqliteRows as $row) {
                            $values = [];
                            foreach ($columns as $col) {
                                $val = $row[$col] ?? null;
                                if ($val !== null && isset($mysqlTypes[$col]) && preg_match('/^(int|tinyint|bigint|smallint)/i', $mysqlTypes[$col]) && !is_numeric($val)) {
                                    $val = null;
                                }
                                $values[] = $val;
                            }
                            try { $stmt->execute($values); $copied++; } catch (PDOException $e) {}
                        }
                        $mysqlPdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                        $message = "Re-synced {$copied} rows from SQLite '{$tableName}' to MySQL.";
                    }
                }
            } catch (Exception $e) {
                $error = 'Re-sync failed: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['migrate_to_mysql'])) {
        if ($dbType !== 'sqlite') {
            $error = 'Database is already MySQL.';
        } else {
            $mHost = trim($_POST['mysql_host'] ?? '');
            $mPort = trim($_POST['mysql_port'] ?? '3306');
            $mName = trim($_POST['mysql_name'] ?? '');
            $mUser = trim($_POST['mysql_user'] ?? '');
            $mPass = $_POST['mysql_pass'] ?? '';

            if (!$mHost || !$mName || !$mUser) {
                $error = 'Host, database name, and username are required.';
            } else {
                set_time_limit(300);

                try {
                    // Connect to MySQL
                    $mysqlDsn = "mysql:host={$mHost};port={$mPort};dbname={$mName};charset=utf8mb4";
                    $mysql = new PDO($mysqlDsn, $mUser, $mPass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]);

                    // Connect to SQLite source
                    $sqlite = new PDO('sqlite:' . DB_PATH, null, null, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]);

                    // Create MySQL schema
                    $mysqlDb = new Database('mysql', [
                        'host' => $mHost, 'port' => $mPort,
                        'name' => $mName, 'user' => $mUser, 'pass' => $mPass,
                    ]);

                    // Get SQLite tables
                    $sqliteTables = [];
                    $result = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'models_fts%'");
                    while ($row = $result->fetch()) {
                        $sqliteTables[] = $row['name'];
                    }

                    // Drop existing MySQL tables
                    $mysql->exec("SET FOREIGN_KEY_CHECKS = 0");
                    foreach ($sqliteTables as $t) {
                        $qt = ($t === 'groups') ? '`groups`' : $t;
                        try { $mysql->exec("DROP TABLE IF EXISTS {$qt}"); } catch (PDOException $e) {}
                    }

                    // Initialize schema + migrations
                    initializeDatabase($mysqlDb);
                    runAllMigrations($mysqlDb);

                    // Copy data table by table
                    $totalRows = 0;
                    foreach ($sqliteTables as $table) {
                        $qt = ($table === 'groups') ? '`groups`' : $table;

                        $count = (int)$sqlite->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn();
                        if ($count === 0) continue;

                        // Get columns that exist in both
                        $sqliteCols = [];
                        $colResult = $sqlite->query("PRAGMA table_info(\"{$table}\")");
                        while ($c = $colResult->fetch()) { $sqliteCols[] = $c['name']; }

                        $mysqlCols = [];
                        try {
                            $mcResult = $mysql->query("DESCRIBE {$qt}");
                            while ($c = $mcResult->fetch()) { $mysqlCols[] = $c['Field']; }
                        } catch (PDOException $e) { continue; }

                        $columns = array_values(array_intersect($sqliteCols, $mysqlCols));
                        if (empty($columns)) continue;

                        $colList = implode(', ', array_map(function($c) { return "`{$c}`"; }, $columns));
                        $ph = implode(', ', array_fill(0, count($columns), '?'));
                        $selCols = implode(', ', array_map(function($c) { return "\"{$c}\""; }, $columns));

                        $mysql->exec("DELETE FROM {$qt}");

                        // Get MySQL column types for type coercion
                        $mysqlTypes = [];
                        try {
                            $typeResult = $mysql->query("DESCRIBE {$qt}");
                            while ($c = $typeResult->fetch()) { $mysqlTypes[$c['Field']] = $c['Type']; }
                        } catch (PDOException $e) {}

                        $rows = $sqlite->query("SELECT {$selCols} FROM \"{$table}\"")->fetchAll();
                        if (empty($rows)) continue;

                        $stmt = $mysql->prepare("INSERT INTO {$qt} ({$colList}) VALUES ({$ph})");
                        foreach ($rows as $row) {
                            $values = [];
                            foreach ($columns as $col) {
                                $val = $row[$col];
                                // Coerce strings to null for integer columns
                                if ($val !== null && isset($mysqlTypes[$col]) && preg_match('/^(int|tinyint|bigint|smallint)/i', $mysqlTypes[$col]) && !is_numeric($val)) {
                                    $val = null;
                                }
                                $values[] = $val;
                            }
                            try { $stmt->execute($values); $totalRows++; } catch (PDOException $e) {}
                        }
                    }

                    // Reset auto-increment
                    foreach ($sqliteTables as $table) {
                        $qt = ($table === 'groups') ? '`groups`' : $table;
                        try {
                            $maxId = $mysql->query("SELECT MAX(id) FROM {$qt}")->fetchColumn();
                            if ($maxId) $mysql->exec("ALTER TABLE {$qt} AUTO_INCREMENT = " . ($maxId + 1));
                        } catch (PDOException $e) {}
                    }

                    $mysql->exec("SET FOREIGN_KEY_CHECKS = 1");

                    // Update config.local.php
                    $projectRoot = dirname(__DIR__, 2);
                    $configPath = null;
                    foreach ([
                        $projectRoot . '/storage/db/config.local.php',
                        $projectRoot . '/db/config.local.php',
                        $projectRoot . '/config.local.php',
                    ] as $p) {
                        if (file_exists($p)) { $configPath = $p; break; }
                    }
                    if (!$configPath) {
                        $configPath = $projectRoot . '/storage/db/config.local.php';
                    }

                    // Backup
                    @copy($configPath, $configPath . '.sqlite.bak');

                    $cfg = "<?php\n";
                    $cfg .= "// Migrated from SQLite to MySQL on " . date('Y-m-d H:i:s') . "\n";
                    $cfg .= "define('DB_TYPE', 'mysql');\n";
                    $cfg .= "define('DB_HOST', '" . addslashes($mHost) . "');\n";
                    $cfg .= "define('DB_PORT', '" . addslashes($mPort) . "');\n";
                    $cfg .= "define('DB_NAME', '" . addslashes($mName) . "');\n";
                    $cfg .= "define('DB_USER', '" . addslashes($mUser) . "');\n";
                    $cfg .= "define('DB_PASS', '" . addslashes($mPass) . "');\n";
                    $cfg .= "define('INSTALLED', true);\n";

                    if (file_put_contents($configPath, $cfg) === false) {
                        $error = "Migration completed ({$totalRows} rows) but failed to update config file. Please update {$configPath} manually.";
                    } else {
                        $_SESSION['success'] = "Migration to MySQL completed successfully. {$totalRows} rows copied.";
                        header('Location: ' . route('admin.database'));
                        exit;
                    }
                } catch (PDOException $e) {
                    $error = 'Migration failed: ' . $e->getMessage();
                } catch (Exception $e) {
                    $error = 'Migration failed: ' . $e->getMessage();
                }
            }
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

        <?php
        if (isset($_SESSION['success'])) {
            $message = $_SESSION['success'];
            unset($_SESSION['success']);
        }
        ?>
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
            <pre style="background: var(--color-surface-hover); padding: 1rem; border-radius: 8px; overflow-x: auto;"><code># Check migration status
php cli/migrate.php --status

# Run migrations with backup
php cli/migrate.php --backup

# Dry run (preview changes)
php cli/migrate.php --dry-run

# Run via bin/meshsilo
./bin/meshsilo migrate</code></pre>
                </details>

        <?php if ($dbType === 'sqlite'): ?>
        <details class="settings-section">
            <summary><h2>Migrate to MySQL</h2></summary>
            <p style="color: var(--color-text-muted); margin-bottom: 1rem;">
                Transfer all data from SQLite to a MySQL database. Your files on disk are not affected.
                A backup of the current configuration will be saved automatically.
            </p>
            <form method="POST" class="settings-form" style="max-width: 500px;">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="mysql_host">MySQL Host</label>
                    <input type="text" id="mysql_host" name="mysql_host" class="form-control" value="<?= htmlspecialchars(getenv('MESHSILO_DB_HOST') ?: 'localhost') ?>" required>
                </div>
                <div class="form-group">
                    <label for="mysql_port">Port</label>
                    <input type="text" id="mysql_port" name="mysql_port" class="form-control" value="<?= htmlspecialchars(getenv('MESHSILO_DB_PORT') ?: '3306') ?>">
                </div>
                <div class="form-group">
                    <label for="mysql_name">Database Name</label>
                    <input type="text" id="mysql_name" name="mysql_name" class="form-control" value="<?= htmlspecialchars(getenv('MESHSILO_DB_NAME') ?: 'meshsilo') ?>" required>
                </div>
                <div class="form-group">
                    <label for="mysql_user">Username</label>
                    <input type="text" id="mysql_user" name="mysql_user" class="form-control" value="<?= htmlspecialchars(getenv('MESHSILO_DB_USER') ?: '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="mysql_pass">Password</label>
                    <input type="password" id="mysql_pass" name="mysql_pass" class="form-control" value="<?= htmlspecialchars(getenv('MESHSILO_DB_PASS') ?: '') ?>">
                </div>
                <button type="submit" name="migrate_to_mysql" class="btn btn-warning"
                        data-confirm="This will copy all data to MySQL and switch the database. Continue?">
                    Migrate to MySQL
                </button>
            </form>
        </details>
        <?php endif; ?>

        <?php
        $sqliteBackupExists = false;
        if ($dbType === 'mysql') {
            $sqlitePaths = [
                dirname(__DIR__, 2) . '/storage/db/meshsilo.db',
                dirname(__DIR__, 2) . '/storage/db/silo.db',
            ];
            foreach ($sqlitePaths as $p) {
                if (file_exists($p)) { $sqliteBackupExists = true; break; }
            }
        }
        ?>
        <?php if ($sqliteBackupExists): ?>
        <details class="settings-section">
            <summary><h2>Re-sync from SQLite</h2></summary>
            <p style="color: var(--color-text-muted); margin-bottom: 1rem;">
                If data was lost during the SQLite-to-MySQL migration, you can re-copy specific tables from the original SQLite file.
            </p>
            <form method="POST" style="max-width: 400px;">
                <?= csrf_field() ?>
                <input type="hidden" name="resync_from_sqlite" value="1">
                <div class="form-group">
                    <label for="sync_table">Table to re-sync</label>
                    <select id="sync_table" name="sync_table" class="form-control">
                        <option value="model_attachments">Attachments</option>
                        <option value="model_links">External Links</option>
                        <option value="model_versions">Version History</option>
                        <option value="share_links">Share Links</option>
                        <option value="related_models">Related Models</option>
                        <option value="model_annotations">Annotations</option>
                        <option value="activity_log">Activity Log</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-secondary"
                        data-confirm="This will replace the selected MySQL table data with data from SQLite. Continue?">
                    Re-sync Table
                </button>
            </form>
        </details>
        <?php endif; ?>

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
    background: var(--color-surface-hover);
    border-radius: 6px;
    border-left: 3px solid var(--color-border);
}

.migration-item.applied {
    border-left-color: var(--color-success, #22c55e);
}

.migration-item.pending {
    border-left-color: var(--color-warning, #f59e0b);
}

.migration-status {
    font-size: 1rem;
    min-width: 1.5rem;
}

.migration-item.applied .migration-status {
    color: var(--color-success, #22c55e);
}

.migration-item.pending .migration-status {
    color: var(--color-warning, #f59e0b);
}

.migration-name {
    font-weight: 500;
    flex-shrink: 0;
}

.migration-desc {
    color: var(--color-text-muted);
    font-size: 0.875rem;
    margin-left: auto;
}

.migration-results {
    background: var(--color-surface-hover);
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
    border-bottom: 1px solid var(--color-border);
}

.migration-results li:last-child {
    border-bottom: none;
}

.migration-results li.success {
    color: var(--color-success, #22c55e);
}

.migration-results li.error {
    color: var(--color-danger, #ef4444);
}

.stat-card-warning {
    border-color: var(--color-warning, #f59e0b);
}

.stat-card-warning .stat-value {
    color: var(--color-warning, #f59e0b);
}

.stat-card-success .stat-value {
    color: var(--color-success, #22c55e);
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th,
.data-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid var(--color-border);
}

.data-table th {
    font-weight: 500;
    color: var(--color-text-muted);
    width: 200px;
}

.alert {
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.alert-success {
    background: color-mix(in srgb, var(--color-success) 10%, transparent);
    color: var(--color-success, #22c55e);
    border: 1px solid var(--color-success, #22c55e);
}

.alert-warning {
    background: color-mix(in srgb, var(--color-warning) 10%, transparent);
    color: var(--color-warning, #f59e0b);
    border: 1px solid var(--color-warning, #f59e0b);
}

.alert-error {
    background: color-mix(in srgb, var(--color-danger) 10%, transparent);
    color: var(--color-danger, #ef4444);
    border: 1px solid var(--color-danger, #ef4444);
}
</style>

<?php
require_once __DIR__ . '/../../includes/footer.php';

// formatBytes is defined in includes/helpers.php
