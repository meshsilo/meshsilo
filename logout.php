<?php
session_start();

// Get user info before destroying session
$userId = $_SESSION['user_id'] ?? null;
$username = $_SESSION['user']['username'] ?? 'unknown';

// Include config for logging (after session_start)
require_once 'includes/logger.php';
initLogger();

if ($userId) {
    logInfo('User logged out', [
        'user_id' => $userId,
        'username' => $username,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
}

session_destroy();
header('Location: login.php');
exit;
