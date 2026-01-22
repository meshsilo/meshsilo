#!/usr/bin/env php
<?php
/**
 * Scheduler CLI Script
 *
 * Run scheduled tasks from the command line.
 *
 * Usage:
 *   php cli/scheduler.php              Run all due tasks
 *   php cli/scheduler.php --list       List all registered tasks
 *   php cli/scheduler.php --run <name> Run a specific task
 *   php cli/scheduler.php --history    Show task run history
 *
 * Cron setup (run every minute):
 *   * * * * * php /path/to/silo/cli/scheduler.php >> /path/to/silo/logs/scheduler.log 2>&1
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Change to project root directory
chdir(__DIR__ . '/..');

// Load application
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Scheduler.php';

// Parse command line arguments
$options = getopt('', ['list', 'run:', 'history', 'help', 'verbose']);
$verbose = isset($options['verbose']);

/**
 * Output message
 */
function output(string $message, bool $verbose = false): void {
    global $options;
    if ($verbose && !isset($options['verbose'])) {
        return;
    }
    echo date('[Y-m-d H:i:s] ') . $message . "\n";
}

// Show help
if (isset($options['help']) || (isset($argv[1]) && $argv[1] === '--help')) {
    echo <<<HELP
Silo Scheduler CLI

Usage:
  php cli/scheduler.php [options]

Options:
  --list        List all registered tasks
  --run <name>  Run a specific task by name
  --history     Show recent task run history
  --verbose     Show detailed output
  --help        Show this help message

Examples:
  php cli/scheduler.php                    Run all due tasks
  php cli/scheduler.php --list             Show all tasks
  php cli/scheduler.php --run cleanup:logs Run the log cleanup task
  php cli/scheduler.php --history          Show run history

Cron setup (recommended):
  * * * * * php /path/to/silo/cli/scheduler.php >> /path/to/silo/logs/scheduler.log 2>&1

HELP;
    exit(0);
}

// List tasks
if (isset($options['list'])) {
    $tasks = Scheduler::getTasks();

    echo "\nRegistered Tasks:\n";
    echo str_repeat('-', 80) . "\n";
    printf("%-30s %-15s %-8s %s\n", "Name", "Schedule", "Enabled", "Description");
    echo str_repeat('-', 80) . "\n";

    foreach ($tasks as $name => $task) {
        $nextRun = Scheduler::getNextRun($task['schedule']);
        $nextRunStr = $nextRun ? date('Y-m-d H:i', $nextRun) : 'N/A';

        printf(
            "%-30s %-15s %-8s %s\n",
            $name,
            $task['schedule'],
            $task['enabled'] ? 'Yes' : 'No',
            $task['description'] ?: '-'
        );

        if ($verbose) {
            printf("  Next run: %s\n", $nextRunStr);
        }
    }

    echo "\nTotal: " . count($tasks) . " tasks\n\n";
    exit(0);
}

// Show history
if (isset($options['history'])) {
    $history = Scheduler::getHistory(20);

    echo "\nRecent Task History:\n";
    echo str_repeat('-', 100) . "\n";
    printf("%-25s %-30s %-10s %-10s %s\n", "Time", "Task", "Status", "Duration", "Output");
    echo str_repeat('-', 100) . "\n";

    foreach ($history as $entry) {
        $output = $entry['output'] ? substr($entry['output'], 0, 30) : '-';
        printf(
            "%-25s %-30s %-10s %-10s %s\n",
            $entry['created_at'],
            $entry['task_name'],
            $entry['status'],
            $entry['duration_ms'] ? $entry['duration_ms'] . 'ms' : '-',
            $output
        );
    }

    if (empty($history)) {
        echo "No task history found.\n";
    }

    echo "\n";
    exit(0);
}

// Run specific task
if (isset($options['run'])) {
    $taskName = $options['run'];
    output("Running task: {$taskName}");

    $result = Scheduler::runTask($taskName);

    if ($result['status'] === 'success') {
        output("Task completed successfully in {$result['duration_ms']}ms");
        if (!empty($result['output'])) {
            output("Output: {$result['output']}", true);
        }
        exit(0);
    } else {
        output("Task failed: " . ($result['error'] ?? $result['reason'] ?? 'Unknown error'));
        exit(1);
    }
}

// Default: run all due tasks
output("Starting scheduler run...", true);

$results = Scheduler::run();

if (empty($results)) {
    output("No tasks due to run.", true);
    exit(0);
}

$success = 0;
$failed = 0;
$skipped = 0;

foreach ($results as $name => $result) {
    switch ($result['status']) {
        case 'success':
            $success++;
            output("Task '{$name}' completed in {$result['duration_ms']}ms");
            break;
        case 'error':
            $failed++;
            output("Task '{$name}' failed: " . ($result['error'] ?? 'Unknown error'));
            break;
        case 'skipped':
            $skipped++;
            output("Task '{$name}' skipped: " . ($result['reason'] ?? 'Unknown reason'), true);
            break;
    }
}

output("Scheduler run complete. Success: {$success}, Failed: {$failed}, Skipped: {$skipped}");

exit($failed > 0 ? 1 : 0);
