<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/slicers.php';
$baseDir = '../';

// Require admin permission
requirePermission(PERM_ADMIN, $baseDir . 'index.php');

$pageTitle = 'Admin Settings';
$activePage = '';
$adminPage = 'settings';

$message = '';
$error = '';

// Handle force update check
if (isset($_GET['force_update_check'])) {
    UpdateChecker::clearCache();
    header('Location: ' . route('admin.settings'));
    exit;
}

// Handle OIDC test connection AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_oidc'])) {
    header('Content-Type: application/json');
    $result = testOIDCConnection();
    echo json_encode($result);
    exit;
}

// Handle php.ini save request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_phpini'])) {
    $phpIniPath = __DIR__ . '/../php.ini';
    $content = $_POST['phpini_content'] ?? '';

    // Basic validation - check for valid ini format
    $lines = explode("\n", $content);
    $valid = true;
    foreach ($lines as $lineNum => $line) {
        $line = trim($line);
        // Skip empty lines and comments
        if (empty($line) || $line[0] === ';' || $line[0] === '#') {
            continue;
        }
        // Check for valid directive format (key = value)
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\s*=\s*.+$/', $line)) {
            $error = "Invalid syntax on line " . ($lineNum + 1) . ": " . htmlspecialchars($line);
            $valid = false;
            break;
        }
    }

    if ($valid) {
        if (is_writable($phpIniPath) || (!file_exists($phpIniPath) && is_writable(dirname($phpIniPath)))) {
            if (file_put_contents($phpIniPath, $content) !== false) {
                $message = 'PHP configuration saved successfully. Restart the web server for changes to take effect.';
                logInfo('php.ini updated', ['by' => getCurrentUser()['username']]);
            } else {
                $error = 'Failed to write php.ini file.';
            }
        } else {
            $error = 'php.ini file is not writable.';
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $autoConvert = isset($_POST['auto_convert_stl']) ? '1' : '0';
    $allowRegistration = isset($_POST['allow_registration']) ? '1' : '0';
    $requireApproval = isset($_POST['require_approval']) ? '1' : '0';
    $enableCategories = isset($_POST['enable_categories']) ? '1' : '0';
    $enableCollections = isset($_POST['enable_collections']) ? '1' : '0';

    // Handle file formats - ensure at least one is selected and always include zip
    $formats = isset($_POST['formats']) ? array_map('strtolower', $_POST['formats']) : ['stl', '3mf'];
    if (empty($formats)) {
        $formats = ['stl', '3mf'];
    }
    // Always include zip for multi-part uploads
    if (!in_array('zip', $formats)) {
        $formats[] = 'zip';
    }
    $allowedExtensions = implode(',', $formats);

    $autoDeduplication = isset($_POST['auto_deduplication']) ? '1' : '0';

    // OIDC settings
    $oidcEnabled = isset($_POST['oidc_enabled']) ? '1' : '0';
    $oidcProviderUrl = trim($_POST['oidc_provider_url'] ?? '');
    $oidcClientId = trim($_POST['oidc_client_id'] ?? '');
    $oidcClientSecret = trim($_POST['oidc_client_secret'] ?? '');
    $oidcButtonText = trim($_POST['oidc_button_text'] ?? 'Sign in with SSO');
    $oidcScopes = trim($_POST['oidc_scopes'] ?? '');
    $oidcUsernameClaim = trim($_POST['oidc_username_claim'] ?? 'preferred_username');
    $oidcAutoRegister = isset($_POST['oidc_auto_register']) ? '1' : '0';
    $oidcLinkExisting = isset($_POST['oidc_link_existing']) ? '1' : '0';
    $oidcPkceEnabled = isset($_POST['oidc_pkce_enabled']) ? '1' : '0';
    $oidcSingleLogout = isset($_POST['oidc_single_logout']) ? '1' : '0';
    $oidcDefaultGroup = trim($_POST['oidc_default_group'] ?? 'Users');
    $oidcGroupsClaim = trim($_POST['oidc_groups_claim'] ?? 'groups');
    $oidcGroupMapping = trim($_POST['oidc_group_mapping'] ?? '');
    $oidcManageGroups = isset($_POST['oidc_manage_groups']) ? '1' : '0';
    $oidcRedirectUri = trim($_POST['oidc_redirect_uri'] ?? '');

    // URL settings
    $siteUrl = trim($_POST['site_url'] ?? '');
    $forceSiteUrl = isset($_POST['force_site_url']) ? '1' : '0';

    setSetting('auto_convert_stl', $autoConvert);
    setSetting('allow_registration', $allowRegistration);
    setSetting('require_approval', $requireApproval);
    setSetting('enable_categories', $enableCategories);
    setSetting('enable_collections', $enableCollections);
    setSetting('allowed_extensions', $allowedExtensions);
    setSetting('auto_deduplication', $autoDeduplication);
    setSetting('oidc_enabled', $oidcEnabled);
    setSetting('oidc_provider_url', $oidcProviderUrl);
    setSetting('oidc_client_id', $oidcClientId);
    if (!empty($oidcClientSecret)) {
        setSetting('oidc_client_secret', $oidcClientSecret);
    }
    setSetting('oidc_button_text', $oidcButtonText);
    setSetting('oidc_scopes', $oidcScopes);
    setSetting('oidc_username_claim', $oidcUsernameClaim);
    setSetting('oidc_auto_register', $oidcAutoRegister);
    setSetting('oidc_link_existing', $oidcLinkExisting);
    setSetting('oidc_pkce_enabled', $oidcPkceEnabled);
    setSetting('oidc_single_logout', $oidcSingleLogout);
    setSetting('oidc_default_group', $oidcDefaultGroup);
    setSetting('oidc_groups_claim', $oidcGroupsClaim);
    setSetting('oidc_group_mapping', $oidcGroupMapping);
    setSetting('oidc_manage_groups', $oidcManageGroups);
    setSetting('oidc_redirect_uri', $oidcRedirectUri);
    // Clear OIDC config cache when settings change
    clearOIDCConfigCache();
    setSetting('site_url', $siteUrl);
    setSetting('force_site_url', $forceSiteUrl);

    // Slicer settings
    $enabledSlicers = isset($_POST['enabled_slicers']) ? $_POST['enabled_slicers'] : [];
    setSetting('enabled_slicers', implode(',', $enabledSlicers));

    logInfo('Settings updated', [
        'auto_convert_stl' => $autoConvert,
        'allow_registration' => $allowRegistration,
        'require_approval' => $requireApproval,
        'enable_categories' => $enableCategories,
        'enable_collections' => $enableCollections,
        'allowed_extensions' => $allowedExtensions,
        'auto_deduplication' => $autoDeduplication,
        'oidc_enabled' => $oidcEnabled
    ]);

    $message = 'Settings saved successfully.';
}

// Get current settings
$settings = getAllSettings();

require_once __DIR__ . '/../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>

            <div class="admin-content">
                <div class="page-header">
                    <h1>Site Settings</h1>
                    <p>Configure your <?= SITE_NAME ?> instance</p>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php
                // Check for updates
                $updateInfo = UpdateChecker::check();
                ?>
                <section class="settings-section update-checker">
                    <h2>Version Information</h2>
                    <div class="version-info">
                        <div class="version-current">
                            <span class="label">Current Version:</span>
                            <span class="version"><?= htmlspecialchars(SILO_VERSION) ?></span>
                        </div>
                        <?php if ($updateInfo['available']): ?>
                        <div class="update-available">
                            <div class="update-badge">Update Available</div>
                            <div class="update-details">
                                <p>Version <strong><?= htmlspecialchars($updateInfo['latest']) ?></strong> is available!</p>
                                <?php if ($updateInfo['published']): ?>
                                <p class="update-date">Released: <?= htmlspecialchars(date('M j, Y', strtotime($updateInfo['published']))) ?></p>
                                <?php endif; ?>
                                <a href="<?= htmlspecialchars($updateInfo['url']) ?>" target="_blank" rel="noopener" class="btn btn-primary">
                                    View Release
                                </a>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="version-status up-to-date">
                            <span class="status-icon">&#10003;</span>
                            <span>You're running the latest version</span>
                        </div>
                        <?php endif; ?>
                        <?php if ($updateInfo['error']): ?>
                        <div class="update-error">
                            <small>Unable to check for updates: <?= htmlspecialchars($updateInfo['error']) ?></small>
                        </div>
                        <?php endif; ?>
                        <div class="version-meta">
                            <small>Last checked: <?= htmlspecialchars($updateInfo['checked_at'] ?? 'Never') ?></small>
                            <a href="?force_update_check=1" class="check-now">Check now</a>
                        </div>
                    </div>
                </section>

                <form class="settings-form" method="POST">
                    <section class="settings-section">
                        <h2>General</h2>

                        <div class="form-group">
                            <label for="site-name">Site Name</label>
                            <input type="text" id="site-name" name="site_name" class="form-input" value="<?= htmlspecialchars(SITE_NAME) ?>">
                        </div>

                        <div class="form-group">
                            <label for="site-description">Site Description</label>
                            <input type="text" id="site-description" name="site_description" class="form-input" value="<?= htmlspecialchars(SITE_DESCRIPTION) ?>">
                        </div>

                        <div class="form-group">
                            <label for="models-per-page">Models Per Page</label>
                            <input type="number" id="models-per-page" name="models_per_page" class="form-input" value="20" min="1" max="100">
                        </div>
                    </section>

                    <section class="settings-section">
                        <h2>URL &amp; Reverse Proxy</h2>

                        <div class="form-group">
                            <label for="site_url">Site URL</label>
                            <input type="url" id="site_url" name="site_url" class="form-input"
                                placeholder="https://silo.example.com"
                                value="<?= htmlspecialchars($settings['site_url'] ?? '') ?>">
                            <p class="form-help">The full URL where Silo is accessible. Required if using a reverse proxy.</p>
                        </div>

                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="force_site_url" <?= ($settings['force_site_url'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-switch"></span>
                                <span>Only allow access via configured URL</span>
                            </label>
                            <p class="form-help">When enabled, requests from other URLs will be rejected.</p>
                        </div>
                    </section>

                    <section class="settings-section">
                        <h2>Navigation</h2>

                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="enable_categories" <?= ($settings['enable_categories'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-switch"></span>
                                <span>Enable Categories page</span>
                            </label>
                            <p class="form-help">Show the Categories link in the navigation and allow browsing by category.</p>
                        </div>

                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="enable_collections" <?= ($settings['enable_collections'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-switch"></span>
                                <span>Enable Collections page</span>
                            </label>
                            <p class="form-help">Show the Collections link in the navigation and allow browsing by collection.</p>
                        </div>
                    </section>

                    <section class="settings-section">
                        <h2>Uploads</h2>

                        <div class="form-group">
                            <label for="max-file-size">Max File Size (MB)</label>
                            <input type="number" id="max-file-size" name="max_file_size" class="form-input" value="<?= MAX_FILE_SIZE / (1024 * 1024) ?>" min="1">
                        </div>

                        <?php $currentFormats = getAllowedExtensions(); ?>
                        <div class="form-group">
                            <label for="allowed-formats">Allowed File Formats</label>
                            <div class="checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="formats[]" value="stl" <?= in_array('stl', $currentFormats) ? 'checked' : '' ?>>
                                    <span>.stl</span>
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="formats[]" value="3mf" <?= in_array('3mf', $currentFormats) ? 'checked' : '' ?>>
                                    <span>.3mf</span>
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="formats[]" value="obj" <?= in_array('obj', $currentFormats) ? 'checked' : '' ?>>
                                    <span>.obj</span>
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="formats[]" value="step" <?= in_array('step', $currentFormats) ? 'checked' : '' ?>>
                                    <span>.step</span>
                                </label>
                            </div>
                            <p class="form-help">Select which 3D model formats can be uploaded. ZIP files are always allowed for multi-part uploads.</p>
                        </div>

                    </section>

                    <section class="settings-section">
                        <h2>File Conversion</h2>

                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="auto_convert_stl" <?= ($settings['auto_convert_stl'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-switch"></span>
                                <span>Auto-convert STL files to 3MF on upload</span>
                            </label>
                            <p class="form-help">When enabled, STL files will automatically be converted to 3MF format during upload if conversion saves space.</p>
                        </div>
                    </section>

                    <section class="settings-section">
                        <h2>Registration</h2>

                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="allow_registration" <?= ($settings['allow_registration'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-switch"></span>
                                <span>Allow new user registration</span>
                            </label>
                        </div>

                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="require_approval" <?= ($settings['require_approval'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-switch"></span>
                                <span>Require admin approval for new accounts</span>
                            </label>
                        </div>
                    </section>

                    <section class="settings-section">
                        <h2>Storage &amp; Deduplication</h2>

                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="auto_deduplication" <?= ($settings['auto_deduplication'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-switch"></span>
                                <span>Enable scheduled deduplication</span>
                            </label>
                            <p class="form-help">When enabled, the CLI deduplication script will run when called by cron. Add to crontab:</p>
                            <code class="code-block">0 2 * * * cd <?= realpath(__DIR__ . '/..') ?> && php cli/dedup.php >> logs/dedup.log 2>&1</code>
                        </div>

                        <?php if ($settings['last_deduplication'] ?? ''): ?>
                        <div class="form-group">
                            <label>Last Deduplication Run</label>
                            <p class="form-value"><?= htmlspecialchars($settings['last_deduplication']) ?></p>
                        </div>
                        <?php endif; ?>
                    </section>

                    <section class="settings-section">
                        <h2>Slicer Integration</h2>
                        <p class="form-help" style="margin-bottom: 1rem;">Select which slicer software options appear in the "Open in" menu on model pages. Slicers with URL protocol support can open files directly.</p>

                        <?php
                        $allSlicers = getDefaultSlicers();
                        $enabledSlicersSetting = $settings['enabled_slicers'] ?? null;
                        if ($enabledSlicersSetting) {
                            $enabledList = array_map('trim', explode(',', $enabledSlicersSetting));
                        } else {
                            // Default enabled slicers
                            $enabledList = array_keys(array_filter($allSlicers, fn($s) => $s['enabled']));
                        }
                        ?>
                        <div class="form-group">
                            <label>Enabled Slicers</label>
                            <div class="slicer-grid">
                                <?php foreach ($allSlicers as $key => $slicer): ?>
                                <label class="checkbox-small slicer-option" title="<?= htmlspecialchars($slicer['description']) ?>">
                                    <input type="checkbox" name="enabled_slicers[]" value="<?= htmlspecialchars($key) ?>"
                                        <?= in_array($key, $enabledList) ? 'checked' : '' ?>>
                                    <span><?= htmlspecialchars($slicer['name']) ?></span>
                                    <?php if (!empty($slicer['protocol'])): ?>
                                    <span class="slicer-protocol-badge" title="Supports direct opening via URL protocol">URL</span>
                                    <?php endif; ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="form-help">Slicers marked with "URL" can open files directly from your browser if the software is installed. Others will download the file for manual opening.</p>
                        </div>
                    </section>

                    <section class="settings-section">
                        <h2>Single Sign-On (OIDC)</h2>
                        <p class="form-help" style="margin-bottom: 1rem;">Configure OpenID Connect to allow users to sign in with an external identity provider (Google, Azure AD, Keycloak, Authentik, etc.)</p>

                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="oidc_enabled" <?= ($settings['oidc_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-switch"></span>
                                <span>Enable OIDC Authentication</span>
                            </label>
                        </div>

                        <h3 style="margin-top: 1.5rem; margin-bottom: 1rem; font-size: 1rem; color: var(--text-muted);">Provider Configuration</h3>

                        <div class="form-group">
                            <label for="oidc_provider_url">Provider URL</label>
                            <input type="url" id="oidc_provider_url" name="oidc_provider_url" class="form-input"
                                value="<?= htmlspecialchars($settings['oidc_provider_url'] ?? '') ?>"
                                placeholder="https://accounts.google.com">
                            <p class="form-help">The base URL of your OIDC provider. The discovery endpoint (/.well-known/openid-configuration) will be appended automatically.</p>
                            <details class="provider-presets" style="margin-top: 0.5rem;">
                                <summary style="cursor: pointer; color: var(--primary-color);">Common provider URLs</summary>
                                <ul style="margin: 0.5rem 0 0 1rem; font-size: 0.875rem;">
                                    <li><strong>Google:</strong> <code>https://accounts.google.com</code></li>
                                    <li><strong>Microsoft:</strong> <code>https://login.microsoftonline.com/{tenant}/v2.0</code></li>
                                    <li><strong>Okta:</strong> <code>https://{domain}.okta.com</code></li>
                                    <li><strong>Auth0:</strong> <code>https://{domain}.auth0.com</code></li>
                                    <li><strong>Keycloak:</strong> <code>https://{host}/realms/{realm}</code></li>
                                    <li><strong>Authentik:</strong> <code>https://{host}/application/o/{slug}</code></li>
                                </ul>
                            </details>
                        </div>

                        <div class="form-group">
                            <label for="oidc_client_id">Client ID</label>
                            <input type="text" id="oidc_client_id" name="oidc_client_id" class="form-input"
                                value="<?= htmlspecialchars($settings['oidc_client_id'] ?? '') ?>"
                                placeholder="your-client-id">
                        </div>

                        <div class="form-group">
                            <label for="oidc_client_secret">Client Secret</label>
                            <input type="password" id="oidc_client_secret" name="oidc_client_secret" class="form-input"
                                placeholder="<?= !empty($settings['oidc_client_secret']) ? '••••••••' : 'your-client-secret' ?>">
                            <p class="form-help">Leave blank to keep existing secret.</p>
                        </div>

                        <div class="form-group">
                            <label for="oidc_scopes">Scopes</label>
                            <input type="text" id="oidc_scopes" name="oidc_scopes" class="form-input"
                                value="<?= htmlspecialchars($settings['oidc_scopes'] ?? '') ?>"
                                placeholder="openid email profile">
                            <p class="form-help">Space-separated list of scopes. Default: <code>openid email profile</code>. Add <code>groups</code> for group mapping.</p>
                        </div>

                        <div class="form-group">
                            <label>Redirect URI</label>
                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                <code class="code-block" style="flex: 1; word-break: break-all;"><?= htmlspecialchars(getOIDCRedirectUri()) ?></code>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="navigator.clipboard.writeText('<?= htmlspecialchars(getOIDCRedirectUri()) ?>')">Copy</button>
                            </div>
                            <p class="form-help">Add this URL to your OIDC provider's allowed redirect URIs.</p>
                        </div>

                        <div class="form-group">
                            <label for="oidc_redirect_uri">Custom Redirect URI (Optional)</label>
                            <input type="url" id="oidc_redirect_uri" name="oidc_redirect_uri" class="form-input"
                                value="<?= htmlspecialchars($settings['oidc_redirect_uri'] ?? '') ?>"
                                placeholder="Leave blank to auto-detect">
                            <p class="form-help">Override the auto-detected redirect URI. Useful when behind a reverse proxy.</p>
                        </div>

                        <h3 style="margin-top: 1.5rem; margin-bottom: 1rem; font-size: 1rem; color: var(--text-muted);">User Settings</h3>

                        <div class="form-group">
                            <label for="oidc_username_claim">Username Claim</label>
                            <select id="oidc_username_claim" name="oidc_username_claim" class="form-input">
                                <?php
                                $claims = ['preferred_username', 'name', 'email', 'sub', 'nickname', 'given_name'];
                                $currentClaim = $settings['oidc_username_claim'] ?? 'preferred_username';
                                foreach ($claims as $claim): ?>
                                <option value="<?= $claim ?>" <?= $currentClaim === $claim ? 'selected' : '' ?>><?= $claim ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-help">Which OIDC claim to use for the username. Falls back to email or sub if not available.</p>
                        </div>

                        <div class="form-group">
                            <label for="oidc_default_group">Default Group for New Users</label>
                            <input type="text" id="oidc_default_group" name="oidc_default_group" class="form-input"
                                value="<?= htmlspecialchars($settings['oidc_default_group'] ?? 'Users') ?>"
                                placeholder="Users">
                            <p class="form-help">New OIDC users will be added to this group. Leave blank to not assign a default group.</p>
                        </div>

                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="oidc_auto_register" <?= ($settings['oidc_auto_register'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-switch"></span>
                                <span>Auto-register new users</span>
                            </label>
                            <p class="form-help">Automatically create accounts for new OIDC users. If disabled, accounts must be pre-created.</p>
                        </div>

                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="oidc_link_existing" <?= ($settings['oidc_link_existing'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-switch"></span>
                                <span>Link existing accounts by email</span>
                            </label>
                            <p class="form-help">If an OIDC user's email matches an existing local account, link them together.</p>
                        </div>

                        <h3 style="margin-top: 1.5rem; margin-bottom: 1rem; font-size: 1rem; color: var(--text-muted);">Security</h3>

                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="oidc_pkce_enabled" <?= ($settings['oidc_pkce_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-switch"></span>
                                <span>Enable PKCE (Recommended)</span>
                            </label>
                            <p class="form-help">Proof Key for Code Exchange adds additional security. Most modern providers support this.</p>
                        </div>

                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="oidc_single_logout" <?= ($settings['oidc_single_logout'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-switch"></span>
                                <span>Single Logout (SLO)</span>
                            </label>
                            <p class="form-help">When users log out, also log them out of the identity provider (if supported).</p>
                        </div>

                        <h3 style="margin-top: 1.5rem; margin-bottom: 1rem; font-size: 1rem; color: var(--text-muted);">Group Mapping</h3>

                        <div class="form-group">
                            <label for="oidc_groups_claim">Groups Claim Name</label>
                            <input type="text" id="oidc_groups_claim" name="oidc_groups_claim" class="form-input"
                                value="<?= htmlspecialchars($settings['oidc_groups_claim'] ?? 'groups') ?>"
                                placeholder="groups">
                            <p class="form-help">The claim in the OIDC response that contains user groups/roles. Common values: <code>groups</code>, <code>roles</code>, <code>cognito:groups</code></p>
                        </div>

                        <div class="form-group">
                            <label for="oidc_group_mapping">Group Mapping (JSON)</label>
                            <textarea id="oidc_group_mapping" name="oidc_group_mapping" class="form-input" rows="4"
                                placeholder='{"oidc-admins": "Administrators", "oidc-users": "Users"}'><?= htmlspecialchars($settings['oidc_group_mapping'] ?? '') ?></textarea>
                            <p class="form-help">Map OIDC groups to Silo groups. Format: <code>{"oidc_group": "silo_group"}</code></p>
                        </div>

                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="oidc_manage_groups" <?= ($settings['oidc_manage_groups'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-switch"></span>
                                <span>Fully manage groups from OIDC</span>
                            </label>
                            <p class="form-help">If enabled, users will be removed from Silo groups they're no longer members of in OIDC. If disabled, groups are only added, never removed.</p>
                        </div>

                        <h3 style="margin-top: 1.5rem; margin-bottom: 1rem; font-size: 1rem; color: var(--text-muted);">Appearance</h3>

                        <div class="form-group">
                            <label for="oidc_button_text">Login Button Text</label>
                            <input type="text" id="oidc_button_text" name="oidc_button_text" class="form-input"
                                value="<?= htmlspecialchars($settings['oidc_button_text'] ?? 'Sign in with SSO') ?>">
                            <p class="form-help">The text displayed on the SSO login button.</p>
                        </div>

                        <div class="form-group" style="margin-top: 1.5rem;">
                            <button type="button" id="test-oidc" class="btn btn-secondary">Test Connection</button>
                            <div id="oidc-test-result" style="margin-top: 0.5rem;"></div>
                        </div>
                    </section>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary">Reset to Defaults</button>
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                    </div>
                </form>

                <?php
                $phpIniPath = __DIR__ . '/../php.ini';
                $phpIniContent = file_exists($phpIniPath) ? file_get_contents($phpIniPath) : "; Silo PHP Configuration\nupload_max_filesize = 100M\npost_max_size = 105M\nmax_execution_time = 300\nmemory_limit = 256M\n";
                $phpIniWritable = is_writable($phpIniPath) || (!file_exists($phpIniPath) && is_writable(dirname($phpIniPath)));
                ?>

                <form class="settings-form" method="POST" style="margin-top: 2rem;">
                    <section class="settings-section">
                        <h2>PHP Configuration</h2>
                        <p class="form-help" style="margin-bottom: 1rem;">
                            Edit the php.ini file to configure PHP settings like upload limits and memory.
                            Changes require a web server restart to take effect.
                        </p>

                        <?php if (!$phpIniWritable): ?>
                        <div class="alert alert-error">
                            The php.ini file is not writable. Check file permissions.
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="phpini_content">php.ini Contents</label>
                            <textarea id="phpini_content" name="phpini_content" class="form-input code-textarea" rows="12" <?= !$phpIniWritable ? 'readonly' : '' ?>><?= htmlspecialchars($phpIniContent) ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Current PHP Values</label>
                            <div class="php-values">
                                <div class="php-value"><span>upload_max_filesize:</span> <code><?= ini_get('upload_max_filesize') ?></code></div>
                                <div class="php-value"><span>post_max_size:</span> <code><?= ini_get('post_max_size') ?></code></div>
                                <div class="php-value"><span>max_execution_time:</span> <code><?= ini_get('max_execution_time') ?>s</code></div>
                                <div class="php-value"><span>memory_limit:</span> <code><?= ini_get('memory_limit') ?></code></div>
                            </div>
                            <p class="form-help">These are the currently active PHP values. They may differ from the file if the server hasn't been restarted.</p>
                        </div>

                        <div class="form-actions" style="margin-top: 1rem;">
                            <button type="submit" name="save_phpini" value="1" class="btn btn-primary" <?= !$phpIniWritable ? 'disabled' : '' ?>>
                                Save PHP Configuration
                            </button>
                        </div>
                    </section>
                </form>
            </div>
        </div>

<script>
document.getElementById('test-oidc').addEventListener('click', async function() {
    const resultDiv = document.getElementById('oidc-test-result');
    const btn = this;

    btn.disabled = true;
    btn.textContent = 'Testing...';
    resultDiv.innerHTML = '';

    try {
        const formData = new FormData();
        formData.append('test_oidc', '1');

        const response = await fetch('settings.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            let html = '<div class="alert alert-success" style="margin: 0;">' +
                '<strong>Connection successful!</strong>' +
                '<div style="margin-top: 0.5rem; font-size: 0.875rem;">' +
                '<div><strong>Issuer:</strong> ' + result.issuer + '</div>' +
                '<div><strong>PKCE Support:</strong> ' + (result.pkce_supported ? '✓ Yes' : '✗ No') + '</div>';

            if (result.endpoints) {
                html += '<details style="margin-top: 0.5rem;"><summary style="cursor: pointer;">Endpoints</summary>' +
                    '<ul style="margin: 0.25rem 0 0 1rem; padding: 0;">';
                for (const [key, value] of Object.entries(result.endpoints)) {
                    html += '<li><strong>' + key + ':</strong> <code style="font-size: 0.75rem; word-break: break-all;">' + value + '</code></li>';
                }
                html += '</ul></details>';
            }

            if (result.scopes_supported && result.scopes_supported.length > 0) {
                html += '<details style="margin-top: 0.5rem;"><summary style="cursor: pointer;">Supported Scopes</summary>' +
                    '<code style="font-size: 0.75rem;">' + result.scopes_supported.join(', ') + '</code></details>';
            }

            html += '</div></div>';
            resultDiv.innerHTML = html;
        } else {
            resultDiv.innerHTML = '<div class="alert alert-error" style="margin: 0;">' +
                '<strong>Connection failed:</strong> ' + result.message +
                '</div>';
        }
    } catch (error) {
        resultDiv.innerHTML = '<div class="alert alert-error" style="margin: 0;">' +
            '<strong>Error:</strong> ' + error.message +
            '</div>';
    }

    btn.disabled = false;
    btn.textContent = 'Test Connection';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
