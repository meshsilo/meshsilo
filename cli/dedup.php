#!/usr/bin/env php
<?php
/**
 * CLI script for running deduplication
 *
 * Usage:
 *   php cli/dedup.php              # Run deduplication
 *   php cli/dedup.php --dry-run    # Show what would be deduplicated without making changes
 *   php cli/dedup.php --force      # Run even if auto_deduplication is disabled
 *
 * Add to crontab for scheduled execution:
 *   0 2 * * * cd /path/to/silo && php cli/dedup.php >> logs/dedup.log 2>&1
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

// Find duplicate files
echo "[" . date('Y-m-d H:i:s') . "] Finding duplicate files...\n";
$dupeResult = $db->query('
    SELECT file_hash, COUNT(*) as count, SUM(file_size) as total_size
    FROM models
    WHERE file_hash IS NOT NULL
      AND dedup_path IS NULL
    GROUP BY file_hash
    HAVING COUNT(*) > 1
');

$duplicateSets = [];
while ($row = $dupeResult->fetchArray(PDO::FETCH_ASSOC)) {
    $duplicateSets[] = $row;
}

if (empty($duplicateSets)) {
    echo "[" . date('Y-m-d H:i:s') . "] No duplicate files found.\n";
    if (!$dryRun) {
        setSetting('last_deduplication', date('Y-m-d H:i:s'));
    }
    exit(0);
}

$totalDupes = array_sum(array_column($duplicateSets, 'count'));
$potentialSavings = 0;
$filesDeduped = 0;
$errors = 0;

echo "[" . date('Y-m-d H:i:s') . "] Found " . count($duplicateSets) . " duplicate sets ({$totalDupes} total files)\n";

foreach ($duplicateSets as $set) {
    $hash = $set['file_hash'];

    // Get all files with this hash
    $stmt = $db->prepare('
        SELECT id, file_path, file_size, dedup_path
        FROM models
        WHERE file_hash = :hash
          AND dedup_path IS NULL
        ORDER BY id ASC
    ');
    $stmt->bindValue(':hash', $hash, PDO::PARAM_STR);
    $result = $stmt->execute();

    $files = [];
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $files[] = $row;
    }

    if (count($files) < 2) {
        continue;
    }

    // First file becomes the canonical copy
    $canonical = $files[0];
    $canonicalPath = UPLOAD_PATH . $canonical['file_path'];

    if (!file_exists($canonicalPath)) {
        echo "[" . date('Y-m-d H:i:s') . "] Warning: Canonical file missing: {$canonical['file_path']}\n";
        $errors++;
        continue;
    }

    // Deduplicate remaining files
    for ($i = 1; $i < count($files); $i++) {
        $dupe = $files[$i];
        $dupePath = UPLOAD_PATH . $dupe['file_path'];

        if (!file_exists($dupePath)) {
            continue;
        }

        if ($dryRun) {
            echo "[" . date('Y-m-d H:i:s') . "] Would deduplicate: {$dupe['file_path']} -> {$canonical['file_path']}\n";
            $potentialSavings += $dupe['file_size'];
            $filesDeduped++;
        } else {
            // Update database to point to canonical file
            $updateStmt = $db->prepare('UPDATE models SET dedup_path = :dedup_path WHERE id = :id');
            $updateStmt->bindValue(':dedup_path', $canonical['file_path'], PDO::PARAM_STR);
            $updateStmt->bindValue(':id', $dupe['id'], PDO::PARAM_INT);

            if ($updateStmt->execute()) {
                // Delete the duplicate file
                if (unlink($dupePath)) {
                    $potentialSavings += $dupe['file_size'];
                    $filesDeduped++;

                    // Try to remove empty directories
                    $dir = dirname($dupePath);
                    if (is_dir($dir) && count(scandir($dir)) == 2) {
                        rmdir($dir);
                    }
                } else {
                    echo "[" . date('Y-m-d H:i:s') . "] Error: Could not delete {$dupe['file_path']}\n";
                    $errors++;
                }
            } else {
                echo "[" . date('Y-m-d H:i:s') . "] Error: Database update failed for {$dupe['file_path']}\n";
                $errors++;
            }
        }
    }
}

// Format size
function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
}

echo "[" . date('Y-m-d H:i:s') . "] Deduplication complete.\n";
echo "[" . date('Y-m-d H:i:s') . "] Files " . ($dryRun ? "that would be" : "") . " deduplicated: {$filesDeduped}\n";
echo "[" . date('Y-m-d H:i:s') . "] Space " . ($dryRun ? "that would be" : "") . " saved: " . formatBytes($potentialSavings) . "\n";
if ($errors > 0) {
    echo "[" . date('Y-m-d H:i:s') . "] Errors: {$errors}\n";
}

// Update last run time
if (!$dryRun) {
    setSetting('last_deduplication', date('Y-m-d H:i:s'));
}

exit($errors > 0 ? 1 : 0);
