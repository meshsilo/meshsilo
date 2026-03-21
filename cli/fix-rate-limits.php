#!/usr/bin/env php
<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("This script must be run from the command line.\n");
}
/**
 * Emergency Rate Limits Table Fix
 *
 * Fixes the rate_limits table schema to match RateLimitMiddleware requirements.
 * Run via: php cli/fix-rate-limits.php
 * Or in Docker: docker exec meshsilo php cli/fix-rate-limits.php
 */

// Change to project root
chdir(dirname(__DIR__));

// Load dependencies
require_once 'includes/config.php';
require_once 'includes/Schema.php';

echo "Rate Limits Table Fix\n";
echo "======================\n\n";

try {
    $db = getDB();
    $type = $db->getType();

    echo "Database type: $type\n";
    echo "Recreating rate_limits table with correct schema...\n";

    Schema::recreateTable($db, 'rate_limits');

    echo "\n✓ Rate limits table fixed successfully!\n";
    echo "\nYou can now access the update page at /update\n";

} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
