#!/usr/bin/env php
<?php
/**
 * Mesh Analysis CLI Tool
 *
 * Usage:
 *   php cli/analyze-meshes.php              # Analyze STL files not yet analyzed
 *   php cli/analyze-meshes.php --limit=50   # Process up to 50 models
 *   php cli/analyze-meshes.php --model=123  # Analyze specific model
 *   php cli/analyze-meshes.php --repair     # Auto-repair issues found
 *   php cli/analyze-meshes.php --dry-run    # Show what would be analyzed
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Change to project root
chdir(__DIR__ . '/..');

// Load configuration
require_once 'includes/config.php';
require_once 'includes/MeshAnalyzer.php';

// Parse arguments
$options = getopt('', ['limit:', 'model:', 'repair', 'dry-run', 'help', 'verbose', 'force']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

$limit = isset($options['limit']) ? (int)$options['limit'] : 100;
$modelId = isset($options['model']) ? (int)$options['model'] : null;
$autoRepair = isset($options['repair']);
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$force = isset($options['force']);

echo "\n";
echo "========================================\n";
echo "  Silo Mesh Analyzer\n";
echo "========================================\n\n";

// Check for admesh
$hasAdmesh = MeshAnalyzer::isAdmeshAvailable();
echo "admesh: " . ($hasAdmesh ? "\033[32mAvailable\033[0m" : "\033[33mNot available (limited analysis)\033[0m") . "\n";
if ($autoRepair && !$hasAdmesh) {
    echo "\033[33mWarning: Auto-repair requires admesh. Install with: apt-get install admesh\033[0m\n";
    $autoRepair = false;
}
echo "\n";

$db = getDB();

// Single model mode
if ($modelId) {
    echo "Analyzing model ID: $modelId\n\n";

    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
    $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $model = $result->fetchArray(PDO::FETCH_ASSOC);

    if (!$model) {
        echo "\033[31mError: Model not found.\033[0m\n";
        exit(1);
    }

    if (strtolower($model['file_type']) !== 'stl') {
        echo "\033[33mSkipping: Not an STL file.\033[0m\n";
        exit(0);
    }

    $filePath = getAbsoluteFilePath($model);
    if (!$filePath || !file_exists($filePath)) {
        echo "\033[31mError: Model file not found.\033[0m\n";
        exit(1);
    }

    if ($dryRun) {
        echo "[DRY RUN] Would analyze: {$model['name']}\n";
        exit(0);
    }

    analyzeAndReport($model, $filePath, $autoRepair, $verbose);
    exit(0);
}

// Batch mode
echo "Batch analyzing STL files...\n";
echo "Limit: $limit models\n";
if ($autoRepair) echo "Auto-repair: \033[32mEnabled\033[0m\n";
if ($dryRun) echo "\033[33m[DRY RUN] No changes will be made.\033[0m\n";
echo "\n";

// Get STL models that haven't been analyzed (or all if force)
$where = "file_type = 'stl'";
if (!$force) {
    $where .= " AND (is_manifold IS NULL)";
}

$stmt = $db->prepare("
    SELECT * FROM models
    WHERE $where
    ORDER BY id DESC
    LIMIT :limit
");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$result = $stmt->execute();

$stats = [
    'analyzed' => 0,
    'manifold' => 0,
    'issues' => 0,
    'repaired' => 0,
    'repair_failed' => 0,
    'skipped' => 0
];

while ($model = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $filePath = getAbsoluteFilePath($model);

    if (!$filePath || !file_exists($filePath)) {
        if ($verbose) echo "  Skipping (file not found): {$model['name']}\n";
        $stats['skipped']++;
        continue;
    }

    if ($dryRun) {
        echo "  Would analyze: {$model['name']}\n";
        $stats['analyzed']++;
        continue;
    }

    $result2 = analyzeAndReport($model, $filePath, $autoRepair, $verbose);

    $stats['analyzed']++;
    if ($result2['is_manifold']) {
        $stats['manifold']++;
    } else {
        $stats['issues']++;
        if ($result2['repaired']) {
            $stats['repaired']++;
        } elseif ($result2['repair_attempted']) {
            $stats['repair_failed']++;
        }
    }
}

echo "\n";
echo "========================================\n";
echo "  Results\n";
echo "========================================\n";
echo "Analyzed:      {$stats['analyzed']} models\n";
echo "Manifold:      \033[32m{$stats['manifold']}\033[0m\n";
echo "With issues:   \033[33m{$stats['issues']}\033[0m\n";
if ($autoRepair) {
    echo "Repaired:      \033[32m{$stats['repaired']}\033[0m\n";
    echo "Repair failed: \033[31m{$stats['repair_failed']}\033[0m\n";
}
echo "Skipped:       {$stats['skipped']}\n";
echo "\n";

function analyzeAndReport($model, $filePath, $autoRepair, $verbose) {
    global $db;

    $result = [
        'is_manifold' => true,
        'repaired' => false,
        'repair_attempted' => false
    ];

    echo "  Analyzing: {$model['name']}... ";

    $analysis = MeshAnalyzer::analyze($filePath);

    if (isset($analysis['error'])) {
        echo "\033[31mError: {$analysis['error']}\033[0m\n";
        return $result;
    }

    $result['is_manifold'] = $analysis['is_manifold'] ?? true;

    // Update database
    MeshAnalyzer::updateModelMeshStatus($model['id'], $analysis);

    if ($result['is_manifold']) {
        echo "\033[32mOK\033[0m";
        if ($verbose && isset($analysis['stats']['facets'])) {
            echo " ({$analysis['stats']['facets']} facets)";
        }
        echo "\n";
    } else {
        $issueCount = count($analysis['issues'] ?? []);
        echo "\033[33m{$issueCount} issue(s)\033[0m\n";

        if ($verbose) {
            foreach ($analysis['issues'] ?? [] as $issue) {
                echo "    - {$issue['message']}\n";
            }
        }

        // Attempt repair if enabled
        if ($autoRepair && ($analysis['can_repair'] ?? false)) {
            echo "    Attempting repair... ";
            $result['repair_attempted'] = true;

            $repairResult = MeshAnalyzer::repair($filePath);

            if ($repairResult['success']) {
                echo "\033[32mSuccess\033[0m\n";
                $result['repaired'] = true;

                // Re-analyze and update
                $newAnalysis = MeshAnalyzer::analyze($filePath);
                MeshAnalyzer::updateModelMeshStatus($model['id'], $newAnalysis);
            } else {
                echo "\033[31mFailed\033[0m\n";
                if ($verbose) {
                    echo "    Error: {$repairResult['error']}\n";
                }
            }
        }
    }

    return $result;
}

function showHelp() {
    echo <<<HELP
Silo Mesh Analyzer

Usage:
  php cli/analyze-meshes.php [options]

Options:
  --limit=N     Process up to N models (default: 100)
  --model=ID    Analyze specific model ID
  --repair      Automatically repair issues found (requires admesh)
  --force       Re-analyze models that were already analyzed
  --dry-run     Show what would be analyzed without making changes
  --verbose     Show detailed output
  --help        Show this help message

Examples:
  php cli/analyze-meshes.php
  php cli/analyze-meshes.php --limit=50 --repair
  php cli/analyze-meshes.php --model=123 --verbose
  php cli/analyze-meshes.php --dry-run

Notes:
  - Only STL files are analyzed
  - Install admesh for detailed analysis: apt-get install admesh
  - Without admesh, only basic file validation is performed

HELP;
}
