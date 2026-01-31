<?php
/**
 * Download Export File
 *
 * Serves an export ZIP file using a session-based download token.
 */
require_once __DIR__ . '/../../includes/config.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo 'Not authenticated';
    exit;
}

$token = $_GET['token'] ?? '';
if (!$token) {
    http_response_code(400);
    echo 'No download token';
    exit;
}

$sessionKey = 'export_download_' . $token;
$zipPath = $_SESSION[$sessionKey] ?? null;

if (!$zipPath || !file_exists($zipPath)) {
    http_response_code(404);
    echo 'Export file not found or expired';
    exit;
}

// Remove from session (single use)
unset($_SESSION[$sessionKey]);

// Send file
$filename = basename($zipPath);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($zipPath);

// Clean up temp file
unlink($zipPath);
exit;
