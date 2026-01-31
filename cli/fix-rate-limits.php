#!/usr/bin/env php
<?php
/**
 * Emergency Rate Limits Table Fix
 *
 * Fixes the rate_limits table schema to match RateLimitMiddleware requirements.
 * Run via: php cli/fix-rate-limits.php
 * Or in Docker: docker exec meshsilo php cli/fix-rate-limits.php
 */

// Change to project root
chdir(dirname(__DIR__));

// Load the database connection
require_once 'includes/config.php';

echo "Rate Limits Table Fix\n";
echo "======================\n\n";

try {
    $db = getDB();
    $type = $db->getType();

    echo "Database type: $type\n";
    echo "Dropping old rate_limits table...\n";

    $db->exec('DROP TABLE IF EXISTS rate_limits');

    echo "Creating new rate_limits table with correct schema...\n";

    if ($type === 'mysql') {
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

    echo "\n✓ Rate limits table fixed successfully!\n";
    echo "\nYou can now access the update page at /update\n";

} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
