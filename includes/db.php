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
}
