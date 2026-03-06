<?php

/**
 * Database Schema Definitions
 *
 * Centralized schema definitions for database tables.
 * Used by migrations, CLI tools, and schema repair scripts.
 */

class Schema
{
    /**
     * Get the rate_limits table schema
     */
    public static function getRateLimitsSchema(string $dbType): array
    {
        if ($dbType === 'mysql') {
            return [
                'create' => 'CREATE TABLE rate_limits (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    key_name VARCHAR(255) NOT NULL UNIQUE,
                    data TEXT,
                    expires_at INT NOT NULL,
                    INDEX idx_rate_limits_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'indexes' => []
            ];
        } else {
            return [
                'create' => 'CREATE TABLE rate_limits (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    key_name TEXT NOT NULL UNIQUE,
                    data TEXT,
                    expires_at INTEGER NOT NULL
                )',
                'indexes' => [
                    'CREATE INDEX idx_rate_limits_expires ON rate_limits(expires_at)'
                ]
            ];
        }
    }

    /**
     * Get the sessions table schema
     */
    public static function getSessionsSchema(string $dbType): array
    {
        if ($dbType === 'mysql') {
            return [
                'create' => 'CREATE TABLE sessions (
                    id VARCHAR(128) PRIMARY KEY,
                    user_id INT DEFAULT NULL,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    payload TEXT NOT NULL,
                    last_activity INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_sessions_user (user_id),
                    INDEX idx_sessions_activity (last_activity)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'indexes' => []
            ];
        } else {
            return [
                'create' => 'CREATE TABLE sessions (
                    id TEXT PRIMARY KEY,
                    user_id INTEGER DEFAULT NULL,
                    ip_address TEXT,
                    user_agent TEXT,
                    payload TEXT NOT NULL,
                    last_activity INTEGER NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )',
                'indexes' => [
                    'CREATE INDEX idx_sessions_user ON sessions(user_id)',
                    'CREATE INDEX idx_sessions_activity ON sessions(last_activity)'
                ]
            ];
        }
    }

    /**
     * Get the activity_log table schema
     */
    public static function getActivityLogSchema(string $dbType): array
    {
        if ($dbType === 'mysql') {
            return [
                'create' => 'CREATE TABLE activity_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT DEFAULT NULL,
                    action VARCHAR(100) NOT NULL,
                    entity_type VARCHAR(50),
                    entity_id INT,
                    entity_name VARCHAR(255),
                    details TEXT,
                    ip_address VARCHAR(45),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_activity_log_user (user_id),
                    INDEX idx_activity_log_action (action),
                    INDEX idx_activity_log_entity (entity_type, entity_id),
                    INDEX idx_activity_log_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'indexes' => []
            ];
        } else {
            return [
                'create' => 'CREATE TABLE activity_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER DEFAULT NULL,
                    action TEXT NOT NULL,
                    entity_type TEXT,
                    entity_id INTEGER,
                    entity_name TEXT,
                    details TEXT,
                    ip_address TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )',
                'indexes' => [
                    'CREATE INDEX idx_activity_log_user ON activity_log(user_id)',
                    'CREATE INDEX idx_activity_log_action ON activity_log(action)',
                    'CREATE INDEX idx_activity_log_entity ON activity_log(entity_type, entity_id)',
                    'CREATE INDEX idx_activity_log_created ON activity_log(created_at)'
                ]
            ];
        }
    }

    /**
     * Create a table from schema definition
     */
    public static function createTable($db, string $tableName): void
    {
        $dbType = $db->getType();
        $methodName = 'get' . str_replace('_', '', ucwords($tableName, '_')) . 'Schema';

        if (!method_exists(self::class, $methodName)) {
            throw new Exception("Unknown table schema: {$tableName}");
        }

        $schema = self::$methodName($dbType);

        // Create main table
        $db->exec($schema['create']);

        // Create indexes
        foreach ($schema['indexes'] as $indexSql) {
            $db->exec($indexSql);
        }
    }

    /**
     * Recreate a table (drop and create)
     */
    public static function recreateTable($db, string $tableName): void
    {
        $db->exec("DROP TABLE IF EXISTS {$tableName}");
        self::createTable($db, $tableName);
    }

    /**
     * Get list of known table names
     */
    public static function getKnownTables(): array
    {
        return [
            'rate_limits',
            'sessions',
            'activity_log',
        ];
    }
}
