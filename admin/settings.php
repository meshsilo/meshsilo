<?php
require_once '../includes/config.php';
$baseDir = '../';

// Require admin permission
requirePermission(PERM_ADMIN, $baseDir . 'index.php');

$pageTitle = 'Admin Settings';
$activePage = '';
$adminPage = 'settings';

$message = '';
$error = '';

// Handle OIDC test connection AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_oidc'])) {
    header('Content-Type: application/json');
    $result = testOIDCConnection();
    echo json_encode($result);
    exit;
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

require_once '../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once '../includes/admin-sidebar.php'; ?>

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
                        <h2>Single Sign-On (OIDC)</h2>
                        <p class="form-help" style="margin-bottom: 1rem;">Configure OpenID Connect to allow users to sign in with an external identity provider (Google, Azure AD, Keycloak, etc.)</p>

                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="oidc_enabled" <?= ($settings['oidc_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-switch"></span>
                                <span>Enable OIDC Authentication</span>
                            </label>
                        </div>

                        <div class="form-group">
                            <label for="oidc_provider_url">Provider URL</label>
                            <input type="url" id="oidc_provider_url" name="oidc_provider_url" class="form-input"
                                value="<?= htmlspecialchars($settings['oidc_provider_url'] ?? '') ?>"
                                placeholder="https://accounts.google.com">
                            <p class="form-help">The base URL of your OIDC provider. The discovery endpoint (/.well-known/openid-configuration) will be appended automatically.</p>
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
                            <label for="oidc_button_text">Button Text</label>
                            <input type="text" id="oidc_button_text" name="oidc_button_text" class="form-input"
                                value="<?= htmlspecialchars($settings['oidc_button_text'] ?? 'Sign in with SSO') ?>">
                            <p class="form-help">The text displayed on the SSO login button.</p>
                        </div>

                        <div class="form-group">
                            <label>Redirect URI</label>
                            <code class="code-block"><?= htmlspecialchars(getOIDCRedirectUri()) ?></code>
                            <p class="form-help">Add this URL to your OIDC provider's allowed redirect URIs.</p>
                        </div>

                        <div class="form-group">
                            <button type="button" id="test-oidc" class="btn btn-secondary">Test Connection</button>
                            <div id="oidc-test-result" style="margin-top: 0.5rem;"></div>
                        </div>
                    </section>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary">Reset to Defaults</button>
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                    </div>
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
            resultDiv.innerHTML = '<div class="alert alert-success" style="margin: 0;">' +
                '<strong>Connection successful!</strong><br>' +
                'Issuer: ' + result.issuer +
                '</div>';
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

<?php require_once '../includes/footer.php'; ?>
