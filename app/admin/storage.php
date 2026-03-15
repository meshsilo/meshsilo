<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/storage.php';

// Require storage management permission
if (!isLoggedIn() || !canManageStorage()) {
    $_SESSION['error'] = 'You do not have permission to manage storage settings.';
    header('Location: ' . route('home'));
    exit;
}

$pageTitle = 'Storage Settings';
$activePage = '';
$adminPage = 'storage';

$db = getDB();

// Current storage type
$storageType = getSetting('storage_type', 'local');

// Calculate storage stats
$assetsPath = realpath(__DIR__ . '/../../storage/assets');
$dbPath = realpath(DB_PATH);

function getDirectorySize($path) {
    $size = 0;
    if (is_dir($path)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
    }
    return $size;
}

// formatBytes is defined in includes/helpers.php

function countFiles($path, $extensions = []) {
    $count = 0;
    if (is_dir($path)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                if (empty($extensions) || in_array(strtolower($file->getExtension()), $extensions)) {
                    $count++;
                }
            }
        }
    }
    return $count;
}

$assetsSize = getDirectorySize($assetsPath);
$dbSize = file_exists($dbPath) ? filesize($dbPath) : 0;
$totalFiles = countFiles($assetsPath);
$stlFiles = countFiles($assetsPath, ['stl']);
$threemfFiles = countFiles($assetsPath, ['3mf']);

// Get model count from database
$modelCount = $db->querySingle('SELECT COUNT(*) FROM models');

// Get storage usage breakdown
$usageByCategory = getStorageUsageByCategory();
$usageByUser = getStorageUsageByUser();
$totalUsage = getTotalStorageUsage();
$dedupSavings = getDedupStorageSavings();

// Calculate 3MF conversion savings
$conversionStats = $db->query('
    SELECT COUNT(*) as converted_count,
           COALESCE(SUM(original_size), 0) as original_total,
           COALESCE(SUM(file_size), 0) as current_total
    FROM models
    WHERE original_size IS NOT NULL AND original_size > 0 AND file_type = \'3mf\'
')->fetchArray(PDO::FETCH_ASSOC);
$conversionSaved = ($conversionStats['original_total'] ?? 0) - ($conversionStats['current_total'] ?? 0);
$conversionPercent = ($conversionStats['original_total'] ?? 0) > 0
    ? round($conversionSaved / $conversionStats['original_total'] * 100)
    : 0;

// Handle actions
$message = '';
$error = '';

// CSRF protection for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    $error = 'Invalid request. Please refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_s3'])) {
        setSetting('storage_type', $_POST['storage_type'] ?? 'local');
        setSetting('s3_endpoint', $_POST['s3_endpoint'] ?? '');
        setSetting('s3_bucket', $_POST['s3_bucket'] ?? '');
        setSetting('s3_access_key', $_POST['s3_access_key'] ?? '');
        if (!empty($_POST['s3_secret_key'])) {
            setSetting('s3_secret_key', $_POST['s3_secret_key']);
        }
        setSetting('s3_region', $_POST['s3_region'] ?? 'us-east-1');
        setSetting('s3_path_style', isset($_POST['s3_path_style']) ? '1' : '0');
        setSetting('s3_public_url', $_POST['s3_public_url'] ?? '');
        $message = 'Storage settings saved successfully.';
        $storageType = $_POST['storage_type'] ?? 'local';
    } elseif (isset($_POST['test_s3'])) {
        $config = [
            'endpoint' => $_POST['s3_endpoint'] ?? '',
            'bucket' => $_POST['s3_bucket'] ?? '',
            'access_key' => $_POST['s3_access_key'] ?? '',
            'secret_key' => !empty($_POST['s3_secret_key']) ? $_POST['s3_secret_key'] : getSetting('s3_secret_key', ''),
            'region' => $_POST['s3_region'] ?? 'us-east-1',
            'use_path_style' => isset($_POST['s3_path_style']),
            'public_url' => $_POST['s3_public_url'] ?? ''
        ];
        $result = testS3Connection($config);
        if ($result['success']) {
            $message = 'S3 connection test successful!';
        } else {
            $error = 'S3 connection test failed: ' . ($result['error'] ?? 'Unknown error');
        }
    } elseif (isset($_POST['migrate_to_s3'])) {
        $result = migrateToS3();
        if ($result['failed'] === 0) {
            $message = "Migration complete! {$result['migrated']} files migrated to S3.";
        } else {
            $error = "Migration completed with errors. {$result['migrated']} migrated, {$result['failed']} failed.";
        }
    } elseif (isset($_POST['backup_db'])) {
        $backupPath = __DIR__ . '/../../storage/db/backup_' . date('Y-m-d_H-i-s') . '.db';
        if (copy($dbPath, $backupPath)) {
            $message = 'Database backed up to: ' . basename($backupPath);
        } else {
            $error = 'Failed to create backup.';
        }
    } elseif (isset($_POST['clear_orphans'])) {
        // Find files in assets that aren't in the database
        $orphanCount = 0;
        if (is_dir($assetsPath)) {
            foreach (new DirectoryIterator($assetsPath) as $file) {
                if ($file->isDot() || $file->getFilename() === '.gitkeep') continue;
                if ($file->isFile()) {
                    $stmt = $db->prepare('SELECT COUNT(*) FROM models WHERE filename = :filename');
                    $stmt->bindValue(':filename', $file->getFilename(), PDO::PARAM_STR);
                    $inDb = $stmt->execute()->fetchArray()[0];
                    if (!$inDb) {
                        unlink($file->getPathname());
                        $orphanCount++;
                    }
                }
            }
        }
        $message = "Cleaned up $orphanCount orphaned file(s).";
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

            <div class="admin-content">
                <div class="page-header">
                    <h1>Storage</h1>
                    <p>Manage storage and database</p>
                </div>

                <?php if ($message): ?>
                <div role="status" class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div role="alert" class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="settings-form">
                    <details class="settings-section" open>
                        <summary><h2>Storage Overview</h2></summary>
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-value"><?= formatBytes($assetsSize) ?></div>
                                <div class="stat-label">Total Storage Used</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?= $modelCount ?></div>
                                <div class="stat-label">Models in Database</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?= $totalFiles ?></div>
                                <div class="stat-label">Files in Assets</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?= formatBytes($dbSize) ?></div>
                                <div class="stat-label">Database Size</div>
                            </div>
                            <?php if ($dedupSavings['saved_size'] > 0): ?>
                            <div class="stat-card stat-card-success">
                                <div class="stat-value"><?= formatBytes($dedupSavings['saved_size']) ?></div>
                                <div class="stat-label">Saved by Dedup (<?= $dedupSavings['saved_percent'] ?>%)</div>
                            </div>
                            <?php endif; ?>
                            <?php if ($conversionSaved > 0): ?>
                            <div class="stat-card stat-card-success">
                                <div class="stat-value"><?= formatBytes($conversionSaved) ?></div>
                                <div class="stat-label">Saved by STL→3MF (<?= $conversionPercent ?>%)</div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </details>

                    <details class="settings-section">
                        <summary><h2>File Types</h2></summary>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th scope="col">Type</th>
                                    <th scope="col">Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>.stl</td>
                                    <td><?= $stlFiles ?></td>
                                </tr>
                                <tr>
                                    <td>.3mf</td>
                                    <td><?= $threemfFiles ?></td>
                                </tr>
                                <tr>
                                    <td>Other</td>
                                    <td><?= $totalFiles - $stlFiles - $threemfFiles ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </details>

                    <?php if ($dedupSavings['saved_size'] > 0): ?>
                    <details class="settings-section" open>
                        <summary><h2>Deduplication Savings</h2></summary>
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-value"><?= formatBytes($dedupSavings['total_size']) ?></div>
                                <div class="stat-label">Original Size (if no dedup)</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?= formatBytes($dedupSavings['actual_size']) ?></div>
                                <div class="stat-label">Actual Storage Used</div>
                            </div>
                            <div class="stat-card stat-card-success">
                                <div class="stat-value"><?= formatBytes($dedupSavings['saved_size']) ?></div>
                                <div class="stat-label">Space Saved</div>
                            </div>
                            <div class="stat-card stat-card-success">
                                <div class="stat-value"><?= $dedupSavings['saved_percent'] ?>%</div>
                                <div class="stat-label">Savings Rate</div>
                            </div>
                        </div>
                    </details>
                    <?php endif; ?>

                    <?php if ($conversionSaved > 0): ?>
                    <details class="settings-section" open>
                        <summary><h2>STL→3MF Conversion Savings</h2></summary>
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-value"><?= $conversionStats['converted_count'] ?></div>
                                <div class="stat-label">Files Converted</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?= formatBytes($conversionStats['original_total']) ?></div>
                                <div class="stat-label">Original Size (STL)</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?= formatBytes($conversionStats['current_total']) ?></div>
                                <div class="stat-label">Current Size (3MF)</div>
                            </div>
                            <div class="stat-card stat-card-success">
                                <div class="stat-value"><?= formatBytes($conversionSaved) ?></div>
                                <div class="stat-label">Total Space Saved</div>
                            </div>
                            <div class="stat-card stat-card-success">
                                <div class="stat-value"><?= $conversionPercent ?>%</div>
                                <div class="stat-label">Compression Rate</div>
                            </div>
                        </div>
                    </details>
                    <?php endif; ?>

                    <details class="settings-section">
                        <summary><h2>Storage by Category</h2></summary>
                        <?php if (empty($usageByCategory)): ?>
                        <p class="text-muted">No categories found</p>
                        <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th scope="col">Category</th>
                                    <th scope="col">Models</th>
                                    <th scope="col">Size</th>
                                    <th style="width: 40%;">Usage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $maxSize = max(array_column($usageByCategory, 'total_size') ?: [1]);
                                foreach ($usageByCategory as $cat):
                                    $percent = $maxSize > 0 ? ($cat['total_size'] / $maxSize * 100) : 0;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($cat['name']) ?></td>
                                    <td><?= $cat['model_count'] ?></td>
                                    <td><?= formatBytes($cat['total_size'] ?? 0) ?></td>
                                    <td>
                                        <div class="progress-bar">
                                            <div class="progress-bar-fill" style="width: <?= $percent ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </details>

                    <details class="settings-section">
                        <summary><h2>Storage by User</h2></summary>
                        <?php if (empty($usageByUser)): ?>
                        <p class="text-muted">No users found</p>
                        <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th scope="col">User</th>
                                    <th scope="col">Models</th>
                                    <th scope="col">Size</th>
                                    <th style="width: 40%;">Usage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $maxUserSize = max(array_column($usageByUser, 'total_size') ?: [1]);
                                foreach ($usageByUser as $usr):
                                    $percent = $maxUserSize > 0 ? ($usr['total_size'] / $maxUserSize * 100) : 0;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($usr['username'] ?? 'Unknown') ?></td>
                                    <td><?= $usr['model_count'] ?></td>
                                    <td><?= formatBytes($usr['total_size'] ?? 0) ?></td>
                                    <td>
                                        <div class="progress-bar">
                                            <div class="progress-bar-fill" style="width: <?= $percent ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </details>

                    <details class="settings-section">
                        <summary><h2>Paths</h2></summary>
                        <div class="form-group">
                            <label>Assets Directory</label>
                            <p class="form-hint"><?= htmlspecialchars($assetsPath) ?></p>
                        </div>
                        <div class="form-group">
                            <label>Database File</label>
                            <p class="form-hint"><?= htmlspecialchars($dbPath) ?></p>
                        </div>
                    </details>

                    <details class="settings-section" open>
                        <summary><h2>Storage Backend</h2></summary>
                        <form method="post">
                            <?= csrf_field() ?>
                            <div class="form-group">
                                <label for="storage_type">Storage Type</label>
                                <select name="storage_type" id="storage_type" class="form-input" onchange="toggleS3Settings()">
                                    <option value="local" <?= $storageType === 'local' ? 'selected' : '' ?>>Local Filesystem</option>
                                    <option value="s3" <?= $storageType === 's3' ? 'selected' : '' ?>>S3-Compatible Object Storage</option>
                                </select>
                                <p class="form-hint">Choose where model files are stored</p>
                            </div>

                            <div id="s3-settings" style="<?= $storageType !== 's3' ? 'display:none;' : '' ?>">
                                <div class="form-group">
                                    <label for="s3_endpoint">S3 Endpoint URL</label>
                                    <input type="url" name="s3_endpoint" id="s3_endpoint" class="form-input"
                                           value="<?= htmlspecialchars(getSetting('s3_endpoint', '')) ?>"
                                           placeholder="https://s3.amazonaws.com or https://your-minio-server.com">
                                    <p class="form-hint">For AWS S3 use https://s3.amazonaws.com, for Minio/Backblaze use your server URL</p>
                                </div>

                                <div class="form-group">
                                    <label for="s3_bucket">Bucket Name</label>
                                    <input type="text" name="s3_bucket" id="s3_bucket" class="form-input"
                                           value="<?= htmlspecialchars(getSetting('s3_bucket', '')) ?>"
                                           placeholder="my-silo-bucket">
                                </div>

                                <div class="form-group">
                                    <label for="s3_region">Region</label>
                                    <input type="text" name="s3_region" id="s3_region" class="form-input"
                                           value="<?= htmlspecialchars(getSetting('s3_region', 'us-east-1')) ?>"
                                           placeholder="us-east-1">
                                </div>

                                <div class="form-group">
                                    <label for="s3_access_key">Access Key</label>
                                    <input type="text" name="s3_access_key" id="s3_access_key" class="form-input"
                                           value="<?= htmlspecialchars(getSetting('s3_access_key', '')) ?>"
                                           placeholder="AKIAIOSFODNN7EXAMPLE">
                                </div>

                                <div class="form-group">
                                    <label for="s3_secret_key">Secret Key</label>
                                    <input type="password" name="s3_secret_key" id="s3_secret_key" class="form-input"
                                           placeholder="<?= getSetting('s3_secret_key', '') ? '(saved - leave blank to keep)' : 'Enter secret key' ?>" autocomplete="off">
                                </div>

                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" name="s3_path_style" <?= getSetting('s3_path_style', '0') === '1' ? 'checked' : '' ?>>
                                        Use Path-Style URLs
                                    </label>
                                    <p class="form-hint">Enable for Minio and some S3-compatible services. Uses endpoint/bucket/key instead of bucket.endpoint/key</p>
                                </div>

                                <div class="form-group">
                                    <label for="s3_public_url">Public URL (optional)</label>
                                    <input type="url" name="s3_public_url" id="s3_public_url" class="form-input"
                                           value="<?= htmlspecialchars(getSetting('s3_public_url', '')) ?>"
                                           placeholder="https://cdn.example.com/silo">
                                    <p class="form-hint">If your bucket is publicly accessible via CDN, enter the base URL here</p>
                                </div>
                            </div>

                            <div class="button-group">
                                <button type="submit" name="save_s3" class="btn btn-primary">Save Settings</button>
                                <button type="submit" name="test_s3" class="btn btn-secondary" id="test-s3-btn"
                                        style="<?= $storageType !== 's3' ? 'display:none;' : '' ?>">Test Connection</button>
                            </div>
                        </form>

                        <?php if ($storageType === 'local' && getSetting('s3_endpoint', '')): ?>
                        <div style="margin-top: 1rem; padding: 1rem; background: var(--bg-tertiary); border-radius: 8px;">
                            <h4>Migrate to S3</h4>
                            <p class="form-hint">You have S3 configured but are using local storage. You can migrate existing files to S3.</p>
                            <form method="post" data-confirm="This will copy all local files to S3. This may take a while. Continue?">
                                <?= csrf_field() ?>
                                <button type="submit" name="migrate_to_s3" class="btn btn-secondary">Migrate Files to S3</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </details>

                    <script>
                    function toggleS3Settings() {
                        const type = document.getElementById('storage_type').value;
                        const s3Settings = document.getElementById('s3-settings');
                        const testBtn = document.getElementById('test-s3-btn');
                        if (type === 's3') {
                            s3Settings.style.display = 'block';
                            testBtn.style.display = 'inline-block';
                        } else {
                            s3Settings.style.display = 'none';
                            testBtn.style.display = 'none';
                        }
                    }
                    </script>

                    <details class="settings-section">
                        <summary><h2>Maintenance</h2></summary>
                        <div class="button-group">
                            <form method="post" style="display:inline;">
                                <?= csrf_field() ?>
                                <button type="submit" name="backup_db" class="btn btn-secondary">Backup Database</button>
                            </form>
                            <form method="post" style="display:inline;" data-confirm="This will delete files not tracked in the database. Continue?">
                                <?= csrf_field() ?>
                                <button type="submit" name="clear_orphans" class="btn btn-secondary">Clean Orphaned Files</button>
                            </form>
                        </div>
                    </details>
                </div>
            </div>
        </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
