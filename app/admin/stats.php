<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/dedup.php';

// Require view stats permission
if (!isLoggedIn() || !canViewStats()) {
    $_SESSION['error'] = 'You do not have permission to view statistics.';
    header('Location: ' . route('home'));
    exit;
}

$pageTitle = 'Statistics';
$activePage = 'admin';
$adminPage = 'stats';

$db = getDB();

$message = '';
$messageType = 'success';

// Handle file cleanup actions (requires admin permission)
// CSRF protection for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    $message = 'Invalid request. Please refresh the page and try again.';
    $messageType = 'error';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isAdmin()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_missing') {
        // Delete a single missing file entry from database
        $modelId = (int)($_POST['model_id'] ?? 0);
        if ($modelId) {
            $stmt = $db->prepare('DELETE FROM models WHERE id = :id');
            $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
            $stmt->execute();
            $message = 'Removed missing file entry from database.';
            logInfo('Removed missing file from database', ['model_id' => $modelId]);
        }
    } elseif ($action === 'delete_all_missing') {
        // Delete all missing file entries (parts with missing files)
        // Process in batches to avoid memory exhaustion
        $batchSize = 100;
        $offset = 0;
        $deletedCount = 0;

        while (true) {
            // Use prepared statement with parameter binding for LIMIT/OFFSET to prevent SQL injection
            $stmt = $db->prepare("SELECT id, file_path, dedup_path, file_type, part_count FROM models WHERE file_path IS NOT NULL LIMIT :limit OFFSET :offset");
            $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $result = $stmt->execute();
            $hasRows = false;
            $idsToDelete = [];

            while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
                $hasRows = true;
                // Skip parent models (ZIP containers) - they don't have actual files
                if ($row['file_type'] === 'zip' && $row['part_count'] > 0) {
                    continue;
                }
                $filePath = getAbsoluteFilePath($row);
                if (!file_exists($filePath) || !is_file($filePath)) {
                    $idsToDelete[] = $row['id'];
                }
            }

            // Delete the missing entries in this batch
            foreach ($idsToDelete as $id) {
                // Get parent_id to update part count later
                $stmt = $db->prepare('SELECT parent_id FROM models WHERE id = :id');
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $parentResult = $stmt->execute();
                $parentRow = $parentResult->fetchArray(PDO::FETCH_ASSOC);

                $stmt = $db->prepare('DELETE FROM models WHERE id = :id');
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $stmt->execute();

                // Update parent's part count if this was a child
                if ($parentRow && $parentRow['parent_id']) {
                    $stmt = $db->prepare('UPDATE models SET part_count = (SELECT COUNT(*) FROM models WHERE parent_id = :parent_id1) WHERE id = :parent_id2');
                    $stmt->bindValue(':parent_id1', $parentRow['parent_id'], PDO::PARAM_INT);
                    $stmt->bindValue(':parent_id2', $parentRow['parent_id'], PDO::PARAM_INT);
                    $stmt->execute();
                }

                $deletedCount++;
            }

            if (!$hasRows) break;
            $offset += $batchSize;
        }

        $message = "Removed $deletedCount missing file entries from database.";
        logInfo('Removed all missing files from database', ['count' => $deletedCount]);
    } elseif ($action === 'delete_orphan') {
        // Delete a single orphaned file from disk
        $filename = $_POST['filename'] ?? '';
        if ($filename && !str_contains($filename, '..') && !str_contains($filename, '/')) {
            $filePath = UPLOAD_PATH . $filename;
            if (file_exists($filePath) && is_file($filePath)) {
                unlink($filePath);
                $message = 'Deleted orphaned file from disk.';
                logInfo('Deleted orphaned file', ['filename' => $filename]);
            }
        }
    } elseif ($action === 'delete_all_orphans') {
        // Delete all orphaned files from disk
        // Use database queries instead of loading all filenames into memory
        $assetsPath = realpath(UPLOAD_PATH);
        if ($assetsPath && is_dir($assetsPath)) {
            $deletedCount = 0;
            $iterator = new DirectoryIterator($assetsPath);
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getFilename() !== '.gitkeep') {
                    // Check if file exists in database
                    $stmt = $db->prepare('SELECT COUNT(*) as count FROM models WHERE filename = :filename');
                    $stmt->bindValue(':filename', $file->getFilename(), PDO::PARAM_STR);
                    $result = $stmt->execute();
                    $row = $result->fetchArray(PDO::FETCH_ASSOC);

                    if ($row['count'] == 0) {
                        unlink($file->getPathname());
                        $deletedCount++;
                    }
                }
            }
            $message = "Deleted $deletedCount orphaned files from disk.";
            logInfo('Deleted all orphaned files', ['count' => $deletedCount]);
        }
    } elseif ($action === 'calculate_hashes') {
        // Calculate missing file hashes
        $count = calculateMissingHashes();
        $message = "Calculated hashes for $count files.";
    } elseif ($action === 'recalculate_3mf_hashes') {
        // Recalculate 3MF hashes using content-based hashing
        $count = recalculate3mfHashes();
        $message = "Recalculated content-based hashes for $count 3MF files.";
    } elseif ($action === 'run_dedup_scan') {
        // Run deduplication scan
        $result = runDeduplicationScan();
        if ($result['success']) {
            $message = "Deduplication complete: {$result['hashes_processed']} duplicate sets processed, {$result['files_deleted']} files removed, " . formatBytes($result['space_saved']) . " saved.";
        } else {
            $message = 'Deduplication scan failed.';
            $messageType = 'error';
        }
    } elseif ($action === 'run_dedup_cleanup') {
        // Run cleanup scan (migrate single-reference files back)
        $result = runDedupCleanupScan();
        if ($result['success']) {
            $message = "Cleanup complete: {$result['migrated']} files migrated back to original locations.";
        }
    }
}

// Get model count
$result = $db->query('SELECT COUNT(*) as count FROM models');
$modelCount = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;

// Get total storage from database
$result = $db->query('SELECT SUM(file_size) as total, AVG(file_size) as avg FROM models');
$row = $result->fetchArray(PDO::FETCH_ASSOC);
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
$userStats = $result->fetchArray(PDO::FETCH_ASSOC);
$totalUsers = $userStats['total'] ?? 0;
$adminUsers = $userStats['admins'] ?? 0;

// Get models by file type (exclude parent models/ZIP containers which are just organizational entries)
$result = $db->query('SELECT file_type, COUNT(*) as count, SUM(file_size) as size FROM models WHERE NOT (file_type = "zip" AND part_count > 0) GROUP BY file_type ORDER BY count DESC');
$fileTypes = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
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
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $categoryStats[] = $row;
}

// Get uncategorized models count
$result = $db->query('
    SELECT COUNT(*) as count FROM models m
    WHERE NOT EXISTS (SELECT 1 FROM model_categories mc WHERE mc.model_id = m.id)
');
$uncategorizedCount = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;

// Get models without source URL
$result = $db->query('SELECT COUNT(*) as count FROM models WHERE source_url IS NULL OR source_url = ""');
$noSourceCount = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;

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
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $collectionStats[] = $row;
}

// Get top creators
$result = $db->query('
    SELECT creator, COUNT(*) as count, SUM(file_size) as size
    FROM models
    WHERE creator IS NOT NULL AND creator != ""
    GROUP BY creator
    ORDER BY count DESC
    LIMIT 10
');
$creatorStats = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $creatorStats[] = $row;
}

// Get recent uploads (last 7 days)
$dbType = $db->getType();
if ($dbType === 'mysql') {
    $result = $db->query('
        SELECT DATE(created_at) as date, COUNT(*) as count
        FROM models
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ');
} else {
    $result = $db->query('
        SELECT DATE(created_at) as date, COUNT(*) as count
        FROM models
        WHERE created_at >= datetime("now", "-7 days")
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ');
}
$recentUploads = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $recentUploads[] = $row;
}

// Get monthly upload trends (last 12 months)
if ($dbType === 'mysql') {
    $result = $db->query('
        SELECT DATE_FORMAT(created_at, "%Y-%m") as month, COUNT(*) as count, SUM(file_size) as size
        FROM models
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, "%Y-%m")
        ORDER BY month DESC
    ');
} else {
    $result = $db->query('
        SELECT strftime("%Y-%m", created_at) as month, COUNT(*) as count, SUM(file_size) as size
        FROM models
        WHERE created_at >= datetime("now", "-12 months")
        GROUP BY strftime("%Y-%m", created_at)
        ORDER BY month DESC
    ');
}
$monthlyUploads = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $monthlyUploads[] = $row;
}

// Get conversion statistics
$result = $db->query('
    SELECT
        COUNT(*) as converted_count,
        SUM(original_size) as total_original_size,
        SUM(file_size) as total_converted_size,
        SUM(original_size - file_size) as total_savings
    FROM models
    WHERE original_size IS NOT NULL AND original_size > 0
');
$conversionStats = $result->fetchArray(PDO::FETCH_ASSOC);

// Get count of STL files that could be converted
$result = $db->query('SELECT COUNT(*) as count FROM models WHERE file_type = "stl"');
$stlCount = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;

// Get oldest and newest models
$result = $db->query('SELECT name, created_at FROM models ORDER BY created_at ASC LIMIT 1');
$oldestModel = $result->fetchArray(PDO::FETCH_ASSOC);

$result = $db->query('SELECT name, created_at FROM models ORDER BY created_at DESC LIMIT 1');
$newestModel = $result->fetchArray(PDO::FETCH_ASSOC);

// Get largest models
$result = $db->query('SELECT name, file_size, file_type FROM models ORDER BY file_size DESC LIMIT 5');
$largestModels = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $largestModels[] = $row;
}

// Check for missing files (files in DB but not on disk)
// Limit to first 100 to avoid memory issues on large databases
$result = $db->query('SELECT id, name, filename, file_path, dedup_path, file_type, part_count FROM models WHERE file_path IS NOT NULL LIMIT 100');
$missingFiles = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    // Skip parent models (ZIP containers) - they don't have actual files
    if ($row['file_type'] === 'zip' && $row['part_count'] > 0) {
        continue;
    }
    $filePath = getAbsoluteFilePath($row);
    if (!file_exists($filePath) || !is_file($filePath)) {
        $missingFiles[] = $row;
    }
}

// Check for orphaned files (files on disk but not in DB)
// Limit to first 100 to avoid memory issues
$orphanedFiles = [];
if ($assetsPath && is_dir($assetsPath)) {
    $iterator = new DirectoryIterator($assetsPath);
    $count = 0;
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename() !== '.gitkeep') {
            // Check if file exists in database
            $stmt = $db->prepare('SELECT COUNT(*) as count FROM models WHERE filename = :filename');
            $stmt->bindValue(':filename', $file->getFilename(), PDO::PARAM_STR);
            $result = $stmt->execute();
            $row = $result->fetchArray(PDO::FETCH_ASSOC);

            if ($row['count'] == 0) {
                $orphanedFiles[] = [
                    'filename' => $file->getFilename(),
                    'size' => $file->getSize()
                ];
                $count++;
                if ($count >= 100) break; // Limit to 100 orphaned files
            }
        }
    }
}

// Get deduplication statistics
$dedupStats = getDeduplicationStats();

// Count files without hashes
$result = $db->query('SELECT COUNT(*) as count FROM models WHERE (file_hash IS NULL OR file_hash = "") AND file_path IS NOT NULL');
$filesWithoutHash = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;

// formatBytes is defined in includes/helpers.php

require_once __DIR__ . '/../../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

            <div class="admin-content">
                <div class="page-header">
                    <h1>Statistics</h1>
                    <p>Overview of your 3D model library</p>
                </div>

            <?php if ($message): ?>
            <div role="<?= $messageType === 'success' ? 'status' : 'alert' ?>" class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

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

            <!-- Conversion Statistics -->
            <?php if ($conversionStats['converted_count'] > 0 || $stlCount > 0): ?>
            <section class="stats-section stats-section-full stats-section-conversion">
                <h2>File Conversion</h2>
                <div class="conversion-stats-grid">
                    <div class="conversion-stat">
                        <span class="conversion-stat-value"><?= number_format($conversionStats['converted_count'] ?? 0) ?></span>
                        <span class="conversion-stat-label">Files Converted</span>
                    </div>
                    <div class="conversion-stat">
                        <span class="conversion-stat-value"><?= number_format($stlCount) ?></span>
                        <span class="conversion-stat-label">STL Files Remaining</span>
                    </div>
                    <?php if ($conversionStats['total_savings'] > 0): ?>
                    <div class="conversion-stat conversion-stat-highlight">
                        <span class="conversion-stat-value"><?= formatBytes($conversionStats['total_savings']) ?></span>
                        <span class="conversion-stat-label">Total Space Saved</span>
                    </div>
                    <div class="conversion-stat">
                        <span class="conversion-stat-value"><?= round(($conversionStats['total_savings'] / $conversionStats['total_original_size']) * 100, 1) ?>%</span>
                        <span class="conversion-stat-label">Average Reduction</span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($conversionStats['total_savings'] > 0): ?>
                <p class="conversion-detail">
                    Original size: <?= formatBytes($conversionStats['total_original_size']) ?> &rarr;
                    Converted size: <?= formatBytes($conversionStats['total_converted_size']) ?>
                </p>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <!-- File Deduplication -->
            <section class="stats-section stats-section-full stats-section-dedup">
                <h2>File Deduplication</h2>
                <p class="section-description">
                    Deduplication saves disk space by storing only one copy of identical files.
                </p>
                <div class="dedup-stats-grid">
                    <div class="dedup-stat">
                        <span class="dedup-stat-value"><?= number_format($dedupStats['dedup_file_count']) ?></span>
                        <span class="dedup-stat-label">Deduplicated Files</span>
                    </div>
                    <div class="dedup-stat">
                        <span class="dedup-stat-value"><?= number_format($dedupStats['dedup_part_count']) ?></span>
                        <span class="dedup-stat-label">Parts Using Dedup</span>
                    </div>
                    <?php if ($dedupStats['space_saved'] > 0): ?>
                    <div class="dedup-stat dedup-stat-highlight">
                        <span class="dedup-stat-value"><?= formatBytes($dedupStats['space_saved']) ?></span>
                        <span class="dedup-stat-label">Space Saved</span>
                    </div>
                    <?php endif; ?>
                    <?php if ($dedupStats['potential_duplicates'] > 0): ?>
                    <div class="dedup-stat dedup-stat-warning">
                        <span class="dedup-stat-value"><?= number_format($dedupStats['potential_duplicates']) ?></span>
                        <span class="dedup-stat-label">Potential Duplicates</span>
                    </div>
                    <div class="dedup-stat">
                        <span class="dedup-stat-value"><?= formatBytes($dedupStats['potential_savings']) ?></span>
                        <span class="dedup-stat-label">Potential Savings</span>
                    </div>
                    <?php endif; ?>
                    <?php if ($filesWithoutHash > 0): ?>
                    <div class="dedup-stat dedup-stat-info">
                        <span class="dedup-stat-value"><?= number_format($filesWithoutHash) ?></span>
                        <span class="dedup-stat-label">Files Without Hash</span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if (isAdmin()): ?>
                <div class="dedup-actions">
                    <?php if ($filesWithoutHash > 0): ?>
                    <form method="POST" style="display: inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="calculate_hashes">
                        <button type="submit" class="btn btn-secondary btn-small">Calculate Missing Hashes</button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" style="display: inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="recalculate_3mf_hashes">
                        <button type="submit" class="btn btn-secondary btn-small" title="Recalculate hashes for 3MF files using content-based hashing (ignores ZIP timestamps)">Recalculate 3MF Hashes</button>
                    </form>
                    <?php if ($dedupStats['potential_duplicates'] > 0): ?>
                    <form method="POST" style="display: inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="run_dedup_scan">
                        <button type="submit" class="btn btn-primary btn-small" data-confirm="This will deduplicate <?= $dedupStats['potential_duplicates'] ?> duplicate file sets, potentially saving <?= formatBytes($dedupStats['potential_savings']) ?>. Continue?">Run Deduplication</button>
                    </form>
                    <?php endif; ?>
                    <?php if ($dedupStats['dedup_file_count'] > 0): ?>
                    <form method="POST" style="display: inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="run_dedup_cleanup">
                        <button type="submit" class="btn btn-secondary btn-small" title="Move files back if only one part references them">Run Cleanup</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </section>

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
                        <div class="health-bar" role="progressbar" aria-valuenow="<?= round($diskUsedPercent, 1) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Disk usage">
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
                    <h2>Parts by File Type</h2>
                    <div class="stats-table-container">
                        <table class="stats-table" aria-label="Parts by file type">
                            <thead>
                                <tr>
                                    <th scope="col">Type</th>
                                    <th scope="col">Count</th>
                                    <th scope="col">Size</th>
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
                        <table class="stats-table" aria-label="Models by category">
                            <thead>
                                <tr>
                                    <th scope="col">Category</th>
                                    <th scope="col">Models</th>
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
                        <table class="stats-table" aria-label="Top collections">
                            <thead>
                                <tr>
                                    <th scope="col">Collection</th>
                                    <th scope="col">Models</th>
                                    <th scope="col">Size</th>
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
                        <table class="stats-table" aria-label="Largest models">
                            <thead>
                                <tr>
                                    <th scope="col">Name</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">Size</th>
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

                <?php if (!empty($creatorStats)): ?>
                <section class="stats-section">
                    <h2>Top Creators</h2>
                    <div class="stats-table-container">
                        <table class="stats-table" aria-label="Top creators">
                            <thead>
                                <tr>
                                    <th scope="col">Creator</th>
                                    <th scope="col">Models</th>
                                    <th scope="col">Size</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($creatorStats as $creator): ?>
                                <tr>
                                    <td><?= htmlspecialchars($creator['creator']) ?></td>
                                    <td><?= number_format($creator['count']) ?></td>
                                    <td><?= formatBytes($creator['size'] ?? 0) ?></td>
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
                        <table class="stats-table" aria-label="Recent upload activity">
                            <thead>
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Uploads</th>
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
                        <table class="stats-table" aria-label="Monthly trends">
                            <thead>
                                <tr>
                                    <th scope="col">Month</th>
                                    <th scope="col">Uploads</th>
                                    <th scope="col">Size</th>
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
                    <?php if (isAdmin()): ?>
                    <form method="POST" style="margin-bottom: 1rem;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_all_missing">
                        <button type="submit" class="btn btn-danger btn-small" data-confirm="Delete all <?= count($missingFiles) ?> missing file entries from the database?">Remove All from Database</button>
                    </form>
                    <?php endif; ?>
                    <div class="stats-table-container">
                        <table class="stats-table" aria-label="Missing files">
                            <thead>
                                <tr>
                                    <th scope="col">ID</th>
                                    <th scope="col">Name</th>
                                    <th scope="col">File Path</th>
                                    <?php if (isAdmin()): ?><th scope="col">Actions</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($missingFiles as $file): ?>
                                <tr>
                                    <td><?= $file['id'] ?></td>
                                    <td><?= htmlspecialchars($file['name']) ?></td>
                                    <td class="text-muted"><?= htmlspecialchars(getRealFilePath($file)) ?></td>
                                    <?php if (isAdmin()): ?>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_missing">
                                            <input type="hidden" name="model_id" value="<?= $file['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-small">Remove</button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
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
                    <?php if (isAdmin()): ?>
                    <form method="POST" style="margin-bottom: 1rem;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_all_orphans">
                        <button type="submit" class="btn btn-warning btn-small" data-confirm="Delete all <?= count($orphanedFiles) ?> orphaned files from disk? This cannot be undone.">Delete All from Disk</button>
                    </form>
                    <?php endif; ?>
                    <div class="stats-table-container">
                        <table class="stats-table" aria-label="Orphaned files">
                            <thead>
                                <tr>
                                    <th scope="col">Filename</th>
                                    <th scope="col">Size</th>
                                    <?php if (isAdmin()): ?><th scope="col">Actions</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orphanedFiles as $file): ?>
                                <tr>
                                    <td class="text-muted"><?= htmlspecialchars($file['filename']) ?></td>
                                    <td><?= formatBytes($file['size']) ?></td>
                                    <?php if (isAdmin()): ?>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_orphan">
                                            <input type="hidden" name="filename" value="<?= htmlspecialchars($file['filename']) ?>">
                                            <button type="submit" class="btn btn-warning btn-small">Delete</button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <?php endif; ?>
            </div>
        </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
