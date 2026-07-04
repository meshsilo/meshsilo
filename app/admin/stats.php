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

$statsService = new StatsService();

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
        if ($statsService->deleteMissing($modelId)) {
            $message = 'Removed missing file entry from database.';
        }
    } elseif ($action === 'delete_all_missing') {
        // Delete all missing file entries (parts with missing files)
        $deletedCount = $statsService->deleteAllMissing();
        $message = "Removed $deletedCount missing file entries from database.";
    } elseif ($action === 'delete_orphan') {
        // Delete a single orphaned file from disk
        $filename = $_POST['filename'] ?? '';
        if ($statsService->deleteOrphan($filename)) {
            $message = 'Deleted orphaned file from disk.';
        }
    } elseif ($action === 'delete_all_orphans') {
        // Delete all orphaned files from disk
        $deletedCount = $statsService->deleteAllOrphans();
        if ($deletedCount !== null) {
            $message = "Deleted $deletedCount orphaned files from disk.";
        }
    } elseif ($action === 'calculate_hashes') {
        // Calculate missing file hashes
        $hashResult = $statsService->calculateHashes();
        $message = "Calculated hashes for {$hashResult['calculated']} files" . ($hashResult['errors'] > 0 ? " ({$hashResult['errors']} errors)" : '') . '.';
    } elseif ($action === 'recalculate_3mf_hashes') {
        // Recalculate 3MF hashes using content-based hashing
        $count = $statsService->recalculate3mfHashes();
        $message = "Recalculated content-based hashes for $count 3MF files.";
    } elseif ($action === 'run_dedup_scan') {
        // Run deduplication scan
        $result = $statsService->runDeduplicationScan();
        if ($result['success']) {
            $message = "Deduplication complete: {$result['hashes_processed']} duplicate sets processed, {$result['files_deleted']} files removed, " . formatBytes($result['space_saved']) . " saved.";
        } else {
            $message = 'Deduplication scan failed.';
            $messageType = 'error';
        }
    } elseif ($action === 'run_dedup_cleanup') {
        // Run cleanup scan (migrate single-reference files back)
        $result = $statsService->runDedupCleanupScan();
        if ($result['success']) {
            $message = "Cleanup complete: {$result['migrated']} files migrated back to original locations.";
        }
    } elseif ($action === 'compress_all_pdfs') {
        // Retroactively dispatch OptimizePdf jobs for every PDF attachment
        // that hasn't already been compressed.
        $queued = $statsService->queueAllPdfCompression();
        if ($queued > 0) {
            $message = "Queued $queued PDF(s) for compression. Processing runs in the background.";
        } else {
            $message = 'No uncompressed PDFs found.';
        }
    } elseif ($action === 'convert_all_images_webp') {
        // Retroactively dispatch OptimizeImage jobs for all unconverted JPEG/PNG
        // attachments and model thumbnails.
        $queued = $statsService->queueAllImageWebpConversion();
        if ($queued > 0) {
            $message = "Queued $queued image(s) for WebP conversion. Conversions run in the background &mdash; check the queue status page for progress.";
        } else {
            $message = 'No JPEG/PNG images found to convert. All images are already in WebP format.';
        }
    }
}

// Gather all display statistics via the service (read-only queries)
$stats = $statsService->getDisplayStats();
$modelCount = $stats['modelCount'];
$totalParts = $stats['totalParts'];
$actualStorage = $stats['actualStorage'];
$fileCount = $stats['fileCount'];
$uncategorizedCount = $stats['uncategorizedCount'];
$noSourceCount = $stats['noSourceCount'];
$avgModelSize = $stats['avgModelSize'];
$diskFree = $stats['diskFree'];
$diskTotal = $stats['diskTotal'];
$diskUsedPercent = $stats['diskUsedPercent'];
$dbSize = $stats['dbSize'];
$totalUsers = $stats['totalUsers'];
$adminUsers = $stats['adminUsers'];
$fileTypes = $stats['fileTypes'];
$categoryStats = $stats['categoryStats'];
$collectionStats = $stats['collectionStats'];
$creatorStats = $stats['creatorStats'];
$recentUploads = $stats['recentUploads'];
$monthlyUploads = $stats['monthlyUploads'];
$conversionStats = $stats['conversionStats'];
$stlCount = $stats['stlCount'];
$oldestModel = $stats['oldestModel'];
$newestModel = $stats['newestModel'];
$largestModels = $stats['largestModels'];
$missingFiles = $stats['missingFiles'];
$orphanedFiles = $stats['orphanedFiles'];
$dedupStats = $stats['dedupStats'];
$filesWithoutHash = $stats['filesWithoutHash'];
$unconvertedAttachments = $stats['unconvertedAttachments'];
$unconvertedThumbnails = $stats['unconvertedThumbnails'];
$totalUnconverted = $stats['totalUnconverted'];
$uncompressedPdfs = $stats['uncompressedPdfs'];
$compressedPdfs = $stats['compressedPdfs'];

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
                    <div class="stat-icon"><i class="fa-solid fa-cube"></i></div>
                    <div class="stat-value"><?= number_format($modelCount) ?></div>
                    <div class="stat-label">Models<?= $totalParts > 0 ? " ($totalParts parts)" : '' ?></div>
                </div>

                <div class="stat-card stat-card-large">
                    <div class="stat-icon"><i class="fa-solid fa-hard-drive"></i></div>
                    <div class="stat-value"><?= formatBytes($actualStorage) ?></div>
                    <div class="stat-label">Storage Used (<?= number_format($fileCount) ?> files)</div>
                </div>

                <div class="stat-card">
                    <div class="stat-value"><?= formatBytes($avgModelSize) ?></div>
                    <div class="stat-label">Avg File Size</div>
                </div>

                <div class="stat-card">
                    <div class="stat-value"><?= $totalUsers ?></div>
                    <div class="stat-label">Users (<?= $adminUsers ?> admin)</div>
                </div>
            </div>

            <!-- Conversion Statistics -->
            <?php if ($conversionStats['converted_count'] > 0 || $stlCount > 0): ?>
            <section class="section-card section-card-full section-card-success">
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
                        <span class="conversion-stat-value"><?= $conversionStats['total_original_size'] > 0 ? round(($conversionStats['total_savings'] / $conversionStats['total_original_size']) * 100, 1) : 0 ?>%</span>
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
            <section class="section-card section-card-full section-card-dedup">
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

            <!-- Image Optimization -->
            <section class="section-card section-card-full">
                <h2>Image Optimization (WebP)</h2>
                <p class="section-description">
                    Convert existing JPEG/PNG images to WebP to reduce disk usage. Conversions run in the background queue.
                </p>
                <div class="dedup-stats-grid">
                    <div class="dedup-stat <?= $totalUnconverted > 0 ? 'dedup-stat-warning' : '' ?>">
                        <span class="dedup-stat-value"><?= number_format($totalUnconverted) ?></span>
                        <span class="dedup-stat-label">Images Awaiting Conversion</span>
                    </div>
                    <div class="dedup-stat">
                        <span class="dedup-stat-value"><?= number_format($unconvertedAttachments) ?></span>
                        <span class="dedup-stat-label">Attachments</span>
                    </div>
                    <div class="dedup-stat">
                        <span class="dedup-stat-value"><?= number_format($unconvertedThumbnails) ?></span>
                        <span class="dedup-stat-label">Model Thumbnails</span>
                    </div>
                </div>
                <?php if (isAdmin() && $totalUnconverted > 0): ?>
                <div class="dedup-actions">
                    <form method="POST" style="display: inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="convert_all_images_webp">
                        <button type="submit" class="btn btn-primary btn-small" data-confirm="This will queue <?= $totalUnconverted ?> image(s) for background WebP conversion. Originals will be deleted as each conversion completes. Continue?">Convert All to WebP</button>
                    </form>
                </div>
                <?php endif; ?>
            </section>

            <!-- PDF Compression -->
            <section class="section-card section-card-full">
                <h2>PDF Compression</h2>
                <p class="section-description">
                    Compress PDF attachments with Ghostscript or qpdf. Mode and enabled/disabled state are configured in <a href="<?= route('admin.settings') ?>">Site Settings</a>. Processing runs in the background queue.
                </p>
                <div class="dedup-stats-grid">
                    <div class="dedup-stat <?= $uncompressedPdfs > 0 ? 'dedup-stat-warning' : '' ?>">
                        <span class="dedup-stat-value"><?= number_format($uncompressedPdfs) ?></span>
                        <span class="dedup-stat-label">Uncompressed PDFs</span>
                    </div>
                    <div class="dedup-stat">
                        <span class="dedup-stat-value"><?= number_format($compressedPdfs) ?></span>
                        <span class="dedup-stat-label">Compressed PDFs</span>
                    </div>
                </div>
                <?php if (isAdmin() && $uncompressedPdfs > 0): ?>
                <div class="dedup-actions">
                    <form method="POST" style="display: inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="compress_all_pdfs">
                        <button type="submit" class="btn btn-primary btn-small" data-confirm="Queue <?= $uncompressedPdfs ?> PDF(s) for background compression? The current mode in Site Settings will be used. Originals are only replaced when the result is measurably smaller.">Compress All PDFs</button>
                    </form>
                </div>
                <?php endif; ?>
            </section>

            <!-- System Health -->
            <?php
            $hasIssues = !empty($missingFiles) || !empty($orphanedFiles) || $uncategorizedCount > 0;
            $issueClass = $hasIssues ? 'section-card-warning' : 'section-card-success';
            ?>
            <section class="section-card section-card-full <?= $issueClass ?>">
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
            <section class="section-card section-card-full">
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

            <div class="section-cards">
                <section class="section-card">
                    <h2>Parts by File Type</h2>
                    <div class="stats-table-container">
                        <table class="data-table" aria-label="Parts by file type">
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

                <section class="section-card">
                    <h2>Models by Category</h2>
                    <div class="stats-table-container">
                        <table class="data-table" aria-label="Models by category">
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
                <section class="section-card">
                    <h2>Top Collections</h2>
                    <div class="stats-table-container">
                        <table class="data-table" aria-label="Top collections">
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
                <section class="section-card">
                    <h2>Largest Models</h2>
                    <div class="stats-table-container">
                        <table class="data-table" aria-label="Largest models">
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
                <section class="section-card">
                    <h2>Top Creators</h2>
                    <div class="stats-table-container">
                        <table class="data-table" aria-label="Top creators">
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
                <section class="section-card">
                    <h2>Recent Upload Activity (7 days)</h2>
                    <div class="stats-table-container">
                        <table class="data-table" aria-label="Recent upload activity">
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
                <section class="section-card">
                    <h2>Monthly Trends (12 months)</h2>
                    <div class="stats-table-container">
                        <table class="data-table" aria-label="Monthly trends">
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
                <section class="section-card section-card-danger">
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
                        <table class="data-table" aria-label="Missing files">
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
                <section class="section-card section-card-warning">
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
                        <table class="data-table" aria-label="Orphaned files">
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
