<?php
// Database connection helper

function getDB() {
    static $db = null;

    if ($db === null) {
        $dbPath = DB_PATH;
        $dbExists = file_exists($dbPath);

        try {
            $db = new SQLite3($dbPath);
            $db->enableExceptions(true);

            // Initialize database if it doesn't exist
            if (!$dbExists) {
                $schema = file_get_contents(__DIR__ . '/../db/schema.sql');
                $db->exec($schema);
                logInfo('Database initialized', ['path' => $dbPath]);
            }

            // Run migrations
            runMigrations($db);
        } catch (Exception $e) {
            logException($e, ['action' => 'database_connect', 'path' => $dbPath]);
            throw $e;
        }
    }

    return $db;
}

// Get user by username or email
function getUserByLogin($login) {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM users WHERE username = :login OR email = :login');
        $stmt->bindValue(':login', $login, SQLITE3_TEXT);
        $result = $stmt->execute();
        return $result->fetchArray(SQLITE3_ASSOC);
    } catch (Exception $e) {
        logException($e, ['action' => 'get_user_by_login', 'login' => $login]);
        return null;
    }
}

// Verify password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Run database migrations
function runMigrations($db) {
    // Check if permissions column exists in users table
    $result = $db->query("PRAGMA table_info(users)");
    $hasPermissions = false;
    while ($col = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($col['name'] === 'permissions') {
            $hasPermissions = true;
            break;
        }
    }

    if (!$hasPermissions) {
        $db->exec('ALTER TABLE users ADD COLUMN permissions TEXT');
        logInfo('Migration: Added permissions column to users table');
    }

    // Check for multi-part model columns
    $result = $db->query("PRAGMA table_info(models)");
    $hasParentId = false;
    $hasOriginalPath = false;
    $hasPartCount = false;
    while ($col = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($col['name'] === 'parent_id') $hasParentId = true;
        if ($col['name'] === 'original_path') $hasOriginalPath = true;
        if ($col['name'] === 'part_count') $hasPartCount = true;
    }

    if (!$hasParentId) {
        $db->exec('ALTER TABLE models ADD COLUMN parent_id INTEGER REFERENCES models(id) ON DELETE CASCADE');
        logInfo('Migration: Added parent_id column to models table');
    }
    if (!$hasOriginalPath) {
        $db->exec('ALTER TABLE models ADD COLUMN original_path TEXT');
        logInfo('Migration: Added original_path column to models table');
    }
    if (!$hasPartCount) {
        $db->exec('ALTER TABLE models ADD COLUMN part_count INTEGER DEFAULT 0');
        logInfo('Migration: Added part_count column to models table');
    }

    // Check if author column needs to be renamed to creator
    $result = $db->query("PRAGMA table_info(models)");
    $hasAuthor = false;
    $hasCreator = false;
    while ($col = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($col['name'] === 'author') $hasAuthor = true;
        if ($col['name'] === 'creator') $hasCreator = true;
    }

    if ($hasAuthor && !$hasCreator) {
        $db->exec('ALTER TABLE models RENAME COLUMN author TO creator');
        logInfo('Migration: Renamed author column to creator in models table');
    }

    // Check if print_type column exists
    $result = $db->query("PRAGMA table_info(models)");
    $hasPrintType = false;
    while ($col = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($col['name'] === 'print_type') $hasPrintType = true;
    }

    if (!$hasPrintType) {
        $db->exec('ALTER TABLE models ADD COLUMN print_type TEXT'); // 'fdm', 'sla', or NULL
        logInfo('Migration: Added print_type column to models table');
    }

    // Create groups table if it doesn't exist
    $db->exec('
        CREATE TABLE IF NOT EXISTS groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT,
            permissions TEXT,
            is_system INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    // Create user_groups junction table if it doesn't exist
    $db->exec('
        CREATE TABLE IF NOT EXISTS user_groups (
            user_id INTEGER,
            group_id INTEGER,
            PRIMARY KEY (user_id, group_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
        )
    ');

    // Create default Admin group if it doesn't exist
    $result = $db->query("SELECT id FROM groups WHERE name = 'Admin'");
    if (!$result->fetchArray()) {
        $adminPerms = json_encode(['upload', 'delete', 'edit', 'admin', 'view_stats']);
        $stmt = $db->prepare("INSERT INTO groups (name, description, permissions, is_system) VALUES ('Admin', 'Full system access', :perms, 1)");
        $stmt->bindValue(':perms', $adminPerms, SQLITE3_TEXT);
        $stmt->execute();
        logInfo('Migration: Created Admin group');

        // Assign existing admin users to Admin group
        $adminGroupId = $db->lastInsertRowID();
        $db->exec("INSERT OR IGNORE INTO user_groups (user_id, group_id) SELECT id, $adminGroupId FROM users WHERE is_admin = 1");
        logInfo('Migration: Assigned admin users to Admin group');
    }

    // Create default Users group if it doesn't exist
    $result = $db->query("SELECT id FROM groups WHERE name = 'Users'");
    if (!$result->fetchArray()) {
        $userPerms = json_encode(['upload', 'view_stats']);
        $stmt = $db->prepare("INSERT INTO groups (name, description, permissions, is_system) VALUES ('Users', 'Default user permissions', :perms, 1)");
        $stmt->bindValue(':perms', $userPerms, SQLITE3_TEXT);
        $stmt->execute();
        logInfo('Migration: Created Users group');
    }

    // Check if original_size column exists for conversion tracking
    $result = $db->query("PRAGMA table_info(models)");
    $hasOriginalSize = false;
    while ($col = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($col['name'] === 'original_size') $hasOriginalSize = true;
    }

    if (!$hasOriginalSize) {
        $db->exec('ALTER TABLE models ADD COLUMN original_size INTEGER'); // Original size before conversion
        logInfo('Migration: Added original_size column to models table');
    }

    // Check if file_hash column exists
    $result = $db->query("PRAGMA table_info(models)");
    $hasFileHash = false;
    while ($col = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($col['name'] === 'file_hash') $hasFileHash = true;
    }

    if (!$hasFileHash) {
        $db->exec('ALTER TABLE models ADD COLUMN file_hash TEXT'); // SHA256 hash for deduplication
        logInfo('Migration: Added file_hash column to models table');
    }

    // Create settings table if it doesn't exist
    $db->exec('
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    // Initialize default settings
    $defaultSettings = [
        'auto_convert_stl' => '0',
        'site_name' => 'Silo',
        'site_description' => 'Your 3D Model Library',
        'models_per_page' => '20',
        'allow_registration' => '1',
        'require_approval' => '0'
    ];

    foreach ($defaultSettings as $key => $value) {
        $stmt = $db->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:key, :value)');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
        $stmt->execute();
    }
}

// Get a setting value
function getSetting($key, $default = null) {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row ? $row['value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

// Set a setting value
function setSetting($key, $value) {
    try {
        $db = getDB();
        $stmt = $db->prepare('INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (:key, :value, CURRENT_TIMESTAMP)');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
        return $stmt->execute();
    } catch (Exception $e) {
        logException($e, ['action' => 'set_setting', 'key' => $key]);
        return false;
    }
}

// Get all settings
function getAllSettings() {
    try {
        $db = getDB();
        $result = $db->query('SELECT key, value FROM settings');
        $settings = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $settings[$row['key']] = $row['value'];
        }
        return $settings;
    } catch (Exception $e) {
        return [];
    }
}
