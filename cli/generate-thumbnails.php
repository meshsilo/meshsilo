#!/usr/bin/env php
<?php
/**
 * Thumbnail Generation CLI Tool
 *
 * Usage:
 *   php cli/generate-thumbnails.php              # Generate missing thumbnails
 *   php cli/generate-thumbnails.php --limit=50   # Process up to 50 models
 *   php cli/generate-thumbnails.php --model=123  # Generate for specific model
 *   php cli/generate-thumbnails.php --dry-run    # Show what would be processed
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Change to project root
chdir(__DIR__ . '/..');

// Load configuration
require_once 'includes/config.php';
require_once 'includes/ThumbnailGenerator.php';

// Parse arguments
$options = getopt('', ['limit:', 'model:', 'dry-run', 'help', 'verbose', 'force']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

$limit = isset($options['limit']) ? (int)$options['limit'] : 100;
$modelId = isset($options['model']) ? (int)$options['model'] : null;
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$force = isset($options['force']);

echo "\n";
echo "========================================\n";
echo "  Silo Thumbnail Generator\n";
echo "========================================\n\n";

// Check for GD library
if (!extension_loaded('gd')) {
    echo "\033[31mError: GD library is not available.\033[0m\n";
    echo "Install with: apt-get install php-gd\n";
    exit(1);
}

// Check for OpenSCAD (optional)
$hasOpenSCAD = ThumbnailGenerator::isOpenSCADAvailable();
echo "GD Library: \033[32mAvailable\033[0m\n";
echo "OpenSCAD: " . ($hasOpenSCAD ? "\033[32mAvailable\033[0m" : "\033[33mNot available (STL rendering disabled)\033[0m") . "\n\n";

$db = getDB();

// Single model mode
if ($modelId) {
    echo "Processing model ID: $modelId\n\n";

    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
    $stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $model = $result->fetchArray(SQLITE3_ASSOC);

    if (!$model) {
        echo "\033[31mError: Model not found.\033[0m\n";
        exit(1);
    }

    if (!$dryRun) {
        $thumbnail = ThumbnailGenerator::generateThumbnail($model);
        if ($thumbnail) {
            echo "\033[32mSuccess:\033[0m Generated thumbnail: $thumbnail\n";
        } else {
            echo "\033[31mFailed:\033[0m Could not generate thumbnail\n";
        }
    } else {
        echo "[DRY RUN] Would generate thumbnail for: {$model['name']}\n";
    }

    exit(0);
}

// Batch mode
echo "Batch generating thumbnails...\n";
echo "Limit: $limit models\n";
if ($dryRun) {
    echo "\033[33m[DRY RUN] No changes will be made.\033[0m\n";
}
echo "\n";

if ($dryRun) {
    // Just show what would be processed
    $where = "(thumbnail_path IS NULL OR thumbnail_path = '')";
    if (!$force) {
        $where .= " AND parent_id IS NULL AND file_type IN ('3mf', 'stl')";
    }

    $stmt = $db->prepare("
        SELECT id, name, file_type
        FROM models
        WHERE $where
        ORDER BY CASE WHEN file_type = '3mf' THEN 0 ELSE 1 END, id DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $count = 0;
    while ($model = $result->fetchArray(SQLITE3_ASSOC)) {
        $count++;
        echo "  Would process: [{$model['file_type']}] {$model['name']}\n";
    }

    echo "\nTotal: $count models would be processed\n";
    exit(0);
}

// Run batch generation
$startTime = microtime(true);
$results = ThumbnailGenerator::batchGenerate($limit);
$elapsed = round(microtime(true) - $startTime, 2);

echo "\n";
echo "========================================\n";
echo "  Results\n";
echo "========================================\n";
echo "Processed: {$results['processed']} models\n";
echo "Success:   \033[32m{$results['success']}\033[0m\n";
echo "Failed:    \033[31m{$results['failed']}\033[0m\n";
echo "Time:      {$elapsed}s\n";
echo "\n";

function showHelp() {
    echo <<<HELP
Silo Thumbnail Generator

Usage:
  php cli/generate-thumbnails.php [options]

Options:
  --limit=N     Process up to N models (default: 100)
  --model=ID    Generate thumbnail for specific model ID
  --dry-run     Show what would be processed without making changes
  --force       Include all file types, not just 3MF/STL
  --verbose     Show detailed output
  --help        Show this help message

Examples:
  php cli/generate-thumbnails.php
  php cli/generate-thumbnails.php --limit=50
  php cli/generate-thumbnails.php --model=123
  php cli/generate-thumbnails.php --dry-run

Notes:
  - 3MF files are prioritized as they often contain embedded thumbnails
  - STL files require OpenSCAD for thumbnail generation
  - Thumbnails are stored in assets/thumbnails/

HELP;
}
