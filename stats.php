<?php
require_once 'includes/config.php';

// Require view stats permission
requirePermission(PERM_VIEW_STATS);

$pageTitle = 'Statistics';
$activePage = 'stats';

$db = getDB();

// Get model count
$result = $db->query('SELECT COUNT(*) as count FROM models');
$modelCount = $result->fetchArray(SQLITE3_ASSOC)['count'];

// Get total storage from database
$result = $db->query('SELECT SUM(file_size) as total, AVG(file_size) as avg FROM models');
$row = $result->fetchArray(SQLITE3_ASSOC);
$totalStorageDB = $row['total'] ?? 0;
$avgModelSize = $row['avg'] ?? 0;

// Get actual storage from filesystem
$assetsPath = realpath(UPLOAD_PATH);
$actualStorage = 0;
$fileCount = 0;
if ($assetsPath && is_dir($assetsPath)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($assetsPath, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename() !== '.gitkeep') {
            $actualStorage += $file->getSize();
            $fileCount++;
        }
    }
}

// Get disk space info
$diskFree = disk_free_space($assetsPath ?: '.');
$diskTotal = disk_total_space($assetsPath ?: '.');
$diskUsedPercent = $diskTotal > 0 ? (($diskTotal - $diskFree) / $diskTotal) * 100 : 0;

// Get database file size
$dbSize = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;

// Get user stats
$result = $db->query('SELECT COUNT(*) as total, SUM(is_admin) as admins FROM users');
$userStats = $result->fetchArray(SQLITE3_ASSOC);
$totalUsers = $userStats['total'] ?? 0;
$adminUsers = $userStats['admins'] ?? 0;

// Get models by file type
$result = $db->query('SELECT file_type, COUNT(*) as count, SUM(file_size) as size FROM models GROUP BY file_type ORDER BY count DESC');
$fileTypes = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $fileTypes[] = $row;
}

// Get models by category
$result = $db->query('
    SELECT c.name, COUNT(mc.model_id) as count
    FROM categories c
    LEFT JOIN model_categories mc ON c.id = mc.category_id
    GROUP BY c.id
    ORDER BY count DESC
');
$categoryStats = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $categoryStats[] = $row;
}

// Get uncategorized models count
$result = $db->query('
    SELECT COUNT(*) as count FROM models m
    WHERE NOT EXISTS (SELECT 1 FROM model_categories mc WHERE mc.model_id = m.id)
');
$uncategorizedCount = $result->fetchArray(SQLITE3_ASSOC)['count'];

// Get models without source URL
$result = $db->query('SELECT COUNT(*) as count FROM models WHERE source_url IS NULL OR source_url = ""');
$noSourceCount = $result->fetchArray(SQLITE3_ASSOC)['count'];

// Get models by collection
$result = $db->query('
    SELECT collection, COUNT(*) as count, SUM(file_size) as size
    FROM models
    WHERE collection IS NOT NULL AND collection != ""
    GROUP BY collection
    ORDER BY count DESC
    LIMIT 10
');
$collectionStats = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $collectionStats[] = $row;
}

// Get top authors
$result = $db->query('
    SELECT author, COUNT(*) as count, SUM(file_size) as size
    FROM models
    WHERE author IS NOT NULL AND author != ""
    GROUP BY author
    ORDER BY count DESC
    LIMIT 10
');
$authorStats = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $authorStats[] = $row;
}

// Get recent uploads (last 7 days)
$result = $db->query('
    SELECT DATE(created_at) as date, COUNT(*) as count
    FROM models
    WHERE created_at >= datetime("now", "-7 days")
    GROUP BY DATE(created_at)
    ORDER BY date DESC
');
$recentUploads = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $recentUploads[] = $row;
}

// Get monthly upload trends (last 12 months)
$result = $db->query('
    SELECT strftime("%Y-%m", created_at) as month, COUNT(*) as count, SUM(file_size) as size
    FROM models
    WHERE created_at >= datetime("now", "-12 months")
    GROUP BY strftime("%Y-%m", created_at)
    ORDER BY month DESC
');
$monthlyUploads = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $monthlyUploads[] = $row;
}

// Get oldest and newest models
$result = $db->query('SELECT name, created_at FROM models ORDER BY created_at ASC LIMIT 1');
$oldestModel = $result->fetchArray(SQLITE3_ASSOC);

$result = $db->query('SELECT name, created_at FROM models ORDER BY created_at DESC LIMIT 1');
$newestModel = $result->fetchArray(SQLITE3_ASSOC);

// Get largest models
$result = $db->query('SELECT name, file_size, file_type FROM models ORDER BY file_size DESC LIMIT 5');
$largestModels = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $largestModels[] = $row;
}

// Check for missing files (files in DB but not on disk)
$result = $db->query('SELECT id, name, filename FROM models');
$missingFiles = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $filePath = UPLOAD_PATH . $row['filename'];
    if (!file_exists($filePath)) {
        $missingFiles[] = $row;
    }
}

// Check for orphaned files (files on disk but not in DB)
$orphanedFiles = [];
if ($assetsPath && is_dir($assetsPath)) {
    $dbFilenames = [];
    $result = $db->query('SELECT filename FROM models');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $dbFilenames[] = $row['filename'];
    }

    $iterator = new DirectoryIterator($assetsPath);
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename() !== '.gitkeep') {
            if (!in_array($file->getFilename(), $dbFilenames)) {
                $orphanedFiles[] = [
                    'filename' => $file->getFilename(),
                    'size' => $file->getSize()
                ];
            }
        }
    }
}

// Helper function to format bytes
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

require_once 'includes/header.php';
?>

        <div class="page-container">
            <div class="page-header">
                <h1>Statistics</h1>
                <p>Overview of your 3D model library</p>
            </div>

            <div class="stats-grid">
                <div class="stat-card stat-card-large">
                    <div class="stat-icon">&#9653;</div>
                    <div class="stat-value"><?= number_format($modelCount) ?></div>
                    <div class="stat-label">Total Models</div>
                </div>

                <div class="stat-card stat-card-large">
                    <div class="stat-icon">&#128190;</div>
                    <div class="stat-value"><?= formatBytes($totalStorageDB) ?></div>
                    <div class="stat-label">Storage Used</div>
                </div>

                <div class="stat-card">
                    <div class="stat-value"><?= formatBytes($avgModelSize) ?></div>
                    <div class="stat-label">Avg Model Size</div>
                </div>

                <div class="stat-card">
                    <div class="stat-value"><?= $totalUsers ?></div>
                    <div class="stat-label">Users (<?= $adminUsers ?> admin)</div>
                </div>
            </div>

            <!-- System Health -->
            <?php
            $hasIssues = !empty($missingFiles) || !empty($orphanedFiles) || $uncategorizedCount > 0;
            $issueClass = $hasIssues ? 'stats-section-warning' : 'stats-section-success';
            ?>
            <section class="stats-section stats-section-full <?= $issueClass ?>">
                <h2>System Health</h2>
                <div class="health-grid">
                    <div class="health-item">
                        <span class="health-label">Disk Space</span>
                        <div class="health-bar">
                            <div class="health-bar-fill <?= $diskUsedPercent > 90 ? 'health-bar-danger' : ($diskUsedPercent > 70 ? 'health-bar-warning' : '') ?>" style="width: <?= min($diskUsedPercent, 100) ?>%"></div>
                        </div>
                        <span class="health-value"><?= formatBytes($diskFree) ?> free of <?= formatBytes($diskTotal) ?> (<?= round($diskUsedPercent, 1) ?>% used)</span>
                    </div>
                    <div class="health-item">
                        <span class="health-label">Database Size</span>
                        <span class="health-value"><?= formatBytes($dbSize) ?></span>
                    </div>
                    <div class="health-item">
                        <span class="health-label">Files on Disk</span>
                        <span class="health-value"><?= $fileCount ?> files (<?= formatBytes($actualStorage) ?>)</span>
                    </div>
                    <div class="health-item <?= $uncategorizedCount > 0 ? 'health-warning' : '' ?>">
                        <span class="health-label">Uncategorized Models</span>
                        <span class="health-value"><?= $uncategorizedCount ?></span>
                    </div>
                    <div class="health-item">
                        <span class="health-label">Models Without Source</span>
                        <span class="health-value"><?= $noSourceCount ?></span>
                    </div>
                    <div class="health-item <?= !empty($missingFiles) ? 'health-danger' : '' ?>">
                        <span class="health-label">Missing Files</span>
                        <span class="health-value"><?= count($missingFiles) ?></span>
                    </div>
                    <div class="health-item <?= !empty($orphanedFiles) ? 'health-warning' : '' ?>">
                        <span class="health-label">Orphaned Files</span>
                        <span class="health-value"><?= count($orphanedFiles) ?></span>
                    </div>
                </div>
            </section>

            <!-- Timeline Info -->
            <?php if ($oldestModel || $newestModel): ?>
            <section class="stats-section stats-section-full">
                <h2>Library Timeline</h2>
                <div class="timeline-grid">
                    <?php if ($oldestModel): ?>
                    <div class="timeline-item">
                        <span class="timeline-label">First Upload</span>
                        <span class="timeline-value"><?= htmlspecialchars($oldestModel['name']) ?></span>
                        <span class="timeline-date"><?= date('M j, Y', strtotime($oldestModel['created_at'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($newestModel): ?>
                    <div class="timeline-item">
                        <span class="timeline-label">Latest Upload</span>
                        <span class="timeline-value"><?= htmlspecialchars($newestModel['name']) ?></span>
                        <span class="timeline-date"><?= date('M j, Y', strtotime($newestModel['created_at'])) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <div class="stats-sections">
                <section class="stats-section">
                    <h2>Models by File Type</h2>
                    <div class="stats-table-container">
                        <table class="stats-table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Count</th>
                                    <th>Size</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($fileTypes)): ?>
                                <tr><td colspan="3" class="text-muted">No models yet</td></tr>
                                <?php else: ?>
                                    <?php foreach ($fileTypes as $type): ?>
                                    <tr>
                                        <td><span class="file-type-badge">.<?= htmlspecialchars($type['file_type']) ?></span></td>
                                        <td><?= number_format($type['count']) ?></td>
                                        <td><?= formatBytes($type['size'] ?? 0) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="stats-section">
                    <h2>Models by Category</h2>
                    <div class="stats-table-container">
                        <table class="stats-table">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Models</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($categoryStats)): ?>
                                <tr><td colspan="2" class="text-muted">No categories</td></tr>
                                <?php else: ?>
                                    <?php foreach ($categoryStats as $cat): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($cat['name']) ?></td>
                                        <td><?= number_format($cat['count']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <?php if (!empty($collectionStats)): ?>
                <section class="stats-section">
                    <h2>Top Collections</h2>
                    <div class="stats-table-container">
                        <table class="stats-table">
                            <thead>
                                <tr>
                                    <th>Collection</th>
                                    <th>Models</th>
                                    <th>Size</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($collectionStats as $col): ?>
                                <tr>
                                    <td><?= htmlspecialchars($col['collection']) ?></td>
                                    <td><?= number_format($col['count']) ?></td>
                                    <td><?= formatBytes($col['size'] ?? 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <?php endif; ?>

                <?php if (!empty($largestModels)): ?>
                <section class="stats-section">
                    <h2>Largest Models</h2>
                    <div class="stats-table-container">
                        <table class="stats-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Size</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($largestModels as $model): ?>
                                <tr>
                                    <td><?= htmlspecialchars($model['name']) ?></td>
                                    <td><span class="file-type-badge">.<?= htmlspecialchars($model['file_type']) ?></span></td>
                                    <td><?= formatBytes($model['file_size'] ?? 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <?php endif; ?>

                <?php if (!empty($authorStats)): ?>
                <section class="stats-section">
                    <h2>Top Authors</h2>
                    <div class="stats-table-container">
                        <table class="stats-table">
                            <thead>
                                <tr>
                                    <th>Author</th>
                                    <th>Models</th>
                                    <th>Size</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($authorStats as $author): ?>
                                <tr>
                                    <td><?= htmlspecialchars($author['author']) ?></td>
                                    <td><?= number_format($author['count']) ?></td>
                                    <td><?= formatBytes($author['size'] ?? 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <?php endif; ?>

                <?php if (!empty($recentUploads)): ?>
                <section class="stats-section">
                    <h2>Recent Upload Activity (7 days)</h2>
                    <div class="stats-table-container">
                        <table class="stats-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Uploads</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentUploads as $day): ?>
                                <tr>
                                    <td><?= htmlspecialchars($day['date']) ?></td>
                                    <td><?= number_format($day['count']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <?php endif; ?>

                <?php if (!empty($monthlyUploads)): ?>
                <section class="stats-section">
                    <h2>Monthly Trends (12 months)</h2>
                    <div class="stats-table-container">
                        <table class="stats-table">
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th>Uploads</th>
                                    <th>Size</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($monthlyUploads as $month): ?>
                                <tr>
                                    <td><?= date('M Y', strtotime($month['month'] . '-01')) ?></td>
                                    <td><?= number_format($month['count']) ?></td>
                                    <td><?= formatBytes($month['size'] ?? 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <?php endif; ?>

                <?php if (!empty($missingFiles)): ?>
                <section class="stats-section stats-section-danger">
                    <h2>Missing Files</h2>
                    <p class="section-description">These models exist in the database but their files are missing from disk.</p>
                    <div class="stats-table-container">
                        <table class="stats-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Filename</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($missingFiles as $file): ?>
                                <tr>
                                    <td><?= $file['id'] ?></td>
                                    <td><?= htmlspecialchars($file['name']) ?></td>
                                    <td class="text-muted"><?= htmlspecialchars($file['filename']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <?php endif; ?>

                <?php if (!empty($orphanedFiles)): ?>
                <section class="stats-section stats-section-warning">
                    <h2>Orphaned Files</h2>
                    <p class="section-description">These files exist on disk but have no database entry. They may be safe to delete.</p>
                    <div class="stats-table-container">
                        <table class="stats-table">
                            <thead>
                                <tr>
                                    <th>Filename</th>
                                    <th>Size</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orphanedFiles as $file): ?>
                                <tr>
                                    <td class="text-muted"><?= htmlspecialchars($file['filename']) ?></td>
                                    <td><?= formatBytes($file['size']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <?php endif; ?>
            </div>
        </div>

<?php require_once 'includes/footer.php'; ?>
