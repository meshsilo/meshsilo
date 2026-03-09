<?php
/**
 * CLI Tools Admin Panel
 *
 * Run optimization and maintenance CLI commands from the web interface.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requirePermission(PERM_ADMIN);

$pageTitle = 'CLI Tools';
$activePage = 'admin';
$adminPage = 'cli-tools';

$output = '';
$success = false;
$executedCommand = '';

// Define available CLI tools with their options
$tools = [
    'purge-css' => [
        'name' => 'CSS Purger',
        'description' => 'Analyze and remove unused CSS rules to reduce file size',
        'script' => 'cli/purge-css.php',
        'options' => [
            'analyze' => ['label' => 'Analyze', 'description' => 'Show CSS usage statistics', 'type' => 'flag'],
            'list' => ['label' => 'List Unused', 'description' => 'List unused CSS selectors', 'type' => 'flag'],
            'purge' => ['label' => 'Purge', 'description' => 'Generate optimized CSS file', 'type' => 'flag', 'warning' => true],
        ],
        'icon' => '&#128396;',
    ],
    'optimize' => [
        'name' => 'Optimizer',
        'description' => 'Manage optimization caches (classmap, routes, assets)',
        'script' => 'cli/optimize.php',
        'options' => [
            'status' => ['label' => 'Status', 'description' => 'Show current optimization status', 'type' => 'command'],
            'classmap' => ['label' => 'Generate Classmap', 'description' => 'Generate optimized autoloader classmap', 'type' => 'command'],
            'clear' => ['label' => 'Clear Caches', 'description' => 'Clear all optimization caches', 'type' => 'command', 'warning' => true],
            'all' => ['label' => 'Run All', 'description' => 'Run all optimizations', 'type' => 'command'],
        ],
        'icon' => '&#9889;',
    ],
    'worker' => [
        'name' => 'Job Queue Worker',
        'description' => 'Manage background job queue',
        'script' => 'cli/worker.php',
        'options' => [
            'stats' => ['label' => 'Show Stats', 'description' => 'Show queue statistics', 'type' => 'flag'],
            'once' => ['label' => 'Process One', 'description' => 'Process one job and exit', 'type' => 'flag'],
            'retry' => ['label' => 'Retry Failed', 'description' => 'Retry all failed jobs', 'type' => 'flag'],
            'clear' => ['label' => 'Clear Failed', 'description' => 'Clear all failed jobs', 'type' => 'flag', 'warning' => true],
        ],
        'params' => [
            'queue' => ['label' => 'Queue Name', 'description' => 'Specific queue to process', 'type' => 'text', 'default' => 'default'],
        ],
        'icon' => '&#128736;',
    ],
    'dedup' => [
        'name' => 'Deduplication',
        'description' => 'Find and remove duplicate files to save storage',
        'script' => 'cli/dedup.php',
        'options' => [
            'dry-run' => ['label' => 'Dry Run', 'description' => 'Show what would be deduplicated without changes', 'type' => 'flag', 'default' => true],
            'force' => ['label' => 'Force', 'description' => 'Run even if auto-deduplication is disabled', 'type' => 'flag'],
        ],
        'icon' => '&#128451;',
    ],
    'generate-thumbnails' => [
        'name' => 'Thumbnail Generator',
        'description' => 'Generate missing thumbnails for 3D models',
        'script' => 'cli/generate-thumbnails.php',
        'options' => [
            'dry-run' => ['label' => 'Dry Run', 'description' => 'Show what would be processed', 'type' => 'flag'],
            'verbose' => ['label' => 'Verbose', 'description' => 'Show detailed output', 'type' => 'flag'],
            'force' => ['label' => 'Force', 'description' => 'Regenerate existing thumbnails', 'type' => 'flag'],
        ],
        'params' => [
            'limit' => ['label' => 'Limit', 'description' => 'Maximum models to process', 'type' => 'number', 'default' => 100],
            'model' => ['label' => 'Model ID', 'description' => 'Generate for specific model only', 'type' => 'number', 'placeholder' => 'Optional'],
        ],
        'icon' => '&#128444;',
    ],
    'migrate' => [
        'name' => 'Database Migrations',
        'description' => 'Run database migrations and schema updates',
        'script' => 'cli/migrate.php',
        'options' => [
            'status' => ['label' => 'Status', 'description' => 'Show migration status', 'type' => 'flag', 'default' => true],
            'backup' => ['label' => 'Backup First', 'description' => 'Create backup before migrating', 'type' => 'flag'],
        ],
        'icon' => '&#128451;',
    ],
    'scheduler' => [
        'name' => 'Task Scheduler',
        'description' => 'Manage scheduled tasks',
        'script' => 'cli/scheduler.php',
        'options' => [
            'list' => ['label' => 'List Tasks', 'description' => 'Show all scheduled tasks', 'type' => 'flag', 'default' => true],
        ],
        'params' => [
            'run' => ['label' => 'Run Task', 'description' => 'Run specific task by name', 'type' => 'text', 'placeholder' => 'e.g., cleanup:logs'],
        ],
        'icon' => '&#128339;',
    ],
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!Csrf::validate()) {
        $output = "Error: Invalid CSRF token";
    } else {
        $toolKey = $_POST['tool'] ?? '';

        if (isset($tools[$toolKey])) {
            $tool = $tools[$toolKey];
            $script = $tool['script'];

            // Build command arguments
            $args = [];

            // Handle options (flags and commands)
            if (isset($tool['options'])) {
                // Check for command-type options (radio buttons share same name)
                $commandInput = "opt_{$toolKey}_command";
                if (!empty($_POST[$commandInput])) {
                    // Validate against allowed option values to prevent command injection
                    $allowedCommands = [];
                    foreach ($tool['options'] as $optKey => $opt) {
                        if (($opt['type'] ?? '') === 'command') {
                            $allowedCommands[] = $opt['value'] ?? "--{$optKey}";
                        }
                    }
                    $submittedCmd = $_POST[$commandInput];
                    if (empty($allowedCommands) || in_array($submittedCmd, $allowedCommands, true)) {
                        $args[] = escapeshellarg($submittedCmd);
                    }
                }

                // Check for flag-type options (checkboxes)
                foreach ($tool['options'] as $optKey => $opt) {
                    if ($opt['type'] === 'flag') {
                        $inputName = "opt_{$toolKey}_{$optKey}";
                        if (!empty($_POST[$inputName])) {
                            $args[] = "--{$optKey}";
                        }
                    }
                }
            }

            // Handle parameters
            if (isset($tool['params'])) {
                foreach ($tool['params'] as $paramKey => $param) {
                    $inputName = "param_{$toolKey}_{$paramKey}";
                    $value = $_POST[$inputName] ?? '';

                    if ($value !== '' && $value !== ($param['default'] ?? '')) {
                        if ($param['type'] === 'number' && is_numeric($value)) {
                            $args[] = "--{$paramKey}=" . intval($value);
                        } elseif ($param['type'] === 'text' && preg_match('/^[a-zA-Z0-9_:-]+$/', $value)) {
                            $args[] = "--{$paramKey}=" . escapeshellarg($value);
                        }
                    }
                }
            }

            // Build full command
            $phpBinary = PHP_BINARY ?: 'php';
            $scriptPath = dirname(__DIR__, 2) . '/' . $script;

            if (file_exists($scriptPath)) {
                $command = escapeshellcmd($phpBinary) . ' ' . escapeshellarg($scriptPath);
                if (!empty($args)) {
                    $command .= ' ' . implode(' ', $args);
                }

                $executedCommand = "php {$script}" . (!empty($args) ? ' ' . implode(' ', $args) : '');

                // Execute command with timeout
                $descriptorspec = [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ];

                $cwd = dirname(__DIR__, 2);
                $process = proc_open($command, $descriptorspec, $pipes, $cwd);

                if (is_resource($process)) {
                    fclose($pipes[0]);

                    // Set timeout
                    stream_set_timeout($pipes[1], 60);
                    stream_set_timeout($pipes[2], 60);

                    $stdout = stream_get_contents($pipes[1]);
                    $stderr = stream_get_contents($pipes[2]);

                    fclose($pipes[1]);
                    fclose($pipes[2]);

                    $returnCode = proc_close($process);

                    $output = $stdout;
                    if ($stderr) {
                        $output .= "\n\nErrors:\n" . $stderr;
                    }

                    $success = ($returnCode === 0);

                    // Log the action
                    if (function_exists('logActivity')) {
                        logActivity('cli_tool_run', 'system', null, $tool['name'], [
                            'command' => $executedCommand,
                            'success' => $success,
                        ]);
                    }
                } else {
                    $output = "Error: Failed to execute command";
                }
            } else {
                $output = "Error: Script not found: {$script}";
            }
        } else {
            $output = "Error: Unknown tool";
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/../../includes/admin-sidebar.php'; ?>

    <div class="admin-content">
        <div class="admin-header">
            <h1>CLI Tools</h1>
            <p>Run optimization and maintenance commands from the web interface</p>
        </div>

        <?php if ($output): ?>
        <div class="cli-output-panel <?= $success ? 'success' : 'error' ?>">
            <div class="output-header">
                <span class="output-icon"><?= $success ? '&#10004;' : '&#10006;' ?></span>
                <span class="output-title"><?= $success ? 'Command Completed' : 'Command Output' ?></span>
                <?php if ($executedCommand): ?>
                <code class="executed-command"><?= htmlspecialchars($executedCommand) ?></code>
                <?php endif; ?>
            </div>
            <pre class="output-content"><?= htmlspecialchars($output) ?></pre>
        </div>
        <?php endif; ?>

        <div class="tools-grid">
            <?php foreach ($tools as $toolKey => $tool): ?>
            <div class="tool-card" data-tool="<?= $toolKey ?>">
                <div class="tool-header">
                    <span class="tool-icon"><?= $tool['icon'] ?></span>
                    <div class="tool-title">
                        <h3><?= htmlspecialchars($tool['name']) ?></h3>
                        <p><?= htmlspecialchars($tool['description']) ?></p>
                    </div>
                    <button type="button" class="tool-expand" aria-expanded="false" onclick="toggleTool('<?= $toolKey ?>')">
                        <span class="expand-icon">&#9662;</span>
                    </button>
                </div>

                <form method="post" class="tool-form" id="form-<?= $toolKey ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="tool" value="<?= $toolKey ?>">

                    <?php if (!empty($tool['options'])): ?>
                    <div class="tool-section">
                        <h4>Options</h4>
                        <div class="options-grid">
                            <?php foreach ($tool['options'] as $optKey => $opt): ?>
                            <label class="option-item <?= !empty($opt['warning']) ? 'warning' : '' ?>">
                                <?php if ($opt['type'] === 'command'): ?>
                                <input
                                    type="radio"
                                    name="opt_<?= $toolKey ?>_command"
                                    value="<?= htmlspecialchars($optKey) ?>"
                                    <?= !empty($opt['default']) ? 'checked' : '' ?>
                                >
                                <?php else: ?>
                                <input
                                    type="checkbox"
                                    name="opt_<?= $toolKey ?>_<?= $optKey ?>"
                                    value="1"
                                    <?= !empty($opt['default']) ? 'checked' : '' ?>
                                >
                                <?php endif; ?>
                                <span class="option-label">
                                    <?= htmlspecialchars($opt['label']) ?>
                                    <?php if (!empty($opt['warning'])): ?>
                                    <span class="warning-badge" title="This action makes changes">&#9888;</span>
                                    <?php endif; ?>
                                </span>
                                <span class="option-desc"><?= htmlspecialchars($opt['description']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($tool['params'])): ?>
                    <div class="tool-section">
                        <h4>Parameters</h4>
                        <div class="params-grid">
                            <?php foreach ($tool['params'] as $paramKey => $param): ?>
                            <div class="param-item">
                                <label for="param_<?= $toolKey ?>_<?= $paramKey ?>"><?= htmlspecialchars($param['label']) ?></label>
                                <input
                                    type="<?= $param['type'] === 'number' ? 'number' : 'text' ?>"
                                    id="param_<?= $toolKey ?>_<?= $paramKey ?>"
                                    name="param_<?= $toolKey ?>_<?= $paramKey ?>"
                                    value="<?= htmlspecialchars($param['default'] ?? '') ?>"
                                    placeholder="<?= htmlspecialchars($param['placeholder'] ?? '') ?>"
                                    <?= $param['type'] === 'number' ? 'min="0"' : '' ?>
                                >
                                <span class="param-desc"><?= htmlspecialchars($param['description']) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="tool-actions">
                        <button type="submit" class="btn btn-primary">
                            <span class="btn-icon">&#9654;</span>
                            Run <?= htmlspecialchars($tool['name']) ?>
                        </button>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="cli-info">
            <h3>Running from Command Line</h3>
            <p>These tools can also be run directly from the command line for better performance and longer timeouts:</p>
            <pre>cd /path/to/meshsilo
php cli/purge-css.php --analyze
php cli/optimize.php status
php cli/worker.php --stats</pre>
        </div>
    </div>
</div>

<style>
/* Admin Header */
.admin-header {
    margin-bottom: 2rem;
}

.admin-header h1 {
    margin: 0 0 0.5rem 0;
    font-size: 1.5rem;
    color: var(--color-text);
}

.admin-header p {
    margin: 0;
    color: var(--color-text-muted);
}

/* CLI Tools Page Styles */
.tools-grid {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.tool-card {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    overflow: hidden;
}

.tool-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem 1.5rem;
    cursor: pointer;
    transition: background-color 0.15s;
}

.tool-header:hover {
    background: var(--color-surface-hover);
}

.tool-icon {
    font-size: 1.5rem;
    width: 2.5rem;
    height: 2.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-background);
    border-radius: var(--radius);
    flex-shrink: 0;
}

.tool-title {
    flex: 1;
    min-width: 0;
}

.tool-title h3 {
    margin: 0;
    font-size: 1rem;
    color: var(--color-text);
}

.tool-title p {
    margin: 0.25rem 0 0;
    font-size: 0.875rem;
    color: var(--color-text-muted);
}

.tool-expand {
    background: none;
    border: none;
    color: var(--color-text-muted);
    cursor: pointer;
    padding: 0.5rem;
    transition: transform 0.2s;
    flex-shrink: 0;
}

.tool-card.expanded .tool-expand {
    transform: rotate(180deg);
}

.tool-form {
    display: none;
    padding: 0 1.5rem 1.5rem;
    border-top: 1px solid var(--color-border);
}

.tool-card.expanded .tool-form {
    display: block;
}

.tool-section {
    margin-top: 1.25rem;
}

.tool-section h4 {
    font-size: 0.7rem;
    text-transform: uppercase;
    color: var(--color-text-muted);
    margin: 0 0 0.75rem 0;
    letter-spacing: 0.05em;
    font-weight: 600;
}

.options-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 0.5rem;
}

.option-item {
    display: grid;
    grid-template-columns: auto 1fr;
    grid-template-rows: auto auto;
    gap: 0 0.75rem;
    padding: 0.75rem;
    background: var(--color-background);
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    cursor: pointer;
    transition: background-color 0.15s, border-color 0.15s;
}

.option-item:hover {
    background: var(--color-surface-hover);
    border-color: var(--color-text-muted);
}

.option-item.warning {
    border-left: 3px solid var(--color-warning);
}

.option-item input {
    grid-row: span 2;
    margin-top: 0.25rem;
    accent-color: var(--color-primary);
}

.option-label {
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--color-text);
}

.warning-badge {
    color: var(--color-warning);
    font-size: 0.875rem;
}

.option-desc {
    font-size: 0.8rem;
    color: var(--color-text-muted);
}

.params-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem;
}

.param-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.param-item label {
    font-weight: 500;
    font-size: 0.875rem;
    color: var(--color-text);
}

.param-item input {
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    background: var(--color-background);
    color: var(--color-text);
    font-size: 0.875rem;
}

.param-item input:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
}

.param-desc {
    font-size: 0.75rem;
    color: var(--color-text-muted);
}

.tool-actions {
    margin-top: 1.5rem;
    padding-top: 1rem;
    border-top: 1px solid var(--color-border);
}

.btn-icon {
    margin-right: 0.25rem;
}

/* CLI Output Panel */
.cli-output-panel {
    background: var(--color-surface);
    border: 2px solid var(--color-border);
    border-radius: var(--radius-md);
    margin-bottom: 1.5rem;
    overflow: hidden;
}

.cli-output-panel.success {
    border-color: var(--color-success);
}

.cli-output-panel.error {
    border-color: var(--color-danger);
}

.output-header {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    background: var(--color-surface-elevated);
    border-bottom: 1px solid var(--color-border);
}

.output-icon {
    font-size: 1.25rem;
}

.cli-output-panel.success .output-icon {
    color: var(--color-success);
}

.cli-output-panel.error .output-icon {
    color: var(--color-danger);
}

.output-title {
    font-weight: 600;
    color: var(--color-text);
}

.executed-command {
    margin-left: auto;
    padding: 0.25rem 0.5rem;
    background: var(--color-background);
    border-radius: var(--radius);
    font-size: 0.75rem;
    color: var(--color-text-muted);
    font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
}

.output-content {
    padding: 1rem;
    margin: 0;
    font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
    font-size: 0.8rem;
    line-height: 1.6;
    overflow-x: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
    max-height: 400px;
    overflow-y: auto;
    color: var(--color-text);
    background: var(--color-background);
}

/* CLI Info Box */
.cli-info {
    margin-top: 2rem;
    padding: 1.5rem;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
}

.cli-info h3 {
    margin: 0 0 0.5rem;
    font-size: 1rem;
    color: var(--color-text);
}

.cli-info p {
    margin: 0 0 1rem;
    color: var(--color-text-muted);
}

.cli-info pre {
    margin: 0;
    padding: 1rem;
    background: var(--color-background);
    border-radius: var(--radius);
    font-size: 0.8rem;
    overflow-x: auto;
    color: var(--color-text);
    border: 1px solid var(--color-border);
}

/* Responsive */
@media (max-width: 640px) {
    .tool-header {
        padding: 1rem;
    }

    .tool-form {
        padding: 0 1rem 1rem;
    }

    .options-grid {
        grid-template-columns: 1fr;
    }

    .params-grid {
        grid-template-columns: 1fr;
    }

    .output-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .executed-command {
        margin-left: 0;
    }
}
</style>

<script>
function toggleTool(toolKey) {
    const card = document.querySelector(`.tool-card[data-tool="${toolKey}"]`);
    const isExpanded = card.classList.contains('expanded');

    // Close all other cards
    document.querySelectorAll('.tool-card.expanded').forEach(c => {
        if (c !== card) c.classList.remove('expanded');
    });

    // Toggle this card
    card.classList.toggle('expanded', !isExpanded);

    // Update aria-expanded
    const button = card.querySelector('.tool-expand');
    button.setAttribute('aria-expanded', !isExpanded);
}

// Allow clicking header to expand
document.querySelectorAll('.tool-header').forEach(header => {
    header.addEventListener('click', function(e) {
        if (e.target.closest('.tool-expand')) return;
        const toolKey = this.closest('.tool-card').dataset.tool;
        toggleTool(toolKey);
    });
});

// Auto-expand card if there was output for it
<?php if ($executedCommand && isset($_POST['tool'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    toggleTool('<?= htmlspecialchars($_POST['tool']) ?>');
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
