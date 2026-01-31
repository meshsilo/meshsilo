<?php
/**
 * OAuth2 Client Management
 *
 * Manage OAuth2 clients that can authenticate against Silo
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/OAuth2Provider.php';

// Require OAuth management permission
if (!isLoggedIn() || !canManageOAuth()) {
    $_SESSION['error'] = 'You do not have permission to manage OAuth2 clients.';
    header('Location: ' . route('admin.health'));
    exit;
}

$pageTitle = 'OAuth2 Clients';
$activePage = 'admin';
$adminPage = 'oauth-clients';

$message = '';
$error = '';
$newClientSecret = null;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create':
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $redirectUris = array_filter(array_map('trim', explode("\n", $_POST['redirect_uris'] ?? '')));
            $isConfidential = isset($_POST['is_confidential']);

            if (empty($name) || empty($redirectUris)) {
                $error = 'Name and at least one redirect URI are required.';
            } else {
                $result = OAuth2Provider::createClient($name, $redirectUris, $description, $isConfidential, getCurrentUser()['id']);
                $message = "Client created successfully. Client ID: <code>{$result['client_id']}</code>";
                $newClientSecret = $result['client_secret'];
                logActivity('oauth_client_created', 'oauth_client', null, $name);
            }
            break;

        case 'update':
            $clientId = $_POST['client_id'] ?? '';
            $data = [
                'name' => trim($_POST['name'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'redirect_uris' => array_filter(array_map('trim', explode("\n", $_POST['redirect_uris'] ?? ''))),
                'allowed_scopes' => trim($_POST['allowed_scopes'] ?? 'profile'),
                'is_active' => isset($_POST['is_active'])
            ];

            if (OAuth2Provider::updateClient($clientId, $data)) {
                $message = 'Client updated successfully.';
                logActivity('oauth_client_updated', 'oauth_client', null, $data['name']);
            } else {
                $error = 'Failed to update client.';
            }
            break;

        case 'delete':
            $clientId = $_POST['client_id'] ?? '';
            if (OAuth2Provider::deleteClient($clientId)) {
                $message = 'Client deleted successfully.';
                logActivity('oauth_client_deleted', 'oauth_client', null, $clientId);
            } else {
                $error = 'Failed to delete client.';
            }
            break;

        case 'regenerate_secret':
            $clientId = $_POST['client_id'] ?? '';
            $newSecret = OAuth2Provider::regenerateSecret($clientId);
            if ($newSecret) {
                $newClientSecret = $newSecret;
                $message = 'Client secret regenerated.';
                logActivity('oauth_client_secret_regenerated', 'oauth_client', null, $clientId);
            } else {
                $error = 'Failed to regenerate secret.';
            }
            break;

        case 'cleanup':
            $deleted = OAuth2Provider::cleanup();
            $message = "Cleaned up $deleted expired tokens.";
            break;
    }
}

// Get data
$clients = OAuth2Provider::getClients();
$stats = OAuth2Provider::getStats();
$scopes = OAuth2Provider::getScopes();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

<div class="admin-content">
    <div class="page-header">
        <h1>OAuth2 Clients</h1>
        <p>Manage applications that can authenticate users through Silo</p>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-success"><?= $message ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($newClientSecret): ?>
    <div class="alert alert-warning">
        <strong>Client Secret (save this now - it won't be shown again!):</strong><br>
        <code class="secret-display"><?= htmlspecialchars($newClientSecret) ?></code>
        <button type="button" class="btn btn-sm btn-secondary" onclick="copySecret(this)">Copy</button>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $stats['active_clients'] ?></div>
            <div class="stat-label">Active Clients</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['active_tokens'] ?></div>
            <div class="stat-label">Active Tokens</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['tokens_issued_today'] ?></div>
            <div class="stat-label">Tokens Today</div>
        </div>
    </div>

    <div class="admin-sections">
        <!-- Endpoint Info -->
        <div class="admin-section">
            <h2>OAuth2 Endpoints</h2>
            <div class="endpoint-grid">
                <div class="endpoint-item">
                    <label>Authorization Endpoint</label>
                    <code><?= url('/oauth/authorize') ?></code>
                </div>
                <div class="endpoint-item">
                    <label>Token Endpoint</label>
                    <code><?= url('/oauth/token') ?></code>
                </div>
                <div class="endpoint-item">
                    <label>User Info Endpoint</label>
                    <code><?= url('/oauth/userinfo') ?></code>
                </div>
                <div class="endpoint-item">
                    <label>Revocation Endpoint</label>
                    <code><?= url('/oauth/revoke') ?></code>
                </div>
            </div>
        </div>

        <!-- Create Client -->
        <div class="admin-section">
            <h2>Register New Client</h2>
            <form method="POST" class="client-form">
                <input type="hidden" name="action" value="create">

                <div class="form-row">
                    <div class="form-group">
                        <label for="name">Application Name *</label>
                        <input type="text" id="name" name="name" class="form-control" required
                               placeholder="My Application">
                    </div>

                    <div class="form-group">
                        <label class="checkbox-label" style="margin-top: 1.75rem;">
                            <input type="checkbox" name="is_confidential" checked>
                            Confidential Client
                        </label>
                        <small class="form-help">Uncheck for public clients (SPAs, mobile apps)</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <input type="text" id="description" name="description" class="form-control"
                           placeholder="Brief description of the application">
                </div>

                <div class="form-group">
                    <label for="redirect_uris">Redirect URIs * (one per line)</label>
                    <textarea id="redirect_uris" name="redirect_uris" class="form-control" rows="3" required
                              placeholder="https://example.com/callback&#10;http://localhost:3000/callback"></textarea>
                    <small class="form-help">Allowed callback URLs for OAuth flow</small>
                </div>

                <button type="submit" class="btn btn-primary">Create Client</button>
            </form>
        </div>

        <!-- Existing Clients -->
        <div class="admin-section">
            <h2>Registered Clients</h2>
            <div class="header-actions" style="margin-bottom: 1rem;">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="cleanup">
                    <button type="submit" class="btn btn-secondary">Cleanup Expired Tokens</button>
                </form>
            </div>

            <?php if (empty($clients)): ?>
            <p class="text-muted">No OAuth2 clients registered yet.</p>
            <?php else: ?>
            <div class="clients-list">
                <?php foreach ($clients as $client): ?>
                <div class="client-card <?= $client['is_active'] ? '' : 'client-inactive' ?>">
                    <div class="client-header">
                        <h3><?= htmlspecialchars($client['name']) ?></h3>
                        <span class="badge badge-<?= $client['is_active'] ? 'success' : 'secondary' ?>">
                            <?= $client['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </div>

                    <?php if ($client['description']): ?>
                    <p class="client-description"><?= htmlspecialchars($client['description']) ?></p>
                    <?php endif; ?>

                    <div class="client-details">
                        <div class="detail-item">
                            <label>Client ID</label>
                            <code><?= htmlspecialchars($client['id']) ?></code>
                        </div>
                        <div class="detail-item">
                            <label>Type</label>
                            <span><?= $client['is_confidential'] ? 'Confidential' : 'Public' ?></span>
                        </div>
                        <div class="detail-item">
                            <label>Redirect URIs</label>
                            <ul class="uri-list">
                                <?php foreach ($client['redirect_uris'] as $uri): ?>
                                <li><code><?= htmlspecialchars($uri) ?></code></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div class="detail-item">
                            <label>Created</label>
                            <span><?= date('M j, Y', strtotime($client['created_at'])) ?></span>
                        </div>
                    </div>

                    <div class="client-actions">
                        <button type="button" class="btn btn-sm btn-secondary"
                                onclick="editClient(<?= htmlspecialchars(json_encode($client)) ?>)">Edit</button>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="regenerate_secret">
                            <input type="hidden" name="client_id" value="<?= htmlspecialchars($client['id']) ?>">
                            <button type="submit" class="btn btn-sm btn-warning"
                                    onclick="return confirm('Regenerate client secret? The old secret will stop working immediately.')">
                                Regenerate Secret
                            </button>
                        </form>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="client_id" value="<?= htmlspecialchars($client['id']) ?>">
                            <button type="submit" class="btn btn-sm btn-danger"
                                    onclick="return confirm('Delete this client? All tokens will be revoked.')">
                                Delete
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Available Scopes -->
        <div class="admin-section">
            <h2>Available Scopes</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Scope</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scopes as $scope => $description): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($scope) ?></code></td>
                        <td><?= htmlspecialchars($description) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>

<!-- Edit Client Modal -->
<div id="edit-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Client</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="client_id" id="edit-client-id">

            <div class="modal-body">
                <div class="form-group">
                    <label for="edit-name">Application Name</label>
                    <input type="text" id="edit-name" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit-description">Description</label>
                    <input type="text" id="edit-description" name="description" class="form-control">
                </div>

                <div class="form-group">
                    <label for="edit-redirect-uris">Redirect URIs (one per line)</label>
                    <textarea id="edit-redirect-uris" name="redirect_uris" class="form-control" rows="3" required></textarea>
                </div>

                <div class="form-group">
                    <label for="edit-scopes">Allowed Scopes (space-separated)</label>
                    <input type="text" id="edit-scopes" name="allowed_scopes" class="form-control"
                           placeholder="profile models:read">
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_active" id="edit-active">
                        Client is active
                    </label>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.stat-card {
    background: var(--card-bg);
    padding: 1rem;
    border-radius: 8px;
    text-align: center;
}

.stat-value {
    font-size: 2rem;
    font-weight: bold;
    color: var(--primary-color);
}

.stat-label {
    color: var(--text-muted);
    font-size: 0.875rem;
}

.admin-sections {
    display: grid;
    gap: 1.5rem;
}

.admin-section {
    background: var(--card-bg);
    padding: 1.5rem;
    border-radius: 8px;
}

.admin-section h2 {
    margin-top: 0;
    margin-bottom: 1rem;
}

.endpoint-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 0.75rem;
}

.endpoint-item {
    background: var(--bg-color);
    padding: 0.75rem;
    border-radius: 4px;
}

.endpoint-item label {
    display: block;
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-bottom: 0.25rem;
}

.endpoint-item code {
    font-size: 0.85rem;
    word-break: break-all;
}

.client-form {
    max-width: 600px;
}

.form-row {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 1rem;
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.25rem;
    font-weight: 500;
}

.form-control {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    background: var(--bg-color);
    color: var(--text-color);
}

.form-help {
    display: block;
    margin-top: 0.25rem;
    color: var(--text-muted);
    font-size: 0.8rem;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.clients-list {
    display: grid;
    gap: 1rem;
}

.client-card {
    background: var(--bg-color);
    padding: 1rem;
    border-radius: 8px;
    border-left: 4px solid var(--primary-color);
}

.client-card.client-inactive {
    border-left-color: var(--text-muted);
    opacity: 0.7;
}

.client-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.client-header h3 {
    margin: 0;
    font-size: 1.1rem;
}

.badge {
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
}

.badge-success {
    background: rgba(34, 197, 94, 0.2);
    color: #22c55e;
}

.badge-secondary {
    background: rgba(107, 114, 128, 0.2);
    color: var(--text-muted);
}

.client-description {
    color: var(--text-muted);
    font-size: 0.9rem;
    margin-bottom: 0.75rem;
}

.client-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.detail-item label {
    display: block;
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-bottom: 0.25rem;
}

.detail-item code {
    font-size: 0.8rem;
    background: var(--card-bg);
    padding: 0.1rem 0.3rem;
    border-radius: 3px;
}

.uri-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.uri-list li {
    margin-bottom: 0.25rem;
}

.client-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.secret-display {
    display: inline-block;
    padding: 0.5rem;
    background: var(--bg-color);
    border-radius: 4px;
    font-size: 0.9rem;
    margin-right: 0.5rem;
    user-select: all;
}
</style>

<script>
function editClient(client) {
    document.getElementById('edit-client-id').value = client.id;
    document.getElementById('edit-name').value = client.name;
    document.getElementById('edit-description').value = client.description || '';
    document.getElementById('edit-redirect-uris').value = client.redirect_uris.join('\n');
    document.getElementById('edit-scopes').value = client.allowed_scopes || 'profile';
    document.getElementById('edit-active').checked = client.is_active == 1;
    document.getElementById('edit-modal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('edit-modal').style.display = 'none';
}

function copySecret(btn) {
    const code = btn.previousElementSibling;
    navigator.clipboard.writeText(code.textContent);
    btn.textContent = 'Copied!';
    setTimeout(() => btn.textContent = 'Copy', 2000);
}

document.getElementById('edit-modal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeEditModal();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
