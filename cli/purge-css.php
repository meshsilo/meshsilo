<?php
/**
 * CSS Purger CLI
 *
 * Usage:
 *   php cli/purge-css.php --analyze    Show unused CSS statistics
 *   php cli/purge-css.php --purge      Generate purged CSS file
 *   php cli/purge-css.php --list       List unused selectors
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/CssPurger.php';

$options = getopt('', ['analyze', 'purge', 'list', 'help']);

if (isset($options['help']) || empty($options)) {
    showHelp();
    exit(0);
}

$purger = new CssPurger();

if (isset($options['analyze']) || isset($options['list'])) {
    echo "Analyzing CSS usage...\n\n";

    $analysis = $purger->analyze();

    echo "=== CSS Analysis Results ===\n";
    echo "Total selectors:  " . $analysis['total_selectors'] . "\n";
    echo "Used selectors:   " . $analysis['used_selectors'] . "\n";
    echo "Unused selectors: " . $analysis['unused_selectors'] . "\n";
    echo "Potential savings: " . $analysis['potential_savings'] . "\n";

    if (isset($options['list']) && !empty($analysis['unused_list'])) {
        echo "\n=== Unused Selectors ===\n";
        foreach (array_slice($analysis['unused_list'], 0, 50) as $selector) {
            echo "  - $selector\n";
        }
        if (count($analysis['unused_list']) > 50) {
            echo "  ... and " . (count($analysis['unused_list']) - 50) . " more\n";
        }
    }

    echo "\nRun with --purge to generate optimized CSS\n";
}

if (isset($options['purge'])) {
    echo "Purging unused CSS...\n\n";

    $result = $purger->purge();

    echo "=== CSS Purge Results ===\n";
    echo "Original size: " . formatBytes($result['original_size']) . "\n";
    echo "Purged size:   " . formatBytes($result['purged_size']) . "\n";
    echo "Savings:       " . formatBytes($result['savings']) . " ({$result['savings_percent']}%)\n";
    echo "\nOutput: {$result['output_file']}\n";

    echo "\nTo use purged CSS, update header.php to load style.purged.css\n";
}

function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

function showHelp(): void {
    echo "MeshSilo CSS Purger\n\n";
    echo "Usage: php cli/purge-css.php [options]\n\n";
    echo "Options:\n";
    echo "  --analyze    Show CSS usage statistics\n";
    echo "  --list       List unused selectors\n";
    echo "  --purge      Generate purged CSS file\n";
    echo "  --help       Show this help message\n";
}
