<?php
// Set baseDir based on how we're accessed (router vs direct)
// Router loads from root context, direct access needs ../
$baseDir = isset($_SERVER['ROUTE_NAME']) ? '' : '../';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/api-auth.php';
require_once __DIR__ . '/../../includes/features.php';

// Require feature to be enabled
requireFeature('api_keys');

// Require API keys management permission
if (!isLoggedIn() || !canManageApiKeys()) {
    $_SESSION['error'] = 'You do not have permission to manage API keys.';
    header('Location: ' . route('home'));
    exit;
}

$user = getCurrentUser();
$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $permissions = $_POST['permissions'] ?? [];
        $expiresIn = $_POST['expires_in'] ?? '';

        if (empty($name)) {
            $error = 'API key name is required';
        } else {
            $expiresAt = null;
            if (!empty($expiresIn)) {
                $expiresAt = date('Y-m-d H:i:s', strtotime("+$expiresIn"));
            }

            $result = generateApiKey($user['id'], $name, $permissions, $expiresAt);
            if ($result) {
                $_SESSION['new_api_key'] = $result['key'];
                $_SESSION['new_api_key_name'] = $result['name'];
                header('Location: ' . route('admin.api-keys', [], ['created' => '1']));
                exit;
            } else {
                $error = 'Failed to create API key';
            }
        }
    } elseif ($action === 'revoke') {
        $keyId = (int)($_POST['key_id'] ?? 0);
        if (revokeApiKey($keyId)) {
            $success = 'API key revoked successfully';
        } else {
            $error = 'Failed to revoke API key';
        }
    }
}

// Check for newly created key
$newKey = null;
$newKeyName = null;
if (isset($_GET['created']) && isset($_SESSION['new_api_key'])) {
    $newKey = $_SESSION['new_api_key'];
    $newKeyName = $_SESSION['new_api_key_name'];
    unset($_SESSION['new_api_key']);
    unset($_SESSION['new_api_key_name']);
}

// Get all API keys (admin sees all)
$apiKeys = getAllApiKeys();

$siteName = getSetting('site_name', 'MeshSilo');
$pageTitle = 'API Keys - ' . $siteName;
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($_COOKIE['meshsilo_theme'] ?? getSetting('default_theme', 'dark')) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= $baseDir ?>css/style.css">
    <style>
        .api-key-display {
            background: var(--bg-tertiary);
            border: 2px solid var(--success);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .api-key-display .key-value {
            font-family: monospace;
            font-size: 0.9rem;
            background: var(--bg-primary);
            padding: 0.75rem;
            border-radius: 4px;
            word-break: break-all;
            margin: 0.5rem 0;
        }
        .api-key-display .warning {
            color: var(--warning);
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        .permission-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            margin-right: 0.25rem;
            background: var(--bg-tertiary);
        }
        .permission-badge.read { background: var(--info); color: white; }
        .permission-badge.write { background: var(--success); color: white; }
        .permission-badge.delete { background: var(--warning); color: black; }
        .permission-badge.admin { background: var(--danger); color: white; }
        .key-inactive {
            opacity: 0.5;
        }
        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .checkbox-group label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../includes/header.php'; ?>

    <div class="admin-layout">
        <?php include __DIR__ . '/../../includes/admin-sidebar.php'; ?>

        <main class="admin-content">
            <div class="admin-header">
                <h1>API Keys</h1>
                <p>Manage API keys for programmatic access to Silo</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <?php if ($newKey): ?>
                <div class="api-key-display">
                    <h3>API Key Created: <?= htmlspecialchars($newKeyName) ?></h3>
                    <p>Your new API key has been created. Copy it now - you won't be able to see it again!</p>
                    <div class="key-value" id="newKeyValue"><?= htmlspecialchars($newKey) ?></div>
                    <button type="button" class="btn btn-secondary" onclick="copyToClipboard('<?= htmlspecialchars($newKey) ?>')">
                        Copy to Clipboard
                    </button>
                    <p class="warning">This key will only be shown once. Store it securely.</p>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h2>Create New API Key</h2>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <input type="hidden" name="action" value="create">

                        <div class="form-group">
                            <label for="name">Key Name</label>
                            <input type="text" id="name" name="name" class="form-input" required
                                   placeholder="e.g., My Integration, CI/CD Pipeline">
                        </div>

                        <div class="form-group">
                            <label>Permissions</label>
                            <div class="checkbox-group">
                                <label>
                                    <input type="checkbox" name="permissions[]" value="read" checked>
                                    Read (view models, categories, tags)
                                </label>
                                <label>
                                    <input type="checkbox" name="permissions[]" value="write">
                                    Write (create, update models)
                                </label>
                                <label>
                                    <input type="checkbox" name="permissions[]" value="delete">
                                    Delete (remove models)
                                </label>
                                <label>
                                    <input type="checkbox" name="permissions[]" value="admin">
                                    Admin (manage webhooks, categories)
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="expires_in">Expiration</label>
                            <select id="expires_in" name="expires_in" class="form-input">
                                <option value="">Never expires</option>
                                <option value="30 days">30 days</option>
                                <option value="90 days">90 days</option>
                                <option value="180 days">180 days</option>
                                <option value="1 year">1 year</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary">Create API Key</button>
                    </form>
                </div>
            </div>

            <div class="card" style="margin-top: 1.5rem;">
                <div class="card-header">
                    <h2>Existing API Keys</h2>
                </div>
                <div class="card-body">
                    <?php if (empty($apiKeys)): ?>
                        <p class="text-muted">No API keys have been created yet.</p>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>User</th>
                                    <th>Key Prefix</th>
                                    <th>Permissions</th>
                                    <th>Requests</th>
                                    <th>Last Used</th>
                                    <th>Expires</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($apiKeys as $key): ?>
                                    <tr class="<?= $key['is_active'] ? '' : 'key-inactive' ?>">
                                        <td><?= htmlspecialchars($key['name']) ?></td>
                                        <td><?= htmlspecialchars($key['username']) ?></td>
                                        <td><code><?= htmlspecialchars($key['key_prefix']) ?>...</code></td>
                                        <td>
                                            <?php foreach ($key['permissions_array'] as $perm): ?>
                                                <span class="permission-badge <?= $perm ?>"><?= $perm ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td><?= number_format($key['request_count']) ?></td>
                                        <td><?= $key['last_used_at'] ? date('M j, Y H:i', strtotime($key['last_used_at'])) : 'Never' ?></td>
                                        <td>
                                            <?php if ($key['expires_at']): ?>
                                                <?= date('M j, Y', strtotime($key['expires_at'])) ?>
                                            <?php else: ?>
                                                Never
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($key['is_active']): ?>
                                                <form method="post" action="" style="display:inline"
                                                      onsubmit="return confirm('Are you sure you want to revoke this API key?')">
                                                    <input type="hidden" name="action" value="revoke">
                                                    <input type="hidden" name="key_id" value="<?= $key['id'] ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">Revoked</span>
                                            <?php endif; ?>
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
                    <h2>API Documentation</h2>
                </div>
                <div class="card-body">
                    <h4>Authentication</h4>
                    <p>Include your API key in the <code>X-API-Key</code> header:</p>
                    <pre><code>curl -H "X-API-Key: silo_your_key_here" <?= rtrim(getSetting('site_url', ''), '/') ?>/api/models</code></pre>

                    <h4 style="margin-top: 1rem;">Endpoints</h4>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th>Endpoint</th>
                                <th>Description</th>
                                <th>Permission</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>GET</td><td>/api/models</td><td>List models</td><td>read</td></tr>
                            <tr><td>GET</td><td>/api/models/{id}</td><td>Get model details</td><td>read</td></tr>
                            <tr><td>POST</td><td>/api/models</td><td>Upload model</td><td>write</td></tr>
                            <tr><td>PUT</td><td>/api/models/{id}</td><td>Update model</td><td>write</td></tr>
                            <tr><td>DELETE</td><td>/api/models/{id}</td><td>Delete model</td><td>delete</td></tr>
                            <tr><td>GET</td><td>/api/categories</td><td>List categories</td><td>read</td></tr>
                            <tr><td>GET</td><td>/api/tags</td><td>List tags</td><td>read</td></tr>
                            <tr><td>GET</td><td>/api/collections</td><td>List collections</td><td>read</td></tr>
                            <tr><td>GET</td><td>/api/stats</td><td>Get statistics</td><td>read</td></tr>
                            <tr><td>GET</td><td>/api/webhooks</td><td>List webhooks</td><td>admin</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <?php include __DIR__ . '/../../includes/footer.php'; ?>

    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('API key copied to clipboard!');
            });
        }
    </script>
</body>
</html>
