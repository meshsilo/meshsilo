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
$pageTitle = 'API Keys';
$adminPage = 'api-keys';

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

include __DIR__ . '/../../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/../../includes/admin-sidebar.php'; ?>

    <div class="admin-content">
        <div class="page-header">
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
            <div class="api-key-created">
                <h3>API Key Created: <?= htmlspecialchars($newKeyName) ?></h3>
                <p>Your new API key has been created. Copy it now — you won't be able to see it again!</p>
                <div class="api-key-value-row">
                    <input type="text" class="api-key-full" id="newKeyValue" value="<?= htmlspecialchars($newKey, ENT_QUOTES) ?>" readonly onclick="this.select()">
                    <button type="button" class="btn btn-primary btn-sm" onclick="copyFullKey(this)">
                        Copy Key
                    </button>
                </div>
                <p class="api-key-warning">This key will only be shown once. Store it securely.</p>
            </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header">
                <h2>Create New API Key</h2>
            </div>
            <div class="card-body">
                <form method="post" action="">
                    <input type="hidden" name="action" value="create">
                    <?= csrf_field() ?>

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

        <div class="card mb-4">
            <div class="card-header">
                <h2>Existing API Keys</h2>
            </div>
            <div class="card-body">
                <?php if (empty($apiKeys)): ?>
                    <p class="text-muted">No API keys have been created yet.</p>
                <?php else: ?>
                    <div class="api-keys-list">
                        <?php foreach ($apiKeys as $key): ?>
                            <?php if (!$key['is_active']) continue; ?>
                            <div class="api-key-item">
                                <div class="api-key-header">
                                    <div class="api-key-info">
                                        <span class="api-key-name"><?= htmlspecialchars($key['name']) ?></span>
                                        <span class="api-key-user">by <?= htmlspecialchars($key['username']) ?></span>
                                    </div>
                                    <div class="api-key-status">
                                        <?php if ($key['expires_at'] && strtotime($key['expires_at']) < time()): ?>
                                            <span class="badge badge-expired">Expired</span>
                                        <?php else: ?>
                                            <span class="badge badge-active">Active</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="api-key-details">
                                    <div class="api-key-prefix-row">
                                        <span class="api-key-label">Key</span>
                                        <code class="api-key-prefix" title="Click to copy prefix" onclick="copyKey('<?= htmlspecialchars($key['key_prefix'], ENT_QUOTES) ?>...', this)">
                                            <?= htmlspecialchars($key['key_prefix']) ?>...
                                        </code>
                                    </div>

                                    <div class="api-key-meta">
                                        <div class="api-key-meta-item">
                                            <span class="api-key-label">Permissions</span>
                                            <span class="api-key-perms">
                                                <?php foreach ($key['permissions_array'] as $perm): ?>
                                                    <span class="perm-badge perm-<?= $perm ?>"><?= $perm ?></span>
                                                <?php endforeach; ?>
                                            </span>
                                        </div>
                                        <div class="api-key-meta-item">
                                            <span class="api-key-label">Requests</span>
                                            <span><?= number_format($key['request_count']) ?></span>
                                        </div>
                                        <div class="api-key-meta-item">
                                            <span class="api-key-label">Last used</span>
                                            <span><?= $key['last_used_at'] ? date('M j, Y H:i', strtotime($key['last_used_at'])) : 'Never' ?></span>
                                        </div>
                                        <div class="api-key-meta-item">
                                            <span class="api-key-label">Expires</span>
                                            <span><?= $key['expires_at'] ? date('M j, Y', strtotime($key['expires_at'])) : 'Never' ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="api-key-actions">
                                    <form method="post" action="" style="display:inline"
                                          onsubmit="return confirm('Are you sure you want to revoke this API key? This cannot be undone.')">
                                        <input type="hidden" name="action" value="revoke">
                                        <input type="hidden" name="key_id" value="<?= $key['id'] ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>API Documentation</h2>
            </div>
            <div class="card-body">
                <h4>Authentication</h4>
                <p>Include your API key in the <code>X-API-Key</code> header:</p>
                <pre class="api-code-block"><code>curl -H "X-API-Key: silo_your_key_here" <?= rtrim(getSetting('site_url', ''), '/') ?>/api/models</code></pre>

                <h4 style="margin-top: 1.5rem;">Endpoints</h4>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Method</th>
                            <th>Endpoint</th>
                            <th>Description</th>
                            <th>Permission</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><code>GET</code></td><td><code>/api/models</code></td><td>List models</td><td><span class="perm-badge perm-read">read</span></td></tr>
                        <tr><td><code>GET</code></td><td><code>/api/models/{id}</code></td><td>Get model details</td><td><span class="perm-badge perm-read">read</span></td></tr>
                        <tr><td><code>POST</code></td><td><code>/api/models</code></td><td>Upload model</td><td><span class="perm-badge perm-write">write</span></td></tr>
                        <tr><td><code>PUT</code></td><td><code>/api/models/{id}</code></td><td>Update model</td><td><span class="perm-badge perm-write">write</span></td></tr>
                        <tr><td><code>DELETE</code></td><td><code>/api/models/{id}</code></td><td>Delete model</td><td><span class="perm-badge perm-delete">delete</span></td></tr>
                        <tr><td><code>GET</code></td><td><code>/api/categories</code></td><td>List categories</td><td><span class="perm-badge perm-read">read</span></td></tr>
                        <tr><td><code>GET</code></td><td><code>/api/tags</code></td><td>List tags</td><td><span class="perm-badge perm-read">read</span></td></tr>
                        <tr><td><code>GET</code></td><td><code>/api/collections</code></td><td>List collections</td><td><span class="perm-badge perm-read">read</span></td></tr>
                        <tr><td><code>GET</code></td><td><code>/api/stats</code></td><td>Get statistics</td><td><span class="perm-badge perm-read">read</span></td></tr>
                        <tr><td><code>GET</code></td><td><code>/api/webhooks</code></td><td>List webhooks</td><td><span class="perm-badge perm-admin">admin</span></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
/* New key display */
.api-key-created {
    background: var(--color-surface);
    border: 2px solid var(--color-success);
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.api-key-created h3 {
    margin: 0 0 0.5rem;
    font-size: 1rem;
}

.api-key-value-row {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    margin: 0.75rem 0;
}

.api-key-full {
    display: block;
    width: 100%;
    box-sizing: border-box;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
    font-size: 1.15rem;
    line-height: 1.5;
    background: var(--color-surface-hover);
    color: var(--color-text);
    border: 2px solid var(--color-border);
    padding: 1rem 1.25rem;
    border-radius: 6px;
    letter-spacing: 0.04em;
    cursor: text;
}

.api-key-full:focus {
    outline: none;
    border-color: var(--color-primary);
}

.api-key-warning {
    color: var(--color-warning);
    font-size: 0.85rem;
    margin: 0.5rem 0 0;
}

/* Key list */
.api-keys-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.api-key-item {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 8px;
    padding: 1rem 1.25rem;
}

.api-key-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}

.api-key-info {
    display: flex;
    align-items: baseline;
    gap: 0.5rem;
}

.api-key-name {
    font-weight: 600;
    font-size: 1rem;
    color: var(--color-text);
}

.api-key-user {
    font-size: 0.8rem;
    color: var(--color-text-muted);
}

.api-key-details {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.api-key-prefix-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.api-key-prefix {
    font-family: monospace;
    font-size: 0.9rem;
    background: var(--color-surface-hover);
    padding: 0.4rem 0.75rem;
    border-radius: 4px;
    cursor: pointer;
    user-select: all;
    letter-spacing: 0.03em;
    transition: background 0.15s;
}

.api-key-prefix:hover {
    background: var(--color-primary);
    color: white;
}

.api-key-prefix.copied {
    background: var(--color-success);
    color: white;
}

.api-key-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--color-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.03em;
    min-width: 80px;
}

.api-key-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 1.25rem;
}

.api-key-meta-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
}

.api-key-perms {
    display: flex;
    gap: 0.3rem;
    flex-wrap: wrap;
}

.api-key-actions {
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--color-border);
}

/* Permission badges */
.perm-badge {
    display: inline-block;
    padding: 0.15rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
}

.perm-read { background: #3b82f6; color: white; }
.perm-write { background: #10b981; color: white; }
.perm-delete { background: #f59e0b; color: black; }
.perm-admin { background: #ef4444; color: white; }

/* Status badges */
.badge {
    display: inline-block;
    padding: 0.2rem 0.6rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
}

.badge-active { background: #10b981; color: white; }
.badge-expired { background: #ef4444; color: white; }

/* Form */
.checkbox-group {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
}

.checkbox-group label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
}

/* Code block */
.api-code-block {
    background: var(--color-surface-hover);
    padding: 0.75rem 1rem;
    border-radius: 6px;
    overflow-x: auto;
    font-size: 0.85rem;
}

.mb-4 { margin-bottom: 1.5rem; }

/* Responsive */
@media (max-width: 768px) {
    .api-key-value-row {
        flex-direction: column;
        align-items: stretch;
    }

    .api-key-meta {
        flex-direction: column;
        gap: 0.5rem;
    }

    .api-key-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }

    .checkbox-group {
        flex-direction: column;
    }
}
</style>

<script>
function copyFullKey(btn) {
    var el = document.getElementById('newKeyValue');
    if (!el) return;
    el.select();
    var text = el.value;
    navigator.clipboard.writeText(text).then(function() {
        btn.textContent = 'Copied!';
        btn.classList.add('btn-success');
        btn.classList.remove('btn-primary');
        setTimeout(function() {
            btn.textContent = 'Copy Key';
            btn.classList.remove('btn-success');
            btn.classList.add('btn-primary');
        }, 2000);
    });
}

function copyKey(text, btn) {
    navigator.clipboard.writeText(text).then(function() {
        if (btn.tagName === 'CODE') {
            btn.classList.add('copied');
            setTimeout(function() { btn.classList.remove('copied'); }, 1500);
        } else {
            var orig = btn.textContent;
            btn.textContent = 'Copied!';
            setTimeout(function() { btn.textContent = orig; }, 1500);
        }
    });
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
