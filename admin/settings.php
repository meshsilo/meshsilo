<?php
require_once '../includes/config.php';
$baseDir = '../';
$pageTitle = 'Admin Settings';
$activePage = '';

// Category data - will be loaded from database later
$categories = [
    ['slug' => 'functional', 'name' => 'Functional'],
    ['slug' => 'decorative', 'name' => 'Decorative'],
    ['slug' => 'tools', 'name' => 'Tools'],
    ['slug' => 'gaming', 'name' => 'Gaming'],
    ['slug' => 'art', 'name' => 'Art'],
    ['slug' => 'mechanical', 'name' => 'Mechanical'],
];

require_once '../includes/header.php';
?>

        <div class="admin-layout">
            <aside class="admin-sidebar">
                <h3>Admin</h3>
                <nav class="admin-nav">
                    <a href="settings.php" class="active">Site Settings</a>
                    <a href="#">Users</a>
                    <a href="#">Categories</a>
                    <a href="#">Collections</a>
                    <a href="#">Storage</a>
                </nav>
            </aside>

            <div class="admin-content">
                <div class="page-header">
                    <h1>Site Settings</h1>
                    <p>Configure your <?= SITE_NAME ?> instance</p>
                </div>

                <form class="settings-form">
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
                        <h2>Registration</h2>

                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="allow_registration" checked>
                                <span class="toggle-switch"></span>
                                <span>Allow new user registration</span>
                            </label>
                        </div>

                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="require_approval">
                                <span class="toggle-switch"></span>
                                <span>Require admin approval for new accounts</span>
                            </label>
                        </div>
                    </section>

                    <section class="settings-section">
                        <h2>Categories</h2>

                        <div class="form-group">
                            <label>Manage Categories</label>
                            <div class="tag-list">
                                <?php foreach ($categories as $category): ?>
                                <span class="tag"><?= htmlspecialchars($category['name']) ?> <button type="button" class="tag-remove">&times;</button></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="input-with-button">
                                <input type="text" id="new-category" class="form-input" placeholder="New category name">
                                <button type="button" class="btn btn-secondary">Add</button>
                            </div>
                        </div>
                    </section>

                    <section class="settings-section">
                        <h2>Database</h2>

                        <div class="form-group">
                            <label>Database Location</label>
                            <p class="form-hint"><?= DB_PATH ?></p>
                        </div>

                        <div class="button-group">
                            <button type="button" class="btn btn-secondary">Export Database</button>
                            <button type="button" class="btn btn-secondary">Backup Now</button>
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
