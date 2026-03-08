<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/UpdateChecker.php';

// Require settings management permission
if (!isLoggedIn() || !canManageSettings()) {
    $_SESSION['error'] = 'You do not have permission to manage settings.';
    header('Location: ' . route('home'));
    exit;
}

$pageTitle = 'Admin Settings';
$activePage = '';
$adminPage = 'settings';

$message = '';
$error = '';

// CSRF protection for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    $error = 'Invalid request. Please refresh the page and try again.';
}

// Handle force update check
if (isset($_GET['force_update_check'])) {
    UpdateChecker::clearCache();
    header('Location: ' . route('admin.settings'));
    exit;
}

// Handle Email test AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_email'])) {
    header('Content-Type: application/json');

    $testEmail = trim($_POST['test_email_address'] ?? '');

    if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
        exit;
    }

    try {
        $siteName = defined('SITE_NAME') ? SITE_NAME : 'Silo';

        $mail = Mail::create()
            ->to($testEmail)
            ->subject("Test Email from $siteName")
            ->body("
                <h2>Email Configuration Test</h2>
                <p>This is a test email from your $siteName installation.</p>
                <p>If you're receiving this, your email settings are configured correctly!</p>
                <p style='margin-top: 20px; color: #666; font-size: 0.9em;'>
                    <strong>Configuration Details:</strong><br>
                    Driver: " . htmlspecialchars(getSetting('mail_driver', 'mail')) . "<br>
                    Host: " . htmlspecialchars(getSetting('mail_host', 'localhost')) . "<br>
                    Port: " . htmlspecialchars(getSetting('mail_port', '587')) . "<br>
                    From: " . htmlspecialchars(getSetting('mail_from_address', 'noreply@example.com')) . "
                </p>
                <p style='margin-top: 20px;'>Sent at: " . date('Y-m-d H:i:s') . "</p>
            ")
            ->send();

        logInfo('Test email sent', ['to' => $testEmail, 'by' => getCurrentUser()['username']]);
        echo json_encode(['success' => true, 'message' => 'Test email sent successfully to ' . $testEmail]);
    } catch (Exception $e) {
        logError('Test email failed', ['to' => $testEmail, 'error' => $e->getMessage()]);
        echo json_encode(['success' => false, 'message' => 'Failed to send email: ' . $e->getMessage()]);
    }
    exit;
}

// Handle php.ini save request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_phpini'])) {
    $isDocker = getenv('MESHSILO_DOCKER') === 'true';
    $content = $_POST['phpini_content'] ?? '';

    // Determine paths based on environment
    // In Docker, write to storage (within open_basedir) and let the reload script apply it
    if ($isDocker) {
        $phpIniPath = __DIR__ . '/../../storage/cache/php-meshsilo.ini';
    } else {
        $phpIniPath = __DIR__ . '/../php.ini';
    }

    // Basic validation - check for valid ini format
    $lines = explode("\n", $content);
    $valid = true;
    $uploadSize = null;
    foreach ($lines as $lineNum => $line) {
        $line = trim($line);
        // Skip empty lines and comments
        if (empty($line) || $line[0] === ';' || $line[0] === '#') {
            continue;
        }
        // Check for valid directive format (key = value)
        if (!preg_match('/^([a-zA-Z_][a-zA-Z0-9_.]*)\s*=\s*(.+)$/', $line, $matches)) {
            $error = "Invalid syntax on line " . ($lineNum + 1) . ": " . htmlspecialchars($line);
            $valid = false;
            break;
        }
        // Extract upload_max_filesize for nginx sync
        if (trim($matches[1]) === 'upload_max_filesize') {
            $uploadSize = trim($matches[2]);
        }
    }

    if ($valid) {
        if (is_writable($phpIniPath) || (!file_exists($phpIniPath) && is_writable(dirname($phpIniPath)))) {
            if (file_put_contents($phpIniPath, $content) !== false) {
                if ($isDocker) {
                    // Reload script copies from storage to /etc/php and /etc/nginx, then restarts services
                    exec('sudo /usr/local/bin/meshsilo-reload 2>&1', $reloadOutput, $reloadResult);

                    if ($reloadResult === 0) {
                        $message = 'PHP configuration saved and services reloaded successfully.';
                    } else {
                        $message = 'PHP configuration saved. Services could not be reloaded automatically — restart the container for changes to take effect.';
                    }
                } else {
                    $message = 'PHP configuration saved successfully. Restart the web server for changes to take effect.';
                }
                logInfo('php.ini updated', ['by' => getCurrentUser()['username'], 'docker' => $isDocker]);
            } else {
                $error = 'Failed to write PHP configuration file.';
            }
        } else {
            $error = 'PHP configuration file is not writable.';
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $autoConvert = isset($_POST['auto_convert_stl']) ? '1' : '0';
    $allowRegistration = isset($_POST['allow_registration']) ? '1' : '0';
    $requireApproval = isset($_POST['require_approval']) ? '1' : '0';

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

    // Site settings (stored in database, not config file)
    $siteName = trim($_POST['site_name'] ?? 'MeshSilo');
    $siteDescription = trim($_POST['site_description'] ?? '3D Model Storage');
    $siteUrl = trim($_POST['site_url'] ?? '');
    $forceSiteUrl = isset($_POST['force_site_url']) ? '1' : '0';

    setSetting('site_name', $siteName);
    setSetting('site_description', $siteDescription);
    setSetting('auto_convert_stl', $autoConvert);
    setSetting('allow_registration', $allowRegistration);
    setSetting('require_approval', $requireApproval);
    setSetting('allowed_extensions', $allowedExtensions);

    // Max file size (convert MB to bytes)
    $maxFileSize = (int)($_POST['max_file_size'] ?? 100);
    if ($maxFileSize < 1) $maxFileSize = 1;
    if ($maxFileSize > 10240) $maxFileSize = 10240; // Cap at 10GB
    setSetting('max_file_size', (string)($maxFileSize * 1024 * 1024));

    setSetting('site_url', $siteUrl);
    setSetting('force_site_url', $forceSiteUrl);

    // Email/SMTP settings
    $mailDriver = trim($_POST['mail_driver'] ?? 'mail');
    $mailHost = trim($_POST['mail_host'] ?? '');
    $mailPort = (int)($_POST['mail_port'] ?? 587);
    $mailUsername = trim($_POST['mail_username'] ?? '');
    $mailPassword = trim($_POST['mail_password'] ?? '');
    $mailEncryption = trim($_POST['mail_encryption'] ?? 'tls');
    $mailFromAddress = trim($_POST['mail_from_address'] ?? '');
    $mailFromName = trim($_POST['mail_from_name'] ?? '');

    setSetting('mail_driver', $mailDriver);
    setSetting('mail_host', $mailHost);
    setSetting('mail_port', (string)$mailPort);
    setSetting('mail_username', $mailUsername);
    if (!empty($mailPassword)) {
        setSetting('mail_password', $mailPassword);
    }
    setSetting('mail_encryption', $mailEncryption);
    setSetting('mail_from_address', $mailFromAddress);
    setSetting('mail_from_name', $mailFromName);

    logInfo('Settings updated', [
        'auto_convert_stl' => $autoConvert,
        'allow_registration' => $allowRegistration,
        'require_approval' => $requireApproval,
        'allowed_extensions' => $allowedExtensions
    ]);

    $message = 'Settings saved successfully.';

    // Plugin hook: admin_settings_saved - plugins save their own settings from the same form
    if (class_exists('PluginManager')) {
        PluginManager::applyFilter('admin_settings_saved', null, $_POST);
    }
}

// Get current settings
$settings = getAllSettings();

require_once __DIR__ . '/../../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

            <div class="admin-content">
                <div class="page-header">
                    <h1>Site Settings</h1>
                    <p>Configure your <?= htmlspecialchars(SITE_NAME) ?> instance</p>
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
                            <span class="version"><?= htmlspecialchars(MESHSILO_VERSION) ?></span>
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
                    <?= csrf_field() ?>
                    <details class="settings-section">
                        <summary><h2>General</h2></summary>

                        <div class="form-group">
                            <label for="site-name">Site Name</label>
                            <input type="text" id="site-name" name="site_name" class="form-input" value="<?= htmlspecialchars(getSetting('site_name', 'MeshSilo')) ?>">
                            <p class="form-help">Displayed in the header and page titles</p>
                        </div>

                        <div class="form-group">
                            <label for="site-description">Site Description</label>
                            <input type="text" id="site-description" name="site_description" class="form-input" value="<?= htmlspecialchars(getSetting('site_description', '3D Model Storage')) ?>">
                            <p class="form-help">Displayed in the footer and meta tags</p>
                        </div>

                        <div class="form-group">
                            <label for="models-per-page">Models Per Page</label>
                            <input type="number" id="models-per-page" name="models_per_page" class="form-input" value="20" min="1" max="100">
                        </div>
                    </details>

                    <details class="settings-section">
                        <summary><h2>URL &amp; Reverse Proxy</h2></summary>

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
                    </details>

                    <details class="settings-section">
                        <summary><h2>Uploads</h2></summary>

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

                    </details>

                    <details class="settings-section">
                        <summary><h2>File Conversion</h2></summary>

                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="auto_convert_stl" <?= ($settings['auto_convert_stl'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-switch"></span>
                                <span>Auto-convert STL files to 3MF on upload</span>
                            </label>
                            <p class="form-help">When enabled, STL files will automatically be converted to 3MF format during upload if conversion saves space.</p>
                        </div>
                    </details>

                    <details class="settings-section">
                        <summary><h2>Registration</h2></summary>

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
                    </details>

                    <details class="settings-section">
                        <summary><h2>Email / SMTP Settings</h2></summary>
                        <p class="form-help" style="margin-bottom: 1rem;">Configure email settings for password reset links, notifications, and other system emails.</p>

                        <div class="form-group">
                            <label for="mail_driver">Email Driver</label>
                            <select id="mail_driver" name="mail_driver" class="form-input">
                                <option value="mail" <?= ($settings['mail_driver'] ?? 'mail') === 'mail' ? 'selected' : '' ?>>PHP mail() - Default</option>
                                <option value="smtp" <?= ($settings['mail_driver'] ?? '') === 'smtp' ? 'selected' : '' ?>>SMTP Server</option>
                                <option value="log" <?= ($settings['mail_driver'] ?? '') === 'log' ? 'selected' : '' ?>>Log to File (Testing)</option>
                            </select>
                            <p class="form-help">PHP mail() uses your server's sendmail. SMTP connects directly to a mail server. Log mode writes emails to a file for testing.</p>
                        </div>

                        <div id="smtp-settings" style="<?= ($settings['mail_driver'] ?? 'mail') !== 'smtp' ? 'display: none;' : '' ?>">
                            <h3 style="margin-top: 1.5rem; margin-bottom: 1rem; font-size: 1rem; color: var(--text-muted);">SMTP Configuration</h3>

                            <div class="form-row-grid">
                                <div class="form-group">
                                    <label for="mail_host">SMTP Host</label>
                                    <input type="text" id="mail_host" name="mail_host" class="form-input"
                                        value="<?= htmlspecialchars($settings['mail_host'] ?? '') ?>"
                                        placeholder="smtp.gmail.com">
                                </div>
                                <div class="form-group">
                                    <label for="mail_port">SMTP Port</label>
                                    <input type="number" id="mail_port" name="mail_port" class="form-input"
                                        value="<?= htmlspecialchars($settings['mail_port'] ?? '587') ?>"
                                        placeholder="587">
                                    <p class="form-help">Common ports: 587 (TLS), 465 (SSL), 25 (unencrypted)</p>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="mail_encryption">Encryption</label>
                                <select id="mail_encryption" name="mail_encryption" class="form-input">
                                    <option value="tls" <?= ($settings['mail_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (Recommended)</option>
                                    <option value="ssl" <?= ($settings['mail_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                    <option value="none" <?= ($settings['mail_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None (Not Recommended)</option>
                                </select>
                            </div>

                            <div class="form-row-grid">
                                <div class="form-group">
                                    <label for="mail_username">SMTP Username</label>
                                    <input type="text" id="mail_username" name="mail_username" class="form-input"
                                        value="<?= htmlspecialchars($settings['mail_username'] ?? '') ?>"
                                        placeholder="your-email@gmail.com">
                                </div>
                                <div class="form-group">
                                    <label for="mail_password">SMTP Password</label>
                                    <input type="password" id="mail_password" name="mail_password" class="form-input"
                                        placeholder="<?= !empty($settings['mail_password']) ? '••••••••' : 'App password or SMTP password' ?>">
                                    <p class="form-help">Leave blank to keep existing password.</p>
                                </div>
                            </div>

                            <details class="provider-presets" style="margin-top: 0.5rem;">
                                <summary style="cursor: pointer; color: var(--primary-color);">Common SMTP configurations</summary>
                                <ul style="margin: 0.5rem 0 0 1rem; font-size: 0.875rem;">
                                    <li><strong>Gmail:</strong> smtp.gmail.com:587 (TLS) - Requires App Password</li>
                                    <li><strong>Outlook/Office 365:</strong> smtp.office365.com:587 (TLS)</li>
                                    <li><strong>SendGrid:</strong> smtp.sendgrid.net:587 (TLS)</li>
                                    <li><strong>Mailgun:</strong> smtp.mailgun.org:587 (TLS)</li>
                                    <li><strong>Amazon SES:</strong> email-smtp.{region}.amazonaws.com:587 (TLS)</li>
                                </ul>
                            </details>
                        </div>

                        <h3 style="margin-top: 1.5rem; margin-bottom: 1rem; font-size: 1rem; color: var(--text-muted);">Sender Information</h3>

                        <div class="form-row-grid">
                            <div class="form-group">
                                <label for="mail_from_address">From Email Address</label>
                                <input type="email" id="mail_from_address" name="mail_from_address" class="form-input"
                                    value="<?= htmlspecialchars($settings['mail_from_address'] ?? '') ?>"
                                    placeholder="noreply@yourdomain.com">
                                <p class="form-help">The email address shown as the sender.</p>
                            </div>
                            <div class="form-group">
                                <label for="mail_from_name">From Name</label>
                                <input type="text" id="mail_from_name" name="mail_from_name" class="form-input"
                                    value="<?= htmlspecialchars($settings['mail_from_name'] ?? (defined('SITE_NAME') ? SITE_NAME : 'Silo')) ?>"
                                    placeholder="<?= defined('SITE_NAME') ? SITE_NAME : 'Silo' ?>">
                                <p class="form-help">The name shown as the sender.</p>
                            </div>
                        </div>

                        <div class="form-group" style="margin-top: 1.5rem;">
                            <label for="test_email_address">Test Email Configuration</label>
                            <div style="display: flex; gap: 0.5rem; align-items: flex-start;">
                                <input type="email" id="test_email_address" class="form-input" style="flex: 1;"
                                    placeholder="Enter email address to send test">
                                <button type="button" id="test-email" class="btn btn-secondary">Send Test Email</button>
                            </div>
                            <div id="email-test-result" style="margin-top: 0.5rem;"></div>
                            <p class="form-help">Save settings first, then send a test email to verify your configuration.</p>
                        </div>
                    </details>

                    <?php if (class_exists('PluginManager')): ?>
                    <?= PluginManager::applyFilter('admin_settings_sections', '') ?>
                    <?php endif; ?>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary">Reset to Defaults</button>
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                    </div>
                </form>

                <?php
                $isDockerEnv = getenv('MESHSILO_DOCKER') === 'true';
                if ($isDockerEnv) {
                    $phpIniPath = __DIR__ . '/../../storage/cache/php-meshsilo.ini';
                } else {
                    $phpIniPath = __DIR__ . '/../php.ini';
                }
                $phpIniContent = file_exists($phpIniPath) ? file_get_contents($phpIniPath) : "; Silo PHP Configuration\nupload_max_filesize = 100M\npost_max_size = 105M\nmax_execution_time = 300\nmemory_limit = 256M\n";
                $phpIniWritable = is_writable($phpIniPath) || (!file_exists($phpIniPath) && is_writable(dirname($phpIniPath)));
                ?>

                <form class="settings-form" method="POST" style="margin-top: 2rem;">
                    <?= csrf_field() ?>
                    <section class="settings-section">
                        <h2>PHP Configuration</h2>
                        <p class="form-help" style="margin-bottom: 1rem;">
                            <?php if ($isDockerEnv): ?>
                            Edit PHP-FPM configuration for upload limits, memory, and execution time.
                            Changes will automatically reload PHP-FPM and nginx. The <code>upload_max_filesize</code> value is synced with nginx's <code>client_max_body_size</code>.
                            <?php else: ?>
                            Edit the php.ini file to configure PHP settings like upload limits and memory.
                            Changes require a web server restart to take effect.
                            <?php endif; ?>
                        </p>

                        <?php if (!$phpIniWritable): ?>
                        <div class="alert alert-error">
                            <?php if ($isDockerEnv): ?>
                            The PHP-FPM configuration file is not writable. The web server process may not have sufficient permissions.
                            <?php else: ?>
                            The php.ini file is not writable. Check file permissions.
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="phpini_content"><?= $isDockerEnv ? 'PHP-FPM Configuration' : 'php.ini Contents' ?></label>
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
                            <p class="form-help">These are the currently active PHP values.<?= $isDockerEnv ? '' : ' They may differ from the file if the server hasn\'t been restarted.' ?></p>
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
// Email driver toggle - show/hide SMTP settings
const mailDriverSelect = document.getElementById('mail_driver');
if (mailDriverSelect) {
    mailDriverSelect.addEventListener('change', function() {
        const smtpSettings = document.getElementById('smtp-settings');
        if (smtpSettings) {
            if (this.value === 'smtp') {
                smtpSettings.style.display = '';
            } else {
                smtpSettings.style.display = 'none';
            }
        }
    });
}

// Test email button
const testEmailBtn = document.getElementById('test-email');
if (testEmailBtn) {
    testEmailBtn.addEventListener('click', async function() {
        const resultDiv = document.getElementById('email-test-result');
        const emailInput = document.getElementById('test_email_address');
        const btn = this;
        const email = emailInput.value.trim();

        if (!email) {
            resultDiv.innerHTML = '<div class="alert alert-error" style="margin: 0;">Please enter an email address.</div>';
            emailInput.focus();
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Sending...';
        resultDiv.innerHTML = '';

        try {
            const formData = new FormData();
            formData.append('test_email', '1');
            formData.append('test_email_address', email);

            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                resultDiv.innerHTML = '<div class="alert alert-success" style="margin: 0;">' +
                    '<strong>Success!</strong> ' + result.message +
                    '</div>';
            } else {
                resultDiv.innerHTML = '<div class="alert alert-error" style="margin: 0;">' +
                    '<strong>Failed:</strong> ' + result.message +
                    '</div>';
            }
        } catch (error) {
            resultDiv.innerHTML = '<div class="alert alert-error" style="margin: 0;">' +
                '<strong>Error:</strong> ' + error.message +
                '</div>';
        }

        btn.disabled = false;
        btn.textContent = 'Send Test Email';
    });
}

</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
