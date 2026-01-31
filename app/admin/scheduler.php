<?php
$pageTitle = 'Scheduled Tasks';
$adminPage = 'scheduler';

require_once __DIR__ . '/../../includes/permissions.php';

// Check permission
if (!canManageScheduler()) {
    $_SESSION['error'] = 'You do not have permission to manage scheduled tasks.';
    header('Location: ' . route('admin.health'));
    exit;
}

require_once __DIR__ . '/../../includes/Scheduler.php';

// Handle actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $taskName = $_POST['task'] ?? '';

    if ($action === 'run' && $taskName) {
        $result = Scheduler::runTask($taskName);
        if ($result['status'] === 'success') {
            $message = "Task '{$taskName}' completed in {$result['duration_ms']}ms";
            if (!empty($result['output'])) {
                $message .= ": " . htmlspecialchars($result['output']);
            }
        } else {
            $error = "Task '{$taskName}' failed: " . ($result['error'] ?? $result['reason'] ?? 'Unknown error');
        }
    } elseif ($action === 'enable' && $taskName) {
        Scheduler::setEnabled($taskName, true);
        $message = "Task '{$taskName}' enabled";
    } elseif ($action === 'disable' && $taskName) {
        Scheduler::setEnabled($taskName, false);
        $message = "Task '{$taskName}' disabled";
    } elseif ($action === 'run_all') {
        $results = Scheduler::run();
        $success = 0;
        $failed = 0;
        foreach ($results as $name => $result) {
            if ($result['status'] === 'success') {
                $success++;
            } else {
                $failed++;
            }
        }
        $message = "Ran scheduler: {$success} tasks succeeded, {$failed} failed/skipped";
    } elseif ($action === 'save_custom') {
        $customTasks = json_decode($_POST['custom_tasks'] ?? '[]', true);
        if ($customTasks !== null) {
            setSetting('scheduler_custom_tasks', json_encode($customTasks));
            $message = "Custom tasks saved";
        } else {
            $error = "Invalid JSON for custom tasks";
        }
    }
}

// Get all tasks
$tasks = Scheduler::getTasks();
$history = Scheduler::getHistory(50);

// Get custom tasks from settings
$customTasks = json_decode(getSetting('scheduler_custom_tasks', '[]'), true) ?? [];

// Calculate next run times
$now = time();

include __DIR__ . '/../../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/../../includes/admin-sidebar.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <h1>Scheduled Tasks</h1>
            <p>Manage background tasks and view execution history</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="card mb-4">
            <div class="card-header">
                <h2>Quick Actions</h2>
            </div>
            <div class="card-body">
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="run_all">
                    <button type="submit" class="btn btn-primary">Run All Due Tasks</button>
                </form>
                <a href="<?= route('admin.health') ?>" class="btn btn-secondary ml-2">View System Health</a>

                <div class="mt-3">
                    <h4>Cron Setup</h4>
                    <p class="text-muted">Add this line to your crontab to run tasks automatically:</p>
                    <pre class="code-block">* * * * * php <?= realpath(__DIR__ . '/../../cli/scheduler.php') ?></pre>
                    <p class="text-muted mt-2">Or use the web endpoint with a secret key:</p>
                    <pre class="code-block">* * * * * curl -s "<?= rtrim(getSetting('site_url', ''), '/') ?>/api/cron?key=YOUR_CRON_KEY"</pre>
                </div>
            </div>
        </div>

        <!-- Task List -->
        <div class="card mb-4">
            <div class="card-header">
                <h2>Registered Tasks</h2>
            </div>
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Task</th>
                            <th>Schedule</th>
                            <th>Description</th>
                            <th>Next Run</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tasks as $name => $task): ?>
                            <?php
                            $nextRun = Scheduler::getNextRun($task['schedule']);
                            $isDue = Scheduler::isDue($task['schedule'], $now);
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($name) ?></strong>
                                </td>
                                <td>
                                    <code><?= htmlspecialchars($task['schedule']) ?></code>
                                    <br><small class="text-muted"><?= describeCronSchedule($task['schedule']) ?></small>
                                </td>
                                <td><?= htmlspecialchars($task['description'] ?? '-') ?></td>
                                <td>
                                    <?php if ($nextRun): ?>
                                        <?= date('M j, H:i', $nextRun) ?>
                                        <br><small class="text-muted"><?= humanTimeDiff($nextRun - $now) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">Unknown</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($task['enabled']): ?>
                                        <span class="badge badge-success">Enabled</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Disabled</span>
                                    <?php endif; ?>
                                    <?php if ($isDue): ?>
                                        <span class="badge badge-warning">Due</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="task" value="<?= htmlspecialchars($name) ?>">
                                        <input type="hidden" name="action" value="run">
                                        <button type="submit" class="btn btn-sm btn-primary">Run Now</button>
                                    </form>
                                    <?php if ($task['enabled']): ?>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="task" value="<?= htmlspecialchars($name) ?>">
                                            <input type="hidden" name="action" value="disable">
                                            <button type="submit" class="btn btn-sm btn-secondary">Disable</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="task" value="<?= htmlspecialchars($name) ?>">
                                            <input type="hidden" name="action" value="enable">
                                            <button type="submit" class="btn btn-sm btn-success">Enable</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Execution History -->
        <div class="card">
            <div class="card-header">
                <h2>Execution History</h2>
            </div>
            <div class="card-body">
                <?php if (empty($history)): ?>
                    <p class="text-muted">No task execution history yet.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>Status</th>
                                <th>Duration</th>
                                <th>Output</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $entry): ?>
                                <tr>
                                    <td><?= htmlspecialchars($entry['task_name']) ?></td>
                                    <td>
                                        <?php if ($entry['status'] === 'completed'): ?>
                                            <span class="badge badge-success">Completed</span>
                                        <?php elseif ($entry['status'] === 'failed'): ?>
                                            <span class="badge badge-danger">Failed</span>
                                        <?php elseif ($entry['status'] === 'started'): ?>
                                            <span class="badge badge-warning">Running</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary"><?= htmlspecialchars($entry['status']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($entry['duration_ms']): ?>
                                            <?= number_format($entry['duration_ms']) ?>ms
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($entry['output']): ?>
                                            <span class="task-output" title="<?= htmlspecialchars($entry['output']) ?>">
                                                <?= htmlspecialchars(substr($entry['output'], 0, 50)) ?>
                                                <?= strlen($entry['output']) > 50 ? '...' : '' ?>
                                            </span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($entry['created_at']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Cron Expression Help -->
        <div class="card mt-4">
            <div class="card-header">
                <h2>Cron Expression Reference</h2>
            </div>
            <div class="card-body">
                <pre>
┌───────────── minute (0-59)
│ ┌───────────── hour (0-23)
│ │ ┌───────────── day of month (1-31)
│ │ │ ┌───────────── month (1-12)
│ │ │ │ ┌───────────── day of week (0-6, Sunday=0)
│ │ │ │ │
* * * * *
                </pre>
                <h4>Common Expressions</h4>
                <table class="table table-sm">
                    <tr><td><code>* * * * *</code></td><td>Every minute</td></tr>
                    <tr><td><code>*/5 * * * *</code></td><td>Every 5 minutes</td></tr>
                    <tr><td><code>0 * * * *</code></td><td>Every hour</td></tr>
                    <tr><td><code>0 */6 * * *</code></td><td>Every 6 hours</td></tr>
                    <tr><td><code>0 0 * * *</code></td><td>Every day at midnight</td></tr>
                    <tr><td><code>0 2 * * *</code></td><td>Every day at 2am</td></tr>
                    <tr><td><code>0 0 * * 0</code></td><td>Every Sunday at midnight</td></tr>
                    <tr><td><code>0 0 1 * *</code></td><td>First day of every month</td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
.code-block {
    background: var(--color-bg-secondary);
    padding: 0.75rem 1rem;
    border-radius: 4px;
    font-family: monospace;
    font-size: 0.85rem;
    overflow-x: auto;
}

.task-output {
    cursor: help;
    border-bottom: 1px dotted var(--color-text-muted);
}

.badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 4px;
}

.badge-success { background: #10b981; color: white; }
.badge-danger { background: #ef4444; color: white; }
.badge-warning { background: #f59e0b; color: white; }
.badge-secondary { background: #6b7280; color: white; }

.ml-2 { margin-left: 0.5rem; }
</style>

<?php
/**
 * Describe a cron schedule in human terms
 */
function describeCronSchedule($schedule) {
    $parts = preg_split('/\s+/', trim($schedule));
    if (count($parts) !== 5) return 'Invalid';

    [$min, $hour, $dom, $mon, $dow] = $parts;

    // Every minute
    if ($schedule === '* * * * *') return 'Every minute';

    // Every N minutes
    if (preg_match('/^\*\/(\d+)$/', $min, $m) && $hour === '*' && $dom === '*' && $mon === '*' && $dow === '*') {
        return "Every {$m[1]} minutes";
    }

    // Every hour at specific minute
    if ($min !== '*' && $hour === '*' && $dom === '*' && $mon === '*' && $dow === '*') {
        return "Every hour at minute $min";
    }

    // Every N hours
    if ($min === '0' && preg_match('/^\*\/(\d+)$/', $hour, $m) && $dom === '*' && $mon === '*' && $dow === '*') {
        return "Every {$m[1]} hours";
    }

    // Daily at specific time
    if ($min !== '*' && $hour !== '*' && $dom === '*' && $mon === '*' && $dow === '*') {
        return "Daily at " . sprintf('%02d:%02d', $hour, $min);
    }

    // Weekly
    if ($dom === '*' && $mon === '*' && $dow !== '*') {
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $dayName = $days[$dow] ?? 'day ' . $dow;
        return "Weekly on $dayName";
    }

    return 'Custom schedule';
}

/**
 * Human-readable time difference
 */
function humanTimeDiff($seconds) {
    if ($seconds < 0) return 'overdue';
    if ($seconds < 60) return 'in ' . $seconds . 's';
    if ($seconds < 3600) return 'in ' . floor($seconds / 60) . 'm';
    if ($seconds < 86400) return 'in ' . floor($seconds / 3600) . 'h';
    return 'in ' . floor($seconds / 86400) . 'd';
}

include __DIR__ . '/../../includes/footer.php';
?>
