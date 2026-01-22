<?php
$baseDir = '../';
require_once __DIR__ . '/../includes/config.php';
requirePermission(PERM_ADMIN);

$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $secret = trim($_POST['secret'] ?? '');
        $events = $_POST['events'] ?? [];

        if (empty($url)) {
            $error = 'Webhook URL is required';
        } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
            $error = 'Invalid webhook URL';
        } elseif (empty($events)) {
            $error = 'At least one event must be selected';
        } else {
            $result = createWebhook($url, $events, $secret ?: null, $name ?: null);
            if ($result) {
                $success = 'Webhook created successfully';
            } else {
                $error = 'Failed to create webhook';
            }
        }
    } elseif ($action === 'delete') {
        $webhookId = (int)($_POST['webhook_id'] ?? 0);
        if (deleteWebhookById($webhookId)) {
            $success = 'Webhook deleted successfully';
        } else {
            $error = 'Failed to delete webhook';
        }
    } elseif ($action === 'toggle') {
        $webhookId = (int)($_POST['webhook_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 0);
        $webhook = getWebhookById($webhookId);
        if ($webhook) {
            updateWebhook($webhookId, $webhook['url'], json_decode($webhook['events'], true),
                         $webhook['secret'], $webhook['name'], !$isActive);
            $success = 'Webhook ' . ($isActive ? 'disabled' : 'enabled') . ' successfully';
        }
    } elseif ($action === 'test') {
        $webhookId = (int)($_POST['webhook_id'] ?? 0);
        $webhook = getWebhookById($webhookId);
        if ($webhook) {
            $testPayload = json_encode([
                'event' => 'test',
                'timestamp' => date('c'),
                'message' => 'This is a test webhook delivery from Silo'
            ]);
            $result = deliverWebhook($webhook, 'test', $testPayload);
            if ($result) {
                $success = 'Test webhook delivered successfully';
            } else {
                $error = 'Test webhook delivery failed. Check the delivery log for details.';
            }
        }
    }
}

// Get all webhooks
$webhooks = getAllWebhooks();
$availableEvents = getWebhookEvents();

$siteName = getSetting('site_name', 'Silo');
$pageTitle = 'Webhooks - ' . $siteName;
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($_COOKIE['silo_theme'] ?? getSetting('default_theme', 'dark')) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= $baseDir ?>css/style.css">
    <style>
        .event-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            margin-right: 0.25rem;
            margin-bottom: 0.25rem;
            background: var(--bg-tertiary);
            color: var(--text-secondary);
        }
        .webhook-inactive {
            opacity: 0.5;
        }
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        .status-indicator.success { background: var(--success); }
        .status-indicator.error { background: var(--danger); }
        .status-indicator.unknown { background: var(--text-muted); }
        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 0.5rem;
        }
        .checkbox-grid label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="admin-layout">
        <?php include __DIR__ . '/../includes/admin-sidebar.php'; ?>

        <main class="admin-content">
            <div class="admin-header">
                <h1>Webhooks</h1>
                <p>Configure webhooks to receive notifications when events occur</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h2>Create New Webhook</h2>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <input type="hidden" name="action" value="create">

                        <div class="form-group">
                            <label for="name">Webhook Name (optional)</label>
                            <input type="text" id="name" name="name"
                                   placeholder="e.g., Discord Notifications, Backup Service">
                        </div>

                        <div class="form-group">
                            <label for="url">Webhook URL</label>
                            <input type="url" id="url" name="url" required
                                   placeholder="https://your-server.com/webhook">
                        </div>

                        <div class="form-group">
                            <label for="secret">Secret (optional)</label>
                            <input type="text" id="secret" name="secret"
                                   placeholder="Used to sign webhook payloads for verification">
                            <small class="text-muted">If provided, payloads will be signed with HMAC-SHA256</small>
                        </div>

                        <div class="form-group">
                            <label>Events</label>
                            <div class="checkbox-grid">
                                <?php foreach ($availableEvents as $event): ?>
                                    <label>
                                        <input type="checkbox" name="events[]" value="<?= $event ?>">
                                        <?= $event ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">Create Webhook</button>
                    </form>
                </div>
            </div>

            <div class="card" style="margin-top: 1.5rem;">
                <div class="card-header">
                    <h2>Configured Webhooks</h2>
                </div>
                <div class="card-body">
                    <?php if (empty($webhooks)): ?>
                        <p class="text-muted">No webhooks have been configured yet.</p>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Name / URL</th>
                                    <th>Events</th>
                                    <th>Last Delivery</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($webhooks as $webhook): ?>
                                    <?php
                                    $events = json_decode($webhook['events'], true) ?: [];
                                    $statusClass = 'unknown';
                                    if ($webhook['last_status_code']) {
                                        $statusClass = ($webhook['last_status_code'] >= 200 && $webhook['last_status_code'] < 300) ? 'success' : 'error';
                                    }
                                    ?>
                                    <tr class="<?= $webhook['is_active'] ? '' : 'webhook-inactive' ?>">
                                        <td>
                                            <span class="status-indicator <?= $statusClass ?>"></span>
                                            <?= $webhook['is_active'] ? 'Active' : 'Disabled' ?>
                                        </td>
                                        <td>
                                            <?php if ($webhook['name']): ?>
                                                <strong><?= htmlspecialchars($webhook['name']) ?></strong><br>
                                            <?php endif; ?>
                                            <code style="font-size: 0.85rem;"><?= htmlspecialchars($webhook['url']) ?></code>
                                        </td>
                                        <td>
                                            <?php foreach ($events as $event): ?>
                                                <span class="event-badge"><?= htmlspecialchars($event) ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td>
                                            <?php if ($webhook['last_triggered_at']): ?>
                                                <?= date('M j, Y H:i', strtotime($webhook['last_triggered_at'])) ?><br>
                                                <small>HTTP <?= $webhook['last_status_code'] ?></small>
                                                <?php if ($webhook['failure_count'] > 0): ?>
                                                    <br><small class="text-danger"><?= $webhook['failure_count'] ?> failures</small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                Never
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="post" action="" style="display:inline">
                                                <input type="hidden" name="action" value="test">
                                                <input type="hidden" name="webhook_id" value="<?= $webhook['id'] ?>">
                                                <button type="submit" class="btn btn-secondary btn-sm">Test</button>
                                            </form>
                                            <form method="post" action="" style="display:inline">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="webhook_id" value="<?= $webhook['id'] ?>">
                                                <input type="hidden" name="is_active" value="<?= $webhook['is_active'] ?>">
                                                <button type="submit" class="btn btn-secondary btn-sm">
                                                    <?= $webhook['is_active'] ? 'Disable' : 'Enable' ?>
                                                </button>
                                            </form>
                                            <form method="post" action="" style="display:inline"
                                                  onsubmit="return confirm('Delete this webhook?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="webhook_id" value="<?= $webhook['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card" style="margin-top: 1.5rem;">
                <div class="card-header">
                    <h2>Webhook Payload Format</h2>
                </div>
                <div class="card-body">
                    <p>Webhooks are delivered as HTTP POST requests with JSON payloads:</p>
                    <pre><code>{
  "event": "model.created",
  "timestamp": "2024-01-15T10:30:00+00:00",
  "model_id": 123,
  "name": "My Model",
  "file_type": "stl",
  "file_size": 1024000
}</code></pre>

                    <h4 style="margin-top: 1rem;">Headers</h4>
                    <table class="data-table">
                        <tr>
                            <td><code>Content-Type</code></td>
                            <td>application/json</td>
                        </tr>
                        <tr>
                            <td><code>X-Webhook-Event</code></td>
                            <td>The event type (e.g., model.created)</td>
                        </tr>
                        <tr>
                            <td><code>X-Webhook-Signature</code></td>
                            <td>HMAC-SHA256 signature (if secret configured)</td>
                        </tr>
                    </table>

                    <h4 style="margin-top: 1rem;">Verifying Signatures</h4>
                    <p>If you configured a secret, verify the signature like this:</p>
                    <pre><code>$signature = hash_hmac('sha256', $payload, $secret);
$expected = 'sha256=' . $signature;
if (hash_equals($expected, $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'])) {
    // Signature valid
}</code></pre>
                </div>
            </div>
        </main>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
