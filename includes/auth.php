<?php
session_start();

// Pages that don't require authentication
$publicPages = ['login.php'];

// Get current page filename
$currentPage = basename($_SERVER['PHP_SELF']);

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Get current user
function getCurrentUser() {
    return $_SESSION['user'] ?? null;
}

// Redirect to login if not authenticated (unless on public page)
if (!isLoggedIn() && !in_array($currentPage, $publicPages)) {
    logWarning('Unauthorized access attempt', [
        'page' => $currentPage,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);
    header('Location: ' . ($GLOBALS['baseDir'] ?? '') . 'login.php');
    exit;
}
