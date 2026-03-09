#!/usr/bin/env php
<?php
/**
 * CLI script for running deduplication
 *
 * NOTE: This task runs automatically via the unified cron. Manual use only when needed.
 * For scheduled execution, use: php cli/cron.php (runs dedup:scan task daily at 1am)
 *
 * Usage:
 *   php cli/dedup.php              # Run deduplication
 *   php cli/dedup.php --dry-run    # Show what would be deduplicated without making changes
 *   php cli/dedup.php --force      # Run even if auto_deduplication is disabled
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Change to project root
chdir(__DIR__ . '/..');

require_once 'includes/config.php';
require_once 'includes/dedup.php';

// Parse arguments
$options = getopt('', ['dry-run', 'force', 'help']);

if (isset($options['help'])) {
    echo "Silo Deduplication CLI\n";
    echo "======================\n\n";
    echo "Usage: php cli/dedup.php [options]\n\n";
    echo "Options:\n";
    echo "  --dry-run    Show what would be deduplicated without making changes\n";
    echo "  --force      Run even if auto_deduplication is disabled in settings\n";
    echo "  --help       Show this help message\n\n";
    exit(0);
}

$dryRun = isset($options['dry-run']);
$force = isset($options['force']);

// Check if auto-deduplication is enabled (unless --force)
if (!$force && getSetting('auto_deduplication', '0') !== '1') {
    echo "[" . date('Y-m-d H:i:s') . "] Auto-deduplication is disabled in settings. Use --force to override.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting deduplication" . ($dryRun ? " (DRY RUN)" : "") . "...\n";

$db = getDB();

// First, calculate any missing hashes
echo "[" . date('Y-m-d H:i:s') . "] Checking for missing hashes...\n";
$hashResult = calculateMissingHashes();
if ($hashResult['calculated'] > 0) {
    echo "[" . date('Y-m-d H:i:s') . "] Calculated {$hashResult['calculated']} missing hashes ({$hashResult['errors']} errors)\n";
}

// Find duplicate files (not yet deduplicated)
echo "[" . date('Y-m-d H:i:s') . "] Finding duplicate files...\n";
$duplicates = findDuplicateHashes();

if (empty($duplicates)) {
    echo "[" . date('Y-m-d H:i:s') . "] No duplicate files found.\n";
    if (!$dryRun) {
        setSetting('last_deduplication', date('Y-m-d H:i:s'));
    }
    exit(0);
}

$totalDupes = array_sum(array_column($duplicates, 'count'));
$totalSaved = 0;
$totalDeleted = 0;
$errors = 0;

echo "[" . date('Y-m-d H:i:s') . "] Found " . count($duplicates) . " duplicate sets ({$totalDupes} total files)\n";

foreach ($duplicates as $dup) {
    $hash = $dup['file_hash'];

    if ($dryRun) {
        // Estimate savings: (count - 1) * average file size
        $avgSize = $dup['total_size'] / $dup['count'];
        $savings = (int)(($dup['count'] - 1) * $avgSize);
        echo "[" . date('Y-m-d H:i:s') . "] Would deduplicate hash {$hash}: {$dup['count']} files, ~" . formatBytes($savings) . " savings\n";
        $totalSaved += $savings;
        $totalDeleted += $dup['count'] - 1;
    } else {
        $result = deduplicateByHash($hash);
        if ($result['success']) {
            $totalSaved += $result['space_saved'];
            $totalDeleted += $result['files_deleted'];
            echo "[" . date('Y-m-d H:i:s') . "] Deduplicated hash {$hash}: {$result['files_deleted']} files, " . formatBytes($result['space_saved']) . " saved\n";
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] Error for hash {$hash}: {$result['error']}\n";
            $errors++;
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Deduplication complete.\n";
echo "[" . date('Y-m-d H:i:s') . "] Files " . ($dryRun ? "that would be " : "") . "deduplicated: {$totalDeleted}\n";
echo "[" . date('Y-m-d H:i:s') . "] Space " . ($dryRun ? "that would be " : "") . "saved: " . formatBytes($totalSaved) . "\n";
if ($errors > 0) {
    echo "[" . date('Y-m-d H:i:s') . "] Errors: {$errors}\n";
}

// Run cleanup - migrate back any files with only one reference
if (!$dryRun) {
    $cleanup = runDedupCleanupScan();
    if ($cleanup['migrated'] > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] Cleaned up {$cleanup['migrated']} single-reference dedup files\n";
    }
    setSetting('last_deduplication', date('Y-m-d H:i:s'));
}

exit($errors > 0 ? 1 : 0);
