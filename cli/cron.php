#!/usr/bin/env php
<?php
/**
 * Unified Cron Entry Point
 *
 * Single cron script that handles all periodic tasks for easier deployment.
 * All tasks are managed through the Scheduler and appear in Admin > Scheduled Tasks.
 *
 * Usage:
 *   php cli/cron.php                    Run all due tasks (default)
 *   php cli/cron.php --all              Run all tasks regardless of schedule
 *   php cli/cron.php --task=<name>      Run a specific task by name
 *   php cli/cron.php --list             List all registered tasks
 *   php cli/cron.php --history          Show task execution history
 *   php cli/cron.php --help             Show this help
 *
 * Recommended cron setup (single entry):
 *   * * * * * php /path/to/silo/cli/cron.php >> /path/to/silo/storage/logs/cron.log 2>&1
 *
 * The scheduler handles task timing internally, so running every minute is safe.
 * All task executions are logged and visible in Admin > Scheduled Tasks.
 */

// Ensure CLI only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("This script must be run from the command line.\n");
}

// Change to project root
chdir(dirname(__DIR__));

// Load application
require_once 'includes/config.php';
require_once 'includes/Scheduler.php';

// Parse arguments
$options = getopt('', ['all', 'task:', 'list', 'history', 'verbose', 'help']);
$verbose = isset($options['verbose']);

/**
 * Output with timestamp
 */
function output(string $message, bool $verboseOnly = false): void {
    global $verbose;
    if ($verboseOnly && !$verbose) {
        return;
    }
    echo date('[Y-m-d H:i:s] ') . $message . "\n";
}

// Show help
if (isset($options['help'])) {
    echo <<<HELP
Silo Unified Cron - Single entry point for all scheduled tasks

Usage: php cli/cron.php [options]

Options:
  --all            Run all tasks regardless of schedule
  --task=<name>    Run a specific task by name
  --list           List all registered tasks
  --history        Show recent task execution history
  --verbose        Show detailed output
  --help           Show this help message

Without options, runs all due tasks based on their schedules.

Task Names:
  cleanup:sessions     Clean expired sessions (hourly)
  cleanup:logs         Clean old log files (daily 3am)
  cleanup:cache        Clean expired cache (every 6 hours)
  cleanup:rate_limits  Clean rate limit data (every 15 min)
  cleanup:activity     Clean old activity logs (daily 4am)
  queue:process        Process background jobs (every minute)
  retention:apply      Apply retention policies (daily 2am)
  thumbnails:generate  Generate thumbnails (every 5 min)
  dedup:scan           Scan for duplicates (daily 1am)
  mesh:analyze         Analyze mesh files (every 10 min)
  demo:reset           Reset demo data (hourly, if enabled)
  maintenance:optimize Database optimization (weekly Sunday 5am)

Cron Setup:
  * * * * * php /path/to/silo/cli/cron.php >> /path/to/silo/storage/logs/cron.log 2>&1

All task executions are logged and visible in Admin > Scheduled Tasks.

HELP;
    exit(0);
}

// List tasks
if (isset($options['list'])) {
    $tasks = Scheduler::getTasks();

    echo "\nRegistered Tasks:\n";
    echo str_repeat('-', 90) . "\n";
    printf("%-25s %-15s %-8s %s\n", "Name", "Schedule", "Enabled", "Description");
    echo str_repeat('-', 90) . "\n";

    foreach ($tasks as $name => $task) {
        $nextRun = Scheduler::getNextRun($task['schedule']);
        printf(
            "%-25s %-15s %-8s %s\n",
            $name,
            $task['schedule'],
            $task['enabled'] ? 'Yes' : 'No',
            $task['description'] ?? '-'
        );
        if ($verbose && $nextRun) {
            printf("  Next run: %s\n", date('Y-m-d H:i', $nextRun));
        }
    }

    echo "\nTotal: " . count($tasks) . " tasks\n";
    exit(0);
}

// Show history
if (isset($options['history'])) {
    $history = Scheduler::getHistory(30);

    echo "\nRecent Task History:\n";
    echo str_repeat('-', 100) . "\n";
    printf("%-20s %-25s %-12s %-10s %s\n", "Time", "Task", "Status", "Duration", "Output");
    echo str_repeat('-', 100) . "\n";

    foreach ($history as $entry) {
        $output = $entry['output'] ? substr($entry['output'], 0, 25) : '-';
        printf(
            "%-20s %-25s %-12s %-10s %s\n",
            date('M j H:i', strtotime($entry['created_at'])),
            $entry['task_name'],
            $entry['status'],
            $entry['duration_ms'] ? $entry['duration_ms'] . 'ms' : '-',
            $output
        );
    }

    if (empty($history)) {
        echo "No task history found.\n";
    }
    exit(0);
}

// Run specific task
if (isset($options['task'])) {
    $taskName = $options['task'];
    output("Running task: {$taskName}");

    $result = Scheduler::runTask($taskName);

    if ($result['status'] === 'success') {
        output("Task completed in {$result['duration_ms']}ms");
        if (!empty($result['output'])) {
            output("Output: " . trim($result['output']));
        }
        exit(0);
    } else {
        output("Task failed: " . ($result['error'] ?? $result['reason'] ?? 'Unknown error'));
        exit(1);
    }
}

// Run all tasks regardless of schedule
if (isset($options['all'])) {
    output("Running all tasks...");
    $tasks = Scheduler::getTasks();
    $success = $failed = $skipped = 0;

    foreach ($tasks as $name => $task) {
        if (!$task['enabled']) {
            $skipped++;
            continue;
        }

        output("Running: {$name}", true);
        $result = Scheduler::runTask($name);

        switch ($result['status']) {
            case 'success':
                $success++;
                output("  {$name}: completed in {$result['duration_ms']}ms");
                break;
            case 'error':
                $failed++;
                output("  {$name}: failed - " . ($result['error'] ?? 'Unknown'));
                break;
            default:
                $skipped++;
                output("  {$name}: skipped - " . ($result['reason'] ?? 'Unknown'), true);
        }
    }

    output("Complete. Success: {$success}, Failed: {$failed}, Skipped: {$skipped}");
    exit($failed > 0 ? 1 : 0);
}

// Default: run scheduler (executes due tasks)
output("Starting cron run...", true);

$results = Scheduler::run();

if (empty($results)) {
    output("No tasks due.", true);
    exit(0);
}

$success = $failed = $skipped = 0;

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

output("Cron complete. Success: {$success}, Failed: {$failed}, Skipped: {$skipped}");

exit($failed > 0 ? 1 : 0);
