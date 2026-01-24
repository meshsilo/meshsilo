<?php
/**
 * Backup Actions
 */

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user = getCurrentUser();

if (!$user['is_admin']) {
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'create':
        createBackup();
        break;
    case 'list':
        listBackups();
        break;
    case 'download':
        downloadBackup();
        break;
    case 'delete':
        deleteBackup();
        break;
    case 'restore':
        restoreBackup();
        break;
    case 'save_schedule':
        saveBackupSchedule();
        break;
    case 'get_schedule':
        getBackupSchedule();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function createBackup() {
    $backupDir = __DIR__ . '/../db/backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }

    $timestamp = date('Y-m-d_H-i-s');
    $filename = "silo_backup_$timestamp.db";
    $backupPath = $backupDir . '/' . $filename;
    $sourcePath = DB_PATH;

    if (!file_exists($sourcePath)) {
        echo json_encode(['success' => false, 'error' => 'Database not found']);
        return;
    }

    if (!copy($sourcePath, $backupPath)) {
        echo json_encode(['success' => false, 'error' => 'Failed to create backup']);
        return;
    }

    // Compress if possible
    if (function_exists('gzopen')) {
        $gzPath = $backupPath . '.gz';
        $fp = fopen($backupPath, 'rb');
        $gz = gzopen($gzPath, 'wb9');
        while (!feof($fp)) {
            gzwrite($gz, fread($fp, 1024 * 1024));
        }
        fclose($fp);
        gzclose($gz);
        unlink($backupPath);
        $filename .= '.gz';
        $backupPath = $gzPath;
    }

    // Clean up old backups (keep last 10)
    cleanOldBackups($backupDir, 10);

    logActivity('backup_created', 'system', null, $filename);

    echo json_encode([
        'success' => true,
        'filename' => $filename,
        'size' => filesize($backupPath),
        'created_at' => date('Y-m-d H:i:s')
    ]);
}

function listBackups() {
    $backupDir = __DIR__ . '/../db/backups';
    $backups = [];

    if (is_dir($backupDir)) {
        $files = scandir($backupDir, SCANDIR_SORT_DESCENDING);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            if (strpos($file, 'silo_backup_') !== 0) continue;

            $path = $backupDir . '/' . $file;
            $backups[] = [
                'filename' => $file,
                'size' => filesize($path),
                'created_at' => date('Y-m-d H:i:s', filemtime($path))
            ];
        }
    }

    echo json_encode(['success' => true, 'backups' => $backups]);
}

function downloadBackup() {
    $filename = basename($_GET['filename'] ?? '');
    if (empty($filename) || strpos($filename, 'silo_backup_') !== 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid filename']);
        return;
    }

    $backupPath = __DIR__ . '/../db/backups/' . $filename;
    if (!file_exists($backupPath)) {
        echo json_encode(['success' => false, 'error' => 'Backup not found']);
        return;
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($backupPath));
    readfile($backupPath);
    exit;
}

function deleteBackup() {
    $filename = basename($_POST['filename'] ?? '');
    if (empty($filename) || strpos($filename, 'silo_backup_') !== 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid filename']);
        return;
    }

    $backupPath = __DIR__ . '/../db/backups/' . $filename;
    if (!file_exists($backupPath)) {
        echo json_encode(['success' => false, 'error' => 'Backup not found']);
        return;
    }

    if (!unlink($backupPath)) {
        echo json_encode(['success' => false, 'error' => 'Failed to delete backup']);
        return;
    }

    logActivity('backup_deleted', 'system', null, $filename);

    echo json_encode(['success' => true]);
}

function restoreBackup() {
    $filename = basename($_POST['filename'] ?? '');
    if (empty($filename) || strpos($filename, 'silo_backup_') !== 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid filename']);
        return;
    }

    $backupPath = __DIR__ . '/../db/backups/' . $filename;
    if (!file_exists($backupPath)) {
        echo json_encode(['success' => false, 'error' => 'Backup not found']);
        return;
    }

    // Create backup of current database first
    $currentBackup = DB_PATH . '.pre-restore.' . date('Y-m-d_H-i-s');
    if (!copy(DB_PATH, $currentBackup)) {
        echo json_encode(['success' => false, 'error' => 'Failed to backup current database']);
        return;
    }

    // Restore
    $restorePath = $backupPath;

    // Decompress if needed
    if (substr($backupPath, -3) === '.gz') {
        $tempPath = sys_get_temp_dir() . '/silo_restore_' . uniqid() . '.db';
        $gz = gzopen($backupPath, 'rb');
        $fp = fopen($tempPath, 'wb');
        while (!gzeof($gz)) {
            fwrite($fp, gzread($gz, 1024 * 1024));
        }
        fclose($fp);
        gzclose($gz);
        $restorePath = $tempPath;
    }

    if (!copy($restorePath, DB_PATH)) {
        // Restore failed, try to restore the pre-restore backup
        copy($currentBackup, DB_PATH);
        echo json_encode(['success' => false, 'error' => 'Failed to restore backup']);
        return;
    }

    // Clean up temp file
    if (isset($tempPath) && file_exists($tempPath)) {
        unlink($tempPath);
    }

    logActivity('backup_restored', 'system', null, $filename);

    echo json_encode(['success' => true]);
}

function saveBackupSchedule() {
    setSetting('backup_enabled', isset($_POST['backup_enabled']) ? '1' : '0');
    setSetting('backup_frequency', $_POST['backup_frequency'] ?? 'daily');
    setSetting('backup_retention', (int)($_POST['backup_retention'] ?? 10));
    setSetting('backup_time', $_POST['backup_time'] ?? '03:00');

    echo json_encode(['success' => true]);
}

function getBackupSchedule() {
    echo json_encode([
        'success' => true,
        'schedule' => [
            'enabled' => getSetting('backup_enabled', '0') === '1',
            'frequency' => getSetting('backup_frequency', 'daily'),
            'retention' => (int)getSetting('backup_retention', 10),
            'time' => getSetting('backup_time', '03:00')
        ]
    ]);
}

function cleanOldBackups($dir, $keep) {
    $files = glob($dir . '/silo_backup_*');
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    $toDelete = array_slice($files, $keep);
    foreach ($toDelete as $file) {
        unlink($file);
    }
}

/**
 * Run scheduled backup (called by cron)
 */
function runScheduledBackup() {
    if (getSetting('backup_enabled', '0') !== '1') {
        return;
    }

    $lastBackup = getSetting('last_scheduled_backup', '');
    $frequency = getSetting('backup_frequency', 'daily');

    $shouldBackup = false;
    $now = time();

    if (empty($lastBackup)) {
        $shouldBackup = true;
    } else {
        $lastTime = strtotime($lastBackup);
        switch ($frequency) {
            case 'hourly':
                $shouldBackup = ($now - $lastTime) >= 3600;
                break;
            case 'daily':
                $shouldBackup = ($now - $lastTime) >= 86400;
                break;
            case 'weekly':
                $shouldBackup = ($now - $lastTime) >= 604800;
                break;
        }
    }

    if ($shouldBackup) {
        ob_start();
        createBackup();
        ob_end_clean();
        setSetting('last_scheduled_backup', date('Y-m-d H:i:s'));
    }
}
