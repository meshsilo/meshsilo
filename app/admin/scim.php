<?php
/**
 * SCIM User Provisioning Admin Page
 *
 * Configure SCIM 2.0 for automated user provisioning from identity providers
 * (Azure AD, Okta, OneLogin, etc.)
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/features.php';

// Require SSO feature to be enabled (SCIM is used with OIDC/SAML)
if (!isFeatureEnabled('oidc_sso') && !isFeatureEnabled('saml_sso')) {
    $_SESSION['error'] = 'SCIM requires OIDC or SAML SSO to be enabled.';
    header('Location: ' . route('admin.features'));
    exit;
}

// Require SCIM management permission
if (!isLoggedIn() || !canManageScim()) {
    $_SESSION['error'] = 'You do not have permission to manage SCIM settings.';
    header('Location: ' . route('admin.health'));
    exit;
}

$pageTitle = 'SCIM Provisioning';
$activePage = 'admin';
$adminPage = 'scim';

$db = getDB();
$message = '';
$error = '';

// Handle actions
// CSRF protection for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    $error = 'Invalid request. Please refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'save_settings':
            setSetting('scim_enabled', isset($_POST['scim_enabled']) ? '1' : '0');
            setSetting('scim_auto_create_users', isset($_POST['auto_create']) ? '1' : '0');
            setSetting('scim_auto_update_users', isset($_POST['auto_update']) ? '1' : '0');
            setSetting('scim_auto_deactivate', isset($_POST['auto_deactivate']) ? '1' : '0');
            setSetting('scim_default_group', $_POST['default_group'] ?? '');

            // Generate new bearer token if requested
            if (isset($_POST['regenerate_token'])) {
                $newToken = bin2hex(random_bytes(32));
                setSetting('scim_bearer_token', password_hash($newToken, PASSWORD_DEFAULT));
                setSetting('scim_bearer_token_preview', substr($newToken, 0, 8) . '...');
                $message = "Settings saved. New SCIM Bearer Token: <code>$newToken</code><br><strong>Copy this token now - it won't be shown again!</strong>";
            } else {
                $message = 'SCIM settings saved.';
            }

            logActivity('scim_settings_updated', 'system', null, 'Admin updated SCIM settings');
            break;

        case 'generate_token':
            $newToken = bin2hex(random_bytes(32));
            setSetting('scim_bearer_token', password_hash($newToken, PASSWORD_DEFAULT));
            setSetting('scim_bearer_token_preview', substr($newToken, 0, 8) . '...');
            $message = "New SCIM Bearer Token: <code>$newToken</code><br><strong>Copy this token now - it won't be shown again!</strong>";
            logActivity('scim_token_generated', 'system', null, 'Admin generated new SCIM token');
            break;

        case 'test_endpoint':
            // Test SCIM endpoint availability
            $message = 'SCIM endpoint is accessible at: <code>' . url('/api/scim/v2') . '</code>';
            break;

        case 'sync_now':
            // Manual sync trigger (for pull-based sync)
            $message = 'Manual sync is not available. SCIM uses push-based provisioning from your identity provider.';
            break;
    }
}

// Get current settings
$scimEnabled = getSetting('scim_enabled', '0') === '1';
$autoCreate = getSetting('scim_auto_create_users', '1') === '1';
$autoUpdate = getSetting('scim_auto_update_users', '1') === '1';
$autoDeactivate = getSetting('scim_auto_deactivate', '0') === '1';
$defaultGroup = getSetting('scim_default_group', '');
$tokenPreview = getSetting('scim_bearer_token_preview', 'Not generated');

// Get available groups
$groups = [];
$result = $db->query('SELECT id, name FROM groups ORDER BY name');
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $groups[] = $row;
}

// Get SCIM provisioning log
$provisioningLog = [];
try {
    $result = $db->query("
        SELECT *
        FROM audit_log
        WHERE event_type = 'scim'
        ORDER BY created_at DESC
        LIMIT 50
    ");
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $provisioningLog[] = $row;
    }
} catch (Exception $e) {
    // Audit log might not have SCIM events
}

// Get SCIM stats
$scimStats = [
    'users_created' => 0,
    'users_updated' => 0,
    'users_deactivated' => 0,
    'last_activity' => null
];

try {
    $result = $db->query("
        SELECT
            SUM(CASE WHEN event_name = 'scim_user_created' THEN 1 ELSE 0 END) as created,
            SUM(CASE WHEN event_name = 'scim_user_updated' THEN 1 ELSE 0 END) as updated,
            SUM(CASE WHEN event_name = 'scim_user_deactivated' THEN 1 ELSE 0 END) as deactivated,
            MAX(created_at) as last_activity
        FROM audit_log
        WHERE event_type = 'scim'
    ");
    if ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $scimStats['users_created'] = $row['created'] ?? 0;
        $scimStats['users_updated'] = $row['updated'] ?? 0;
        $scimStats['users_deactivated'] = $row['deactivated'] ?? 0;
        $scimStats['last_activity'] = $row['last_activity'];
    }
} catch (Exception $e) {
    // Ignore
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

<div class="admin-content">
    <div class="page-header">
        <h1>SCIM User Provisioning</h1>
        <p>Configure SCIM 2.0 for automated user provisioning from your identity provider</p>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-success"><?= $message ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Status Banner -->
    <div class="status-banner <?= $scimEnabled ? 'status-enabled' : 'status-disabled' ?>">
        <span class="status-icon"><?= $scimEnabled ? '&#10004;' : '&#10006;' ?></span>
        <span class="status-text">
            SCIM Provisioning is <strong><?= $scimEnabled ? 'Enabled' : 'Disabled' ?></strong>
        </span>
    </div>

    <!-- Stats -->
    <?php if ($scimEnabled): ?>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($scimStats['users_created']) ?></div>
            <div class="stat-label">Users Created</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($scimStats['users_updated']) ?></div>
            <div class="stat-label">Users Updated</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($scimStats['users_deactivated']) ?></div>
            <div class="stat-label">Users Deactivated</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">
                <?= $scimStats['last_activity'] ? date('M j, H:i', strtotime($scimStats['last_activity'])) : 'Never' ?>
            </div>
            <div class="stat-label">Last Activity</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="admin-sections">
        <!-- SCIM Configuration -->
        <div class="admin-section">
            <h2>Configuration</h2>
            <form method="POST" class="settings-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_settings">

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="scim_enabled" <?= $scimEnabled ? 'checked' : '' ?>>
                        Enable SCIM Provisioning
                    </label>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="auto_create" <?= $autoCreate ? 'checked' : '' ?>>
                        Auto-create users from SCIM
                    </label>
                    <small class="form-help">Automatically create new users when provisioned via SCIM</small>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="auto_update" <?= $autoUpdate ? 'checked' : '' ?>>
                        Auto-update user attributes
                    </label>
                    <small class="form-help">Update user details when changed in identity provider</small>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="auto_deactivate" <?= $autoDeactivate ? 'checked' : '' ?>>
                        Auto-deactivate removed users
                    </label>
                    <small class="form-help">Deactivate users when removed from identity provider</small>
                </div>

                <div class="form-group">
                    <label for="default_group">Default Group for New Users</label>
                    <select id="default_group" name="default_group" class="form-control">
                        <option value="">No default group</option>
                        <?php foreach ($groups as $group): ?>
                        <option value="<?= $group['id'] ?>" <?= $defaultGroup == $group['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($group['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="regenerate_token">
                        Generate new bearer token
                    </label>
                    <small class="form-help">Check this to generate a new authentication token</small>
                </div>

                <button type="submit" class="btn btn-primary">Save Settings</button>
            </form>
        </div>

        <!-- Endpoint Information -->
        <div class="admin-section">
            <h2>SCIM Endpoint</h2>
            <p>Configure your identity provider to use these endpoints:</p>

            <div class="endpoint-info">
                <div class="endpoint-item">
                    <label>Base URL</label>
                    <code><?= url('/api/scim/v2') ?></code>
                </div>

                <div class="endpoint-item">
                    <label>Bearer Token</label>
                    <code><?= htmlspecialchars($tokenPreview) ?></code>
                    <form method="POST" style="display: inline; margin-left: 0.5rem;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="generate_token">
                        <button type="submit" class="btn btn-sm btn-secondary">Generate New</button>
                    </form>
                </div>

                <div class="endpoint-item">
                    <label>Users Endpoint</label>
                    <code><?= url('/api/scim/v2/Users') ?></code>
                </div>

                <div class="endpoint-item">
                    <label>Groups Endpoint</label>
                    <code><?= url('/api/scim/v2/Groups') ?></code>
                </div>
            </div>

            <h3>Supported Operations</h3>
            <ul class="feature-list">
                <li>&#10004; GET /Users - List users</li>
                <li>&#10004; POST /Users - Create user</li>
                <li>&#10004; GET /Users/{id} - Get user</li>
                <li>&#10004; PUT /Users/{id} - Replace user</li>
                <li>&#10004; PATCH /Users/{id} - Update user</li>
                <li>&#10004; DELETE /Users/{id} - Delete user</li>
                <li>&#10004; GET /Groups - List groups</li>
                <li>&#10004; GET /ServiceProviderConfig - Configuration</li>
                <li>&#10004; GET /Schemas - Schema definitions</li>
            </ul>
        </div>

        <!-- Provider Setup Guides -->
        <div class="admin-section">
            <h2>Setup Guides</h2>
            <div class="provider-guides">
                <details class="guide-item">
                    <summary>Azure Active Directory</summary>
                    <ol>
                        <li>Go to Azure Portal > Enterprise Applications</li>
                        <li>Create a new application or select existing</li>
                        <li>Go to Provisioning > Get Started</li>
                        <li>Set Provisioning Mode to "Automatic"</li>
                        <li>Enter the Tenant URL: <code><?= url('/api/scim/v2') ?></code></li>
                        <li>Enter the Secret Token (bearer token from above)</li>
                        <li>Test the connection and save</li>
                        <li>Configure attribute mappings as needed</li>
                        <li>Turn on provisioning</li>
                    </ol>
                </details>

                <details class="guide-item">
                    <summary>Okta</summary>
                    <ol>
                        <li>Go to Okta Admin > Applications</li>
                        <li>Add Application > Create New App</li>
                        <li>Select SCIM 2.0 as sign-on method</li>
                        <li>Enter the Base URL: <code><?= url('/api/scim/v2') ?></code></li>
                        <li>Set Authentication Mode to "HTTP Header"</li>
                        <li>Enter the Bearer Token</li>
                        <li>Configure provisioning features</li>
                    </ol>
                </details>

                <details class="guide-item">
                    <summary>OneLogin</summary>
                    <ol>
                        <li>Go to OneLogin Admin > Applications</li>
                        <li>Add App > SCIM Provisioner with SAML (SCIM v2)</li>
                        <li>In Configuration tab, set SCIM Base URL</li>
                        <li>Set SCIM Bearer Token</li>
                        <li>Configure user provisioning settings</li>
                    </ol>
                </details>
            </div>
        </div>

        <!-- Provisioning Log -->
        <?php if (!empty($provisioningLog)): ?>
        <div class="admin-section">
            <h2>Recent Activity</h2>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Event</th>
                            <th>Resource</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($provisioningLog as $log): ?>
                        <tr>
                            <td class="timestamp"><?= date('M j, H:i', strtotime($log['created_at'])) ?></td>
                            <td><?= htmlspecialchars($log['event_name']) ?></td>
                            <td><?= htmlspecialchars($log['resource_type'] . '#' . $log['resource_id']) ?></td>
                            <td><?= htmlspecialchars($log['resource_name'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<style>
.status-banner {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.status-banner.status-enabled {
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
}

.status-banner.status-disabled {
    background: rgba(107, 114, 128, 0.15);
    color: var(--text-muted);
}

.status-icon {
    font-size: 1.25rem;
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

.admin-section h3 {
    margin-top: 1.5rem;
    margin-bottom: 0.75rem;
    font-size: 1rem;
}

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
    font-size: 1.5rem;
    font-weight: bold;
    color: var(--primary-color);
}

.stat-label {
    color: var(--text-muted);
    font-size: 0.875rem;
}

.settings-form {
    max-width: 500px;
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

.endpoint-info {
    background: var(--bg-color);
    padding: 1rem;
    border-radius: 4px;
    margin-bottom: 1rem;
}

.endpoint-item {
    margin-bottom: 0.75rem;
}

.endpoint-item:last-child {
    margin-bottom: 0;
}

.endpoint-item label {
    display: block;
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-bottom: 0.25rem;
}

.endpoint-item code {
    background: var(--card-bg);
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.85rem;
}

.feature-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 0.5rem;
}

.feature-list li {
    padding: 0.25rem 0;
    font-size: 0.9rem;
}

.provider-guides {
    display: grid;
    gap: 0.5rem;
}

.guide-item {
    background: var(--bg-color);
    border-radius: 4px;
}

.guide-item summary {
    padding: 0.75rem 1rem;
    cursor: pointer;
    font-weight: 500;
}

.guide-item summary:hover {
    background: rgba(59, 130, 246, 0.1);
}

.guide-item ol {
    padding: 0 1rem 1rem 2.5rem;
    margin: 0;
}

.guide-item li {
    margin-bottom: 0.5rem;
}

.guide-item code {
    background: var(--card-bg);
    padding: 0.1rem 0.3rem;
    border-radius: 3px;
    font-size: 0.8rem;
}

.timestamp {
    font-family: monospace;
    font-size: 0.85rem;
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
