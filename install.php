<?php
/**
 * MeshSilo Installation Wizard
 *
 * This file guides users through the initial setup of Silo.
 * It should be deleted after installation is complete.
 */

// Show errors during installation for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// Prevent running if already installed
if (file_exists(__DIR__ . '/storage/db/config.local.php') || file_exists(__DIR__ . '/config.local.php')) {
    http_response_code(200);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>MeshSilo - Already Installed</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #1a1a2e; color: #eee; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
            .container { text-align: center; padding: 2rem; }
            h1 { color: #4ade80; margin-bottom: 1rem; }
            p { color: #aaa; margin-bottom: 1.5rem; }
            a { color: #60a5fa; text-decoration: none; }
            a:hover { text-decoration: underline; }
            .note { font-size: 0.875rem; color: #666; margin-top: 2rem; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>MeshSilo is Already Installed</h1>
            <p>The installation wizard has already been completed.</p>
            <p><a href="/">Go to Homepage</a> or <a href="/login">Log In</a></p>
            <p class="note">To reinstall, delete <code>storage/db/config.local.php</code> and refresh this page.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize installation state
if (!isset($_SESSION['install'])) {
    $_SESSION['install'] = [
        'step' => 1,
        'db_type' => 'mysql',
        'db_config' => [],
        'admin' => [],
        'site' => []
    ];
}

$step = $_SESSION['install']['step'];
$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'check_requirements':
            $step = 2;
            $_SESSION['install']['step'] = $step;
            break;

        case 'configure_database':
            $dbType = $_POST['db_type'] ?? 'mysql';
            $_SESSION['install']['db_type'] = $dbType;

            if ($dbType === 'mysql') {
                $config = [
                    'host' => trim($_POST['db_host'] ?? 'localhost'),
                    'port' => trim($_POST['db_port'] ?? '3306'),
                    'name' => trim($_POST['db_name'] ?? 'silo'),
                    'user' => trim($_POST['db_user'] ?? ''),
                    'pass' => $_POST['db_pass'] ?? ''
                ];
                $_SESSION['install']['db_config'] = $config;

                // Test MySQL connection
                $testResult = testMySQLConnection($config);
                if ($testResult !== true) {
                    $error = $testResult;
                } else {
                    $step = 3;
                    $_SESSION['install']['step'] = $step;
                }
            } else {
                // SQLite - just check write permissions
                $dbDir = __DIR__ . '/storage/db';
                if (!is_dir($dbDir)) {
                    @mkdir($dbDir, 0755, true);
                }
                if (!is_writable($dbDir)) {
                    $error = 'Database directory is not writable: ' . $dbDir;
                } else {
                    $step = 3;
                    $_SESSION['install']['step'] = $step;
                }
            }
            break;

        case 'configure_admin':
            $username = trim($_POST['admin_username'] ?? '');
            $email = trim($_POST['admin_email'] ?? '');
            $password = $_POST['admin_password'] ?? '';
            $passwordConfirm = $_POST['admin_password_confirm'] ?? '';

            if (empty($username) || empty($email) || empty($password)) {
                $error = 'All fields are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif ($password !== $passwordConfirm) {
                $error = 'Passwords do not match.';
            } else {
                $_SESSION['install']['admin'] = [
                    'username' => $username,
                    'email' => $email,
                    'password' => $password
                ];
                $step = 4;
                $_SESSION['install']['step'] = $step;
            }
            break;

        case 'configure_site':
            $_SESSION['install']['site'] = [
                'name' => trim($_POST['site_name'] ?? 'MeshSilo'),
                'description' => trim($_POST['site_description'] ?? '3D Model Library'),
                'url' => trim($_POST['site_url'] ?? ''),
                'force_url' => isset($_POST['force_url']) ? '1' : '0'
            ];
            $step = 5;
            $_SESSION['install']['step'] = $step;
            break;

        case 'install':
            $result = performInstallation($_SESSION['install']);
            if ($result === true) {
                $step = 6;
                $_SESSION['install']['step'] = $step;
                $success = 'Installation completed successfully!';
            } else {
                $error = $result;
            }
            break;

        case 'go_back':
            $step = max(1, $step - 1);
            $_SESSION['install']['step'] = $step;
            break;
    }
}

/**
 * Check system requirements
 */
function checkRequirements() {
    $requirements = [];

    // PHP Version
    $requirements['php_version'] = [
        'name' => 'PHP Version',
        'required' => '7.4+',
        'current' => PHP_VERSION,
        'passed' => version_compare(PHP_VERSION, '7.4.0', '>=')
    ];

    // Required extensions
    $extensions = ['pdo', 'json', 'session', 'fileinfo', 'zip'];
    foreach ($extensions as $ext) {
        $requirements['ext_' . $ext] = [
            'name' => 'PHP Extension: ' . $ext,
            'required' => 'Enabled',
            'current' => extension_loaded($ext) ? 'Enabled' : 'Missing',
            'passed' => extension_loaded($ext)
        ];
    }

    // Optional extensions
    $requirements['ext_sqlite3'] = [
        'name' => 'PHP Extension: sqlite3',
        'required' => 'Required for SQLite',
        'current' => extension_loaded('sqlite3') ? 'Enabled' : 'Missing',
        'passed' => extension_loaded('sqlite3'),
        'optional' => true
    ];

    $requirements['ext_pdo_mysql'] = [
        'name' => 'PHP Extension: pdo_mysql',
        'required' => 'Required for MySQL',
        'current' => extension_loaded('pdo_mysql') ? 'Enabled' : 'Missing',
        'passed' => extension_loaded('pdo_mysql'),
        'optional' => true
    ];

    // Directory permissions
    $dirs = [
        'storage/db' => __DIR__ . '/storage/db',
        'storage/assets' => __DIR__ . '/storage/assets',
        'storage/logs' => __DIR__ . '/storage/logs',
        'storage/cache' => __DIR__ . '/storage/cache'
    ];

    foreach ($dirs as $name => $path) {
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        $requirements['dir_' . $name] = [
            'name' => 'Directory: ' . $name,
            'required' => 'Writable',
            'current' => is_writable($path) ? 'Writable' : 'Not Writable',
            'passed' => is_writable($path)
        ];
    }

    // Config file
    $configDir = __DIR__ . '/storage/db';
    if (!is_dir($configDir)) {
        @mkdir($configDir, 0755, true);
    }
    $requirements['config_writable'] = [
        'name' => 'Config Directory',
        'required' => 'Writable',
        'current' => is_writable($configDir) ? 'Writable' : 'Not Writable',
        'passed' => is_writable($configDir)
    ];

    return $requirements;
}

/**
 * Test MySQL connection
 */
function testMySQLConnection($config) {
    try {
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        return true;
    } catch (PDOException $e) {
        return 'MySQL connection failed: ' . $e->getMessage();
    }
}

/**
 * Perform the actual installation
 */
function performInstallation($config) {
    try {
        // Create config file
        $configContent = generateConfigFile($config);
        $configPath = __DIR__ . '/storage/db/config.local.php';

        // Ensure storage/db directory exists
        $configDir = dirname($configPath);
        if (!is_dir($configDir)) {
            @mkdir($configDir, 0755, true);
        }

        if (file_put_contents($configPath, $configContent) === false) {
            return 'Failed to write configuration file.';
        }

        // Initialize database
        if ($config['db_type'] === 'mysql') {
            $result = initializeMySQLDatabase($config);
        } else {
            $result = initializeSQLiteDatabase($config);
        }

        if ($result !== true) {
            @unlink($configPath);
            return $result;
        }

        // Run migrations to ensure all tables and columns are up to date
        try {
            require_once __DIR__ . '/includes/config.php';
            $db = getDB();
            runMigrations($db);
        } catch (Throwable $e) {
            // Log but don't fail - migrations can be run manually
            error_log('Post-install migrations warning: ' . $e->getMessage());
        }

        return true;
    } catch (Throwable $e) {
        return 'Installation failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    }
}

/**
 * Generate configuration file content
 *
 * IMPORTANT: This file should ONLY contain database connection settings.
 * All other settings (site name, URL, server UUID, etc.) are stored in the database.
 * This ensures settings can be managed via admin panel and can't be overwritten by file changes.
 */
function generateConfigFile($config) {
    $dbType = $config['db_type'];

    $content = "<?php\n";
    $content .= "/**\n";
    $content .= " * MeshSilo Database Configuration\n";
    $content .= " *\n";
    $content .= " * This file contains ONLY database connection settings.\n";
    $content .= " * All other settings are stored in the database and managed via the admin panel.\n";
    $content .= " * DO NOT add other settings here - they will be ignored.\n";
    $content .= " *\n";
    $content .= " * Generated: " . date('Y-m-d H:i:s') . "\n";
    $content .= " */\n\n";

    // Database configuration only
    $content .= "// Database Type: sqlite or mysql\n";
    $content .= "define('DB_TYPE', '" . addslashes($dbType) . "');\n";

    if ($dbType === 'mysql') {
        $db = $config['db_config'];
        $content .= "\n// MySQL Connection Settings\n";
        $content .= "define('DB_HOST', '" . addslashes($db['host']) . "');\n";
        $content .= "define('DB_PORT', '" . addslashes($db['port']) . "');\n";
        $content .= "define('DB_NAME', '" . addslashes($db['name']) . "');\n";
        $content .= "define('DB_USER', '" . addslashes($db['user']) . "');\n";
        $content .= "define('DB_PASS', '" . addslashes($db['pass']) . "');\n";
    }

    $content .= "\n// Installation marker\n";
    $content .= "define('INSTALLED', true);\n";

    return $content;
}

/**
 * Initialize SQLite database
 */
function initializeSQLiteDatabase($config) {
    try {
        $dbPath = __DIR__ . '/storage/db/meshsilo.db';

        // Ensure storage/db directory exists
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir)) {
            @mkdir($dbDir, 0755, true);
        }
        $db = new SQLite3($dbPath);
        $db->enableExceptions(true);

        // Create tables
        $schema = getSchemaSQL('sqlite');
        $db->exec($schema);

        // Create admin user
        $admin = $config['admin'];
        $hash = password_hash($admin['password'], PASSWORD_ARGON2ID);

        $stmt = $db->prepare('INSERT INTO users (username, email, password, is_admin) VALUES (:username, :email, :password, 1)');
        $stmt->bindValue(':username', $admin['username'], SQLITE3_TEXT);
        $stmt->bindValue(':email', $admin['email'], SQLITE3_TEXT);
        $stmt->bindValue(':password', $hash, SQLITE3_TEXT);
        $stmt->execute();

        $userId = $db->lastInsertRowID();

        // Assign to Admin group
        $db->exec("INSERT INTO user_groups (user_id, group_id) SELECT $userId, id FROM groups WHERE name = 'Admin'");

        // Save site settings to database (all settings are stored in DB, not config file)
        $site = $config['site'];
        if (!empty($site['name'])) {
            $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('site_name', :val)");
            $stmt->bindValue(':val', $site['name'], SQLITE3_TEXT);
            $stmt->execute();
        }
        if (!empty($site['description'])) {
            $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('site_description', :val)");
            $stmt->bindValue(':val', $site['description'], SQLITE3_TEXT);
            $stmt->execute();
        }
        if (!empty($site['url'])) {
            $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('site_url', :val)");
            $stmt->bindValue(':val', $site['url'], SQLITE3_TEXT);
            $stmt->execute();
        }
        // Always save force_site_url setting
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('force_site_url', :val)");
        $stmt->bindValue(':val', $site['force_url'] ?? '0', SQLITE3_TEXT);
        $stmt->execute();

        // Generate and save server UUID for license tracking
        $serverUuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('server_uuid', :val)");
        $stmt->bindValue(':val', $serverUuid, SQLITE3_TEXT);
        $stmt->execute();

        $db->close();
        return true;
    } catch (Exception $e) {
        return 'SQLite initialization failed: ' . $e->getMessage();
    }
}

/**
 * Initialize MySQL database
 */
function initializeMySQLDatabase($config) {
    try {
        $db = $config['db_config'];
        $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        // Create tables
        $schema = getSchemaSQL('mysql');
        $pdo->exec($schema);

        // Create admin user
        $admin = $config['admin'];
        $hash = password_hash($admin['password'], PASSWORD_ARGON2ID);

        $stmt = $pdo->prepare('INSERT INTO users (username, email, password, is_admin) VALUES (:username, :email, :password, 1)');
        $stmt->execute([
            ':username' => $admin['username'],
            ':email' => $admin['email'],
            ':password' => $hash
        ]);

        $userId = $pdo->lastInsertId();

        // Assign to Admin group
        $pdo->exec("INSERT INTO user_groups (user_id, group_id) SELECT $userId, id FROM `groups` WHERE name = 'Admin'");

        // Save site settings to database (all settings are stored in DB, not config file)
        $site = $config['site'];
        $stmt = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `value` = :v2");
        if (!empty($site['name'])) {
            $stmt->execute([':k' => 'site_name', ':v' => $site['name'], ':v2' => $site['name']]);
        }
        if (!empty($site['description'])) {
            $stmt->execute([':k' => 'site_description', ':v' => $site['description'], ':v2' => $site['description']]);
        }
        if (!empty($site['url'])) {
            $stmt->execute([':k' => 'site_url', ':v' => $site['url'], ':v2' => $site['url']]);
        }
        // Always save force_site_url setting
        $forceUrl = $site['force_url'] ?? '0';
        $stmt->execute([':k' => 'force_site_url', ':v' => $forceUrl, ':v2' => $forceUrl]);

        // Generate and save server UUID for license tracking
        $serverUuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        $stmt->execute([':k' => 'server_uuid', ':v' => $serverUuid, ':v2' => $serverUuid]);

        return true;
    } catch (Exception $e) {
        return 'MySQL initialization failed: ' . $e->getMessage();
    }
}

/**
 * Get BASE schema SQL - minimal tables needed to bootstrap
 * All other tables are created by running migrations
 */
function getBaseSchemaSQL($type) {
    if ($type === 'mysql') {
        return <<<'SQL'
-- Minimal bootstrap schema - migrations add all other tables and columns
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    is_admin TINYINT DEFAULT 0,
    permissions TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS models (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    filename VARCHAR(255),
    file_path VARCHAR(500),
    file_size BIGINT,
    file_type VARCHAR(50),
    description TEXT,
    creator VARCHAR(255),
    collection VARCHAR(255),
    source_url VARCHAR(500),
    parent_id INT,
    original_path VARCHAR(500),
    part_count INT DEFAULT 0,
    print_type VARCHAR(50),
    original_size BIGINT,
    file_hash VARCHAR(64),
    dedup_path VARCHAR(500),
    download_count INT DEFAULT 0,
    license VARCHAR(100),
    is_archived TINYINT DEFAULT 0,
    notes TEXT,
    is_printed TINYINT DEFAULT 0,
    printed_at TIMESTAMP NULL,
    dim_x DECIMAL(10,3),
    dim_y DECIMAL(10,3),
    dim_z DECIMAL(10,3),
    dim_unit VARCHAR(10) DEFAULT 'mm',
    volume DECIMAL(15,2),
    sort_order INT DEFAULT 0,
    current_version INT DEFAULT 1,
    thumbnail_path VARCHAR(500),
    folder_id INT,
    approval_status VARCHAR(20) DEFAULT 'approved',
    approved_by INT,
    approved_at TIMESTAMP NULL,
    integrity_hash VARCHAR(64),
    integrity_checked_at TIMESTAMP NULL,
    remix_of INT,
    external_source_url VARCHAR(500),
    external_source_id VARCHAR(100),
    user_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES models(id) ON DELETE CASCADE,
    INDEX idx_models_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS model_categories (
    model_id INT,
    category_id INT,
    PRIMARY KEY (model_id, category_id),
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO categories (name) VALUES
    ('Functional'), ('Decorative'), ('Tools'), ('Gaming'), ('Art'), ('Mechanical');

CREATE TABLE IF NOT EXISTS collections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `groups` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    permissions TEXT,
    is_system TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_groups (
    user_id INT,
    group_id INT,
    PRIMARY KEY (user_id, group_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `groups` (name, description, permissions, is_system) VALUES
    ('Admin', 'Full system access', '["upload","delete","edit","admin","view_stats"]', 1),
    ('Users', 'Default user permissions', '["upload","view_stats"]', 1);

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(255) PRIMARY KEY,
    `value` TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (`key`, `value`) VALUES
    ('site_name', 'MeshSilo'),
    ('site_description', 'Your 3D Model Library'),
    ('models_per_page', '20'),
    ('allow_registration', '1'),
    ('default_theme', 'dark'),
    ('default_sort', 'newest'),
    ('default_view', 'grid');
SQL;
    } else {
        // SQLite schema
        return <<<'SQL'
-- Minimal bootstrap schema - migrations add all other tables and columns
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    is_admin INTEGER DEFAULT 0,
    permissions TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS models (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    filename TEXT,
    file_path TEXT,
    file_size INTEGER,
    file_type TEXT,
    description TEXT,
    creator TEXT,
    collection TEXT,
    source_url TEXT,
    parent_id INTEGER,
    original_path TEXT,
    part_count INTEGER DEFAULT 0,
    print_type TEXT,
    original_size INTEGER,
    file_hash TEXT,
    dedup_path TEXT,
    download_count INTEGER DEFAULT 0,
    license TEXT,
    is_archived INTEGER DEFAULT 0,
    notes TEXT,
    is_printed INTEGER DEFAULT 0,
    printed_at DATETIME,
    dim_x REAL,
    dim_y REAL,
    dim_z REAL,
    dim_unit TEXT DEFAULT 'mm',
    volume REAL,
    sort_order INTEGER DEFAULT 0,
    current_version INTEGER DEFAULT 1,
    thumbnail_path TEXT,
    folder_id INTEGER,
    approval_status TEXT DEFAULT 'approved',
    approved_by INTEGER,
    approved_at DATETIME,
    integrity_hash TEXT,
    integrity_checked_at DATETIME,
    remix_of INTEGER,
    external_source_url TEXT,
    external_source_id TEXT,
    user_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES models(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS model_categories (
    model_id INTEGER,
    category_id INTEGER,
    PRIMARY KEY (model_id, category_id),
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

INSERT OR IGNORE INTO categories (name) VALUES
    ('Functional'), ('Decorative'), ('Tools'), ('Gaming'), ('Art'), ('Mechanical');

CREATE TABLE IF NOT EXISTS collections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    permissions TEXT,
    is_system INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_groups (
    user_id INTEGER,
    group_id INTEGER,
    PRIMARY KEY (user_id, group_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
);

INSERT OR IGNORE INTO groups (name, description, permissions, is_system) VALUES
    ('Admin', 'Full system access', '["upload","delete","edit","admin","view_stats"]', 1),
    ('Users', 'Default user permissions', '["upload","view_stats"]', 1);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT OR IGNORE INTO settings (key, value) VALUES
    ('site_name', 'MeshSilo'),
    ('site_description', 'Your 3D Model Library'),
    ('models_per_page', '20'),
    ('allow_registration', '1'),
    ('default_theme', 'dark'),
    ('default_sort', 'newest'),
    ('default_view', 'grid');
SQL;
    }
}

/**
 * LEGACY FUNCTION - kept for compatibility
 * Now just delegates to getBaseSchemaSQL()
 */
function getSchemaSQL($type) {
    return getBaseSchemaSQL($type);
}

// Schema functions end here - all additional tables are created by migrations
// See includes/migrations.php for the complete list of tables

/**
 * Detect web server type
 */
function detectWebServer() {
    $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? '';
    if (stripos($serverSoftware, 'apache') !== false) {
        return 'apache';
    } elseif (stripos($serverSoftware, 'nginx') !== false) {
        return 'nginx';
    }
    return 'unknown';
}

$requirements = checkRequirements();
$allPassed = true;
$criticalFailed = false;

foreach ($requirements as $req) {
    if (!$req['passed'] && empty($req['optional'])) {
        $allPassed = false;
        $criticalFailed = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install MeshSilo</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        :root {
            --color-primary: #2563eb;
            --color-primary-hover: #1d4ed8;
            --color-bg: #0f172a;
            --color-surface: #1e293b;
            --color-text: #f1f5f9;
            --color-text-muted: #94a3b8;
            --color-border: #334155;
            --color-success: #22c55e;
            --color-error: #ef4444;
            --color-warning: #f59e0b;
            --radius: 8px;
        }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            line-height: 1.6;
            background-color: var(--color-bg);
            color: var(--color-text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .installer {
            background-color: var(--color-surface);
            border-radius: 12px;
            padding: 2rem;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        .logo-icon {
            font-size: 3rem;
            color: var(--color-primary);
        }
        .logo h1 {
            font-size: 1.5rem;
            margin-top: 0.5rem;
        }
        .steps {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 2rem;
        }
        .step-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background-color: var(--color-border);
        }
        .step-dot.active {
            background-color: var(--color-primary);
        }
        .step-dot.completed {
            background-color: var(--color-success);
        }
        h2 {
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        .form-input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--color-border);
            border-radius: var(--radius);
            background-color: var(--color-bg);
            color: var(--color-text);
            font-size: 1rem;
        }
        .form-input:focus {
            outline: none;
            border-color: var(--color-primary);
        }
        .form-help {
            font-size: 0.875rem;
            color: var(--color-text-muted);
            margin-top: 0.25rem;
        }
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .btn-primary {
            background-color: var(--color-primary);
            color: white;
        }
        .btn-primary:hover {
            background-color: var(--color-primary-hover);
        }
        .btn-secondary {
            background-color: var(--color-border);
            color: var(--color-text);
        }
        .btn-danger {
            background-color: var(--color-error);
            color: white;
        }
        .btn-full {
            width: 100%;
        }
        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
        }
        .alert-error {
            background-color: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--color-error);
            color: var(--color-error);
        }
        .alert-success {
            background-color: rgba(34, 197, 94, 0.1);
            border: 1px solid var(--color-success);
            color: var(--color-success);
        }
        .requirements-list {
            list-style: none;
        }
        .requirements-list li {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--color-border);
        }
        .status-pass {
            color: var(--color-success);
        }
        .status-fail {
            color: var(--color-error);
        }
        .status-warn {
            color: var(--color-warning);
        }
        .radio-group {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .radio-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }
        .toggle-section {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--color-border);
        }
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }
        code {
            background-color: var(--color-bg);
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            display: block;
            overflow-x: auto;
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        .success-icon {
            font-size: 4rem;
            color: var(--color-success);
            text-align: center;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="installer">
        <div class="logo">
            <div class="logo-icon">&#9653;</div>
            <h1>MeshSilo Installation</h1>
        </div>

        <div class="steps">
            <?php for ($i = 1; $i <= 6; $i++): ?>
            <div class="step-dot <?= $i < $step ? 'completed' : ($i === $step ? 'active' : '') ?>"></div>
            <?php endfor; ?>
        </div>

        <?php if ($error): ?>
        <div role="alert" class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div role="status" class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
        <!-- Step 1: Requirements Check -->
        <h2>System Requirements</h2>
        <ul class="requirements-list">
            <?php foreach ($requirements as $req): ?>
            <li>
                <span><?= htmlspecialchars($req['name']) ?></span>
                <span class="<?= $req['passed'] ? 'status-pass' : (isset($req['optional']) ? 'status-warn' : 'status-fail') ?>">
                    <?= htmlspecialchars($req['current']) ?>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>

        <form method="post">
            <input type="hidden" name="action" value="check_requirements">
            <div class="btn-group">
                <button type="submit" class="btn btn-primary btn-full" <?= $criticalFailed ? 'disabled' : '' ?>>
                    <?= $criticalFailed ? 'Fix Requirements First' : 'Continue' ?>
                </button>
            </div>
        </form>

        <?php elseif ($step === 2): ?>
        <!-- Step 2: Database Configuration -->
        <h2>Database Configuration</h2>
        <form method="post">
            <input type="hidden" name="action" value="configure_database">

            <div class="radio-group">
                <label class="radio-label">
                    <input type="radio" name="db_type" value="mysql" <?= ($_SESSION['install']['db_type'] ?? 'mysql') === 'mysql' ? 'checked' : '' ?> onchange="toggleMySQLFields()">
                    <span>MySQL (Recommended)</span>
                </label>
                <label class="radio-label">
                    <input type="radio" name="db_type" value="sqlite" <?= ($_SESSION['install']['db_type'] ?? 'mysql') === 'sqlite' ? 'checked' : '' ?> onchange="toggleMySQLFields()">
                    <span>SQLite</span>
                </label>
            </div>

            <div id="mysql-fields" style="display: <?= ($_SESSION['install']['db_type'] ?? 'mysql') === 'mysql' ? 'block' : 'none' ?>;">
                <div class="form-group">
                    <label for="db_host">Host</label>
                    <input type="text" id="db_host" name="db_host" class="form-input" value="<?= htmlspecialchars($_SESSION['install']['db_config']['host'] ?? 'localhost') ?>">
                </div>
                <div class="form-group">
                    <label for="db_port">Port</label>
                    <input type="text" id="db_port" name="db_port" class="form-input" value="<?= htmlspecialchars($_SESSION['install']['db_config']['port'] ?? '3306') ?>">
                </div>
                <div class="form-group">
                    <label for="db_name">Database Name</label>
                    <input type="text" id="db_name" name="db_name" class="form-input" value="<?= htmlspecialchars($_SESSION['install']['db_config']['name'] ?? 'silo') ?>">
                </div>
                <div class="form-group">
                    <label for="db_user">Username</label>
                    <input type="text" id="db_user" name="db_user" class="form-input" value="<?= htmlspecialchars($_SESSION['install']['db_config']['user'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="db_pass">Password</label>
                    <input type="password" id="db_pass" name="db_pass" class="form-input">
                </div>
            </div>

            <div class="btn-group">
                <button type="button" class="btn btn-secondary" onclick="goBack(this)">Back</button>
                <button type="submit" class="btn btn-primary">Continue</button>
            </div>
        </form>

        <script>
        function toggleMySQLFields() {
            const mysqlSelected = document.querySelector('input[name="db_type"][value="mysql"]').checked;
            document.getElementById('mysql-fields').style.display = mysqlSelected ? 'block' : 'none';
        }
        </script>

        <?php elseif ($step === 3): ?>
        <!-- Step 3: Admin Account -->
        <h2>Create Admin Account</h2>
        <form method="post">
            <input type="hidden" name="action" value="configure_admin">

            <div class="form-group">
                <label for="admin_username">Username</label>
                <input type="text" id="admin_username" name="admin_username" class="form-input" required
                    value="<?= htmlspecialchars($_SESSION['install']['admin']['username'] ?? '') ?>" autocomplete="username">
            </div>

            <div class="form-group">
                <label for="admin_email">Email</label>
                <input type="email" id="admin_email" name="admin_email" class="form-input" required
                    value="<?= htmlspecialchars($_SESSION['install']['admin']['email'] ?? '') ?>" autocomplete="email">
            </div>

            <div class="form-group">
                <label for="admin_password">Password</label>
                <input type="password" id="admin_password" name="admin_password" class="form-input" required minlength="8" autocomplete="new-password">
                <p class="form-help">Minimum 8 characters</p>
            </div>

            <div class="form-group">
                <label for="admin_password_confirm">Confirm Password</label>
                <input type="password" id="admin_password_confirm" name="admin_password_confirm" class="form-input" required autocomplete="new-password">
            </div>

            <div class="btn-group">
                <button type="button" class="btn btn-secondary" onclick="goBack(this)">Back</button>
                <button type="submit" class="btn btn-primary">Continue</button>
            </div>
        </form>

        <?php elseif ($step === 4): ?>
        <!-- Step 4: Site Configuration -->
        <h2>Site Configuration</h2>
        <form method="post">
            <input type="hidden" name="action" value="configure_site">

            <div class="form-group">
                <label for="site_name">Site Name</label>
                <input type="text" id="site_name" name="site_name" class="form-input"
                    value="<?= htmlspecialchars($_SESSION['install']['site']['name'] ?? 'MeshSilo') ?>">
            </div>

            <div class="form-group">
                <label for="site_description">Site Description</label>
                <input type="text" id="site_description" name="site_description" class="form-input"
                    value="<?= htmlspecialchars($_SESSION['install']['site']['description'] ?? '3D Model Library') ?>">
            </div>

            <div class="form-group">
                <label for="site_url">Site URL (Optional)</label>
                <input type="url" id="site_url" name="site_url" class="form-input" placeholder="https://silo.example.com"
                    value="<?= htmlspecialchars($_SESSION['install']['site']['url'] ?? '') ?>">
                <p class="form-help">Required if using a reverse proxy. Leave blank for auto-detection.</p>
            </div>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="force_url" <?= ($_SESSION['install']['site']['force_url'] ?? '') === '1' ? 'checked' : '' ?>>
                    <span>Only allow access via configured URL</span>
                </label>
                <p class="form-help">Reject requests that don't match the configured URL.</p>
            </div>

            <div class="btn-group">
                <button type="button" class="btn btn-secondary" onclick="goBack(this)">Back</button>
                <button type="submit" class="btn btn-primary">Continue</button>
            </div>
        </form>

        <?php elseif ($step === 5): ?>
        <!-- Step 5: Confirm Installation -->
        <h2>Ready to Install</h2>

        <p style="margin-bottom: 1rem;">Please review your configuration:</p>

        <ul style="list-style: none; margin-bottom: 1.5rem;">
            <li><strong>Database:</strong> <?= ucfirst($_SESSION['install']['db_type']) ?></li>
            <li><strong>Admin:</strong> <?= htmlspecialchars($_SESSION['install']['admin']['username']) ?></li>
            <li><strong>Site Name:</strong> <?= htmlspecialchars($_SESSION['install']['site']['name'] ?? 'MeshSilo') ?></li>
            <?php if (!empty($_SESSION['install']['site']['url'])): ?>
            <li><strong>Site URL:</strong> <?= htmlspecialchars($_SESSION['install']['site']['url']) ?></li>
            <?php endif; ?>
        </ul>

        <form method="post">
            <input type="hidden" name="action" value="install">
            <div class="btn-group">
                <button type="button" class="btn btn-secondary" onclick="goBack(this)">Back</button>
                <button type="submit" class="btn btn-primary">Install MeshSilo</button>
            </div>
        </form>

        <?php elseif ($step === 6): ?>
        <!-- Step 6: Complete -->
        <div class="success-icon">&#10003;</div>
        <h2 style="text-align: center;">Installation Complete!</h2>

        <p style="text-align: center; margin: 1rem 0;">
            MeshSilo has been successfully installed. You can now log in with your admin account.
        </p>

        <div style="background-color: var(--color-bg); padding: 1rem; border-radius: var(--radius); margin: 1rem 0;">
            <p><strong>Web Server Configuration</strong></p>
            <?php $server = detectWebServer(); ?>
            <?php if ($server === 'apache'): ?>
            <p class="form-help">For friendly URLs, create a .htaccess file:</p>
            <code>RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?route=$1 [QSA,L]</code>
            <?php elseif ($server === 'nginx'): ?>
            <p class="form-help">For friendly URLs, add to your nginx config:</p>
            <code>location / {
    try_files $uri $uri/ /index.php?route=$uri&$args;
}</code>
            <?php else: ?>
            <p class="form-help">Server type not detected. Configure URL rewriting manually if needed.</p>
            <?php endif; ?>
        </div>

        <div class="btn-group" style="flex-direction: column; gap: 0.5rem;">
            <a href="/login" class="btn btn-primary btn-full" style="text-align: center; text-decoration: none;">Go to Login</a>
        </div>
        <?php endif; ?>
    </div>
    <script>
    function goBack(btn) {
        const form = btn.closest('form');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'action';
        input.value = 'go_back';
        form.appendChild(input);
        form.submit();
    }
    </script>
</body>
</html>
