<?php
require_once '../includes/config.php';
$baseDir = '../';

// Require admin permission
requirePermission(PERM_ADMIN, $baseDir . 'index.php');

$pageTitle = 'Storage Settings';
$activePage = '';
$adminPage = 'storage';

$db = getDB();

// Calculate storage stats
$assetsPath = realpath(__DIR__ . '/../assets');
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

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

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

// Handle actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['backup_db'])) {
        $backupPath = __DIR__ . '/../db/backup_' . date('Y-m-d_H-i-s') . '.db';
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
                    $stmt->bindValue(':filename', $file->getFilename(), SQLITE3_TEXT);
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

require_once '../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once '../includes/admin-sidebar.php'; ?>

            <div class="admin-content">
                <div class="page-header">
                    <h1>Storage</h1>
                    <p>Manage storage and database</p>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="settings-form">
                    <section class="settings-section">
                        <h2>Storage Overview</h2>
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
                        </div>
                    </section>

                    <section class="settings-section">
                        <h2>File Types</h2>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Count</th>
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
                    </section>

                    <?php if ($dedupSavings['saved_size'] > 0): ?>
                    <section class="settings-section">
                        <h2>Deduplication Savings</h2>
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
                    </section>
                    <?php endif; ?>

                    <section class="settings-section">
                        <h2>Storage by Category</h2>
                        <?php if (empty($usageByCategory)): ?>
                        <p class="text-muted">No categories found</p>
                        <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Models</th>
                                    <th>Size</th>
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
                    </section>

                    <section class="settings-section">
                        <h2>Storage by User</h2>
                        <?php if (empty($usageByUser)): ?>
                        <p class="text-muted">No users found</p>
                        <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Models</th>
                                    <th>Size</th>
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
                    </section>

                    <section class="settings-section">
                        <h2>Paths</h2>
                        <div class="form-group">
                            <label>Assets Directory</label>
                            <p class="form-hint"><?= htmlspecialchars($assetsPath) ?></p>
                        </div>
                        <div class="form-group">
                            <label>Database File</label>
                            <p class="form-hint"><?= htmlspecialchars($dbPath) ?></p>
                        </div>
                    </section>

                    <section class="settings-section">
                        <h2>Maintenance</h2>
                        <div class="button-group">
                            <form method="post" style="display:inline;">
                                <button type="submit" name="backup_db" class="btn btn-secondary">Backup Database</button>
                            </form>
                            <form method="post" style="display:inline;" onsubmit="return confirm('This will delete files not tracked in the database. Continue?');">
                                <button type="submit" name="clear_orphans" class="btn btn-secondary">Clean Orphaned Files</button>
                            </form>
                        </div>
                    </section>
                </div>
            </div>
        </div>

<?php require_once '../includes/footer.php'; ?>
