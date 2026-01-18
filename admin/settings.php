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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $autoConvert = isset($_POST['auto_convert_stl']) ? '1' : '0';
    $allowRegistration = isset($_POST['allow_registration']) ? '1' : '0';
    $requireApproval = isset($_POST['require_approval']) ? '1' : '0';

    setSetting('auto_convert_stl', $autoConvert);
    setSetting('allow_registration', $allowRegistration);
    setSetting('require_approval', $requireApproval);

    logInfo('Settings updated', [
        'auto_convert_stl' => $autoConvert,
        'allow_registration' => $allowRegistration,
        'require_approval' => $requireApproval
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
                        <h2>Uploads</h2>

                        <div class="form-group">
                            <label for="max-file-size">Max File Size (MB)</label>
                            <input type="number" id="max-file-size" name="max_file_size" class="form-input" value="<?= MAX_FILE_SIZE / (1024 * 1024) ?>" min="1">
                        </div>

                        <div class="form-group">
                            <label for="allowed-formats">Allowed File Formats</label>
                            <div class="checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="formats[]" value="stl" <?= in_array('stl', ALLOWED_EXTENSIONS) ? 'checked' : '' ?>>
                                    <span>.stl</span>
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="formats[]" value="3mf" <?= in_array('3mf', ALLOWED_EXTENSIONS) ? 'checked' : '' ?>>
                                    <span>.3mf</span>
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="formats[]" value="obj">
                                    <span>.obj</span>
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="formats[]" value="step">
                                    <span>.step</span>
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="require_login" checked>
                                <span class="toggle-switch"></span>
                                <span>Require login to upload</span>
                            </label>
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

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary">Reset to Defaults</button>
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                    </div>
                </form>
            </div>
        </div>

<?php require_once '../includes/footer.php'; ?>
