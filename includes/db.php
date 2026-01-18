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
