#!/usr/bin/env php
<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("This script must be run from the command line.\n");
}
/**
 * Configuration Validation CLI
 *
 * Validates application configuration and reports issues.
 * Run via: php cli/validate-config.php
 * Or in Docker: docker exec meshsilo php cli/validate-config.php
 */

// Change to project root
chdir(dirname(__DIR__));

// Load configuration
require_once 'includes/config.php';
require_once 'includes/ConfigValidator.php';
require_once 'includes/features.php';

echo "Configuration Validation\n";
echo "========================\n\n";

$validator = new ConfigValidator();
$valid = $validator->validate();
$summary = $validator->getSummary();

// Display errors
if (!empty($summary['errors'])) {
    echo "ERRORS ({$summary['error_count']}):\n";
    foreach ($summary['errors'] as $error) {
        echo "  ✗ {$error}\n";
    }
    echo "\n";
}

// Display warnings
if (!empty($summary['warnings'])) {
    echo "WARNINGS ({$summary['warning_count']}):\n";
    foreach ($summary['warnings'] as $warning) {
        echo "  ⚠ {$warning}\n";
    }
    echo "\n";
}

// Summary
if ($valid && empty($summary['warnings'])) {
    echo "✓ All configuration checks passed!\n";
    exit(0);
} elseif ($valid) {
    echo "✓ Configuration is valid with {$summary['warning_count']} warning(s).\n";
    exit(0);
} else {
    echo "✗ Configuration has {$summary['error_count']} error(s).\n";
    exit(1);
}
