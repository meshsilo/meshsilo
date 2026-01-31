<?php
/**
 * LDAP/Active Directory Integration
 *
 * Configure LDAP authentication and user synchronization
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/permissions.php';

// Require LDAP management permission
if (!isLoggedIn() || !canManageLdap()) {
    $_SESSION['error'] = 'You do not have permission to manage LDAP settings.';
    header('Location: ' . route('admin.health'));
    exit;
}

$pageTitle = 'LDAP Integration';
$activePage = 'admin';
$adminPage = 'ldap';

$db = getDB();
$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'save_settings':
            setSetting('ldap_enabled', isset($_POST['ldap_enabled']) ? '1' : '0');
            setSetting('ldap_host', trim($_POST['ldap_host'] ?? ''));
            setSetting('ldap_port', (int)($_POST['ldap_port'] ?? 389));
            setSetting('ldap_use_ssl', isset($_POST['ldap_ssl']) ? '1' : '0');
            setSetting('ldap_use_tls', isset($_POST['ldap_tls']) ? '1' : '0');
            setSetting('ldap_base_dn', trim($_POST['ldap_base_dn'] ?? ''));
            setSetting('ldap_bind_dn', trim($_POST['ldap_bind_dn'] ?? ''));

            // Only update password if provided
            if (!empty($_POST['ldap_bind_password'])) {
                setSetting('ldap_bind_password', $_POST['ldap_bind_password']);
            }

            setSetting('ldap_user_filter', trim($_POST['ldap_user_filter'] ?? ''));
            setSetting('ldap_group_filter', trim($_POST['ldap_group_filter'] ?? ''));
            setSetting('ldap_username_attr', trim($_POST['ldap_username_attr'] ?? 'sAMAccountName'));
            setSetting('ldap_email_attr', trim($_POST['ldap_email_attr'] ?? 'mail'));
            setSetting('ldap_name_attr', trim($_POST['ldap_name_attr'] ?? 'displayName'));
            setSetting('ldap_group_attr', trim($_POST['ldap_group_attr'] ?? 'memberOf'));

            setSetting('ldap_auto_create_users', isset($_POST['auto_create']) ? '1' : '0');
            setSetting('ldap_auto_sync_groups', isset($_POST['auto_sync_groups']) ? '1' : '0');
            setSetting('ldap_default_group', $_POST['default_group'] ?? '');

            $message = 'LDAP settings saved.';
            logActivity('ldap_settings_updated', 'system', null, 'Admin updated LDAP settings');
            break;

        case 'test_connection':
            $testResult = testLDAPConnection();
            if ($testResult['success']) {
                $message = 'LDAP connection successful! ' . ($testResult['user_count'] ?? 0) . ' users found.';
            } else {
                $error = 'LDAP connection failed: ' . $testResult['error'];
            }
            break;

        case 'sync_users':
            $syncResult = syncAllLDAPUsers();
            if ($syncResult['success']) {
                $message = "Sync complete: {$syncResult['created']} created, {$syncResult['updated']} updated, {$syncResult['deactivated']} deactivated.";
            } else {
                $error = 'Sync failed: ' . $syncResult['error'];
            }
            break;

        case 'add_group_mapping':
            $ldapGroup = trim($_POST['ldap_group'] ?? '');
            $siloGroup = (int)($_POST['silo_group'] ?? 0);
            if ($ldapGroup && $siloGroup) {
                $mappings = json_decode(getSetting('ldap_group_mappings', '[]'), true);
                $mappings[$ldapGroup] = $siloGroup;
                setSetting('ldap_group_mappings', json_encode($mappings));
                $message = 'Group mapping added.';
            }
            break;

        case 'remove_group_mapping':
            $ldapGroup = $_POST['ldap_group'] ?? '';
            $mappings = json_decode(getSetting('ldap_group_mappings', '[]'), true);
            unset($mappings[$ldapGroup]);
            setSetting('ldap_group_mappings', json_encode($mappings));
            $message = 'Group mapping removed.';
            break;
    }
}

// Get current settings
$ldapEnabled = getSetting('ldap_enabled', '0') === '1';
$ldapHost = getSetting('ldap_host', '');
$ldapPort = getSetting('ldap_port', '389');
$ldapSsl = getSetting('ldap_use_ssl', '0') === '1';
$ldapTls = getSetting('ldap_use_tls', '0') === '1';
$ldapBaseDn = getSetting('ldap_base_dn', '');
$ldapBindDn = getSetting('ldap_bind_dn', '');
$ldapUserFilter = getSetting('ldap_user_filter', '(&(objectClass=user)(objectCategory=person))');
$ldapGroupFilter = getSetting('ldap_group_filter', '(objectClass=group)');
$ldapUsernameAttr = getSetting('ldap_username_attr', 'sAMAccountName');
$ldapEmailAttr = getSetting('ldap_email_attr', 'mail');
$ldapNameAttr = getSetting('ldap_name_attr', 'displayName');
$ldapGroupAttr = getSetting('ldap_group_attr', 'memberOf');
$autoCreate = getSetting('ldap_auto_create_users', '1') === '1';
$autoSyncGroups = getSetting('ldap_auto_sync_groups', '0') === '1';
$defaultGroup = getSetting('ldap_default_group', '');

$groupMappings = json_decode(getSetting('ldap_group_mappings', '[]'), true);

// Get available groups
$groups = [];
$result = $db->query('SELECT id, name FROM groups ORDER BY name');
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $groups[] = $row;
}

// Check LDAP extension
$ldapExtensionLoaded = extension_loaded('ldap');

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

<div class="admin-content">
    <div class="page-header">
        <h1>LDAP/Active Directory</h1>
        <p>Configure LDAP authentication and user synchronization</p>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!$ldapExtensionLoaded): ?>
    <div class="alert alert-warning">
        <strong>PHP LDAP Extension Not Installed</strong><br>
        The PHP LDAP extension is required for LDAP integration. Install it with:<br>
        <code>apt install php-ldap</code> (Debian/Ubuntu) or <code>yum install php-ldap</code> (CentOS/RHEL)
    </div>
    <?php endif; ?>

    <!-- Status Banner -->
    <div class="status-banner <?= $ldapEnabled && $ldapExtensionLoaded ? 'status-enabled' : 'status-disabled' ?>">
        <span class="status-icon"><?= $ldapEnabled && $ldapExtensionLoaded ? '&#10004;' : '&#10006;' ?></span>
        <span class="status-text">
            LDAP Authentication is <strong><?= $ldapEnabled ? 'Enabled' : 'Disabled' ?></strong>
        </span>
        <?php if ($ldapEnabled && $ldapExtensionLoaded): ?>
        <div class="status-actions">
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="test_connection">
                <button type="submit" class="btn btn-sm btn-secondary">Test Connection</button>
            </form>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="sync_users">
                <button type="submit" class="btn btn-sm btn-primary">Sync Users Now</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <div class="admin-sections">
        <!-- Connection Settings -->
        <div class="admin-section">
            <h2>Connection Settings</h2>
            <form method="POST" class="settings-form">
                <input type="hidden" name="action" value="save_settings">

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="ldap_enabled" <?= $ldapEnabled ? 'checked' : '' ?>>
                        Enable LDAP Authentication
                    </label>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="ldap_host">LDAP Host</label>
                        <input type="text" id="ldap_host" name="ldap_host" class="form-control"
                               value="<?= htmlspecialchars($ldapHost) ?>" placeholder="ldap.example.com">
                    </div>

                    <div class="form-group">
                        <label for="ldap_port">Port</label>
                        <input type="number" id="ldap_port" name="ldap_port" class="form-control"
                               value="<?= $ldapPort ?>" placeholder="389">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="ldap_ssl" <?= $ldapSsl ? 'checked' : '' ?>>
                            Use LDAPS (SSL, port 636)
                        </label>
                    </div>

                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="ldap_tls" <?= $ldapTls ? 'checked' : '' ?>>
                            Use StartTLS
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="ldap_base_dn">Base DN</label>
                    <input type="text" id="ldap_base_dn" name="ldap_base_dn" class="form-control"
                           value="<?= htmlspecialchars($ldapBaseDn) ?>" placeholder="DC=example,DC=com">
                    <small class="form-help">The base distinguished name to search from</small>
                </div>

                <div class="form-group">
                    <label for="ldap_bind_dn">Bind DN (Service Account)</label>
                    <input type="text" id="ldap_bind_dn" name="ldap_bind_dn" class="form-control"
                           value="<?= htmlspecialchars($ldapBindDn) ?>" placeholder="CN=ServiceAccount,OU=Users,DC=example,DC=com">
                    <small class="form-help">Service account used to search the directory</small>
                </div>

                <div class="form-group">
                    <label for="ldap_bind_password">Bind Password</label>
                    <input type="password" id="ldap_bind_password" name="ldap_bind_password" class="form-control"
                           placeholder="<?= getSetting('ldap_bind_password', '') ? '••••••••' : 'Enter password' ?>">
                    <small class="form-help">Leave blank to keep existing password</small>
                </div>

                <h3>Search Filters</h3>

                <div class="form-group">
                    <label for="ldap_user_filter">User Filter</label>
                    <input type="text" id="ldap_user_filter" name="ldap_user_filter" class="form-control"
                           value="<?= htmlspecialchars($ldapUserFilter) ?>">
                    <small class="form-help">LDAP filter for finding users</small>
                </div>

                <div class="form-group">
                    <label for="ldap_group_filter">Group Filter</label>
                    <input type="text" id="ldap_group_filter" name="ldap_group_filter" class="form-control"
                           value="<?= htmlspecialchars($ldapGroupFilter) ?>">
                    <small class="form-help">LDAP filter for finding groups</small>
                </div>

                <h3>Attribute Mapping</h3>

                <div class="form-row">
                    <div class="form-group">
                        <label for="ldap_username_attr">Username Attribute</label>
                        <input type="text" id="ldap_username_attr" name="ldap_username_attr" class="form-control"
                               value="<?= htmlspecialchars($ldapUsernameAttr) ?>" placeholder="sAMAccountName">
                    </div>

                    <div class="form-group">
                        <label for="ldap_email_attr">Email Attribute</label>
                        <input type="text" id="ldap_email_attr" name="ldap_email_attr" class="form-control"
                               value="<?= htmlspecialchars($ldapEmailAttr) ?>" placeholder="mail">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="ldap_name_attr">Display Name Attribute</label>
                        <input type="text" id="ldap_name_attr" name="ldap_name_attr" class="form-control"
                               value="<?= htmlspecialchars($ldapNameAttr) ?>" placeholder="displayName">
                    </div>

                    <div class="form-group">
                        <label for="ldap_group_attr">Group Membership Attribute</label>
                        <input type="text" id="ldap_group_attr" name="ldap_group_attr" class="form-control"
                               value="<?= htmlspecialchars($ldapGroupAttr) ?>" placeholder="memberOf">
                    </div>
                </div>

                <h3>User Provisioning</h3>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="auto_create" <?= $autoCreate ? 'checked' : '' ?>>
                        Auto-create users on first login
                    </label>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="auto_sync_groups" <?= $autoSyncGroups ? 'checked' : '' ?>>
                        Auto-sync group memberships
                    </label>
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

                <button type="submit" class="btn btn-primary">Save Settings</button>
            </form>
        </div>

        <!-- Group Mappings -->
        <div class="admin-section">
            <h2>Group Mappings</h2>
            <p>Map LDAP groups to Silo permission groups</p>

            <form method="POST" class="add-mapping-form">
                <input type="hidden" name="action" value="add_group_mapping">
                <div class="form-row">
                    <div class="form-group">
                        <input type="text" name="ldap_group" class="form-control"
                               placeholder="LDAP Group DN (e.g., CN=Admins,OU=Groups,DC=example,DC=com)">
                    </div>
                    <div class="form-group">
                        <select name="silo_group" class="form-control">
                            <option value="">Select Silo Group</option>
                            <?php foreach ($groups as $group): ?>
                            <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-secondary">Add Mapping</button>
                </div>
            </form>

            <?php if (!empty($groupMappings)): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>LDAP Group</th>
                        <th>Silo Group</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groupMappings as $ldapGroup => $siloGroupId): ?>
                    <?php
                    $siloGroupName = 'Unknown';
                    foreach ($groups as $g) {
                        if ($g['id'] == $siloGroupId) {
                            $siloGroupName = $g['name'];
                            break;
                        }
                    }
                    ?>
                    <tr>
                        <td class="ldap-group"><?= htmlspecialchars($ldapGroup) ?></td>
                        <td><?= htmlspecialchars($siloGroupName) ?></td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="remove_group_mapping">
                                <input type="hidden" name="ldap_group" value="<?= htmlspecialchars($ldapGroup) ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="text-muted">No group mappings configured.</p>
            <?php endif; ?>
        </div>

        <!-- Help -->
        <div class="admin-section">
            <h2>Configuration Help</h2>
            <details class="help-item">
                <summary>Active Directory Example</summary>
                <ul>
                    <li><strong>Host:</strong> dc01.example.com</li>
                    <li><strong>Port:</strong> 389 (or 636 for LDAPS)</li>
                    <li><strong>Base DN:</strong> DC=example,DC=com</li>
                    <li><strong>Bind DN:</strong> CN=silo-service,OU=ServiceAccounts,DC=example,DC=com</li>
                    <li><strong>User Filter:</strong> (&(objectClass=user)(objectCategory=person)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))</li>
                    <li><strong>Username Attr:</strong> sAMAccountName</li>
                </ul>
            </details>

            <details class="help-item">
                <summary>OpenLDAP Example</summary>
                <ul>
                    <li><strong>Host:</strong> ldap.example.com</li>
                    <li><strong>Port:</strong> 389</li>
                    <li><strong>Base DN:</strong> ou=People,dc=example,dc=com</li>
                    <li><strong>Bind DN:</strong> cn=admin,dc=example,dc=com</li>
                    <li><strong>User Filter:</strong> (objectClass=posixAccount)</li>
                    <li><strong>Username Attr:</strong> uid</li>
                </ul>
            </details>
        </div>
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
    flex-wrap: wrap;
}

.status-banner.status-enabled {
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
}

.status-banner.status-disabled {
    background: rgba(107, 114, 128, 0.15);
    color: var(--text-muted);
}

.status-actions {
    margin-left: auto;
    display: flex;
    gap: 0.5rem;
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
    padding-top: 1rem;
    border-top: 1px solid var(--border-color);
}

.settings-form {
    max-width: 600px;
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

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.add-mapping-form .form-row {
    grid-template-columns: 1fr 200px auto;
    align-items: end;
    margin-bottom: 1rem;
}

.ldap-group {
    font-family: monospace;
    font-size: 0.85rem;
    word-break: break-all;
}

.help-item {
    margin-bottom: 0.5rem;
}

.help-item summary {
    cursor: pointer;
    padding: 0.5rem;
    background: var(--bg-color);
    border-radius: 4px;
    font-weight: 500;
}

.help-item ul {
    padding: 1rem 1rem 1rem 2rem;
    margin: 0;
}

.help-item li {
    margin-bottom: 0.25rem;
}

@media (max-width: 768px) {
    .add-mapping-form .form-row {
        grid-template-columns: 1fr;
    }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
