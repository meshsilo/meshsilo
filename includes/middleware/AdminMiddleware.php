<?php
/**
 * Admin Middleware
 *
 * Ensures user has admin privileges before accessing admin routes.
 */

require_once __DIR__ . '/MiddlewareInterface.php';

class AdminMiddleware implements MiddlewareInterface {
    /**
     * Handle the middleware
     */
    public function handle(array $params): bool {
        // First check if logged in
        if (!function_exists('isLoggedIn') || !isLoggedIn()) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            header('Location: ' . Router::url('login'));
            exit;
        }

        // Then check admin permission
        if (!function_exists('isAdmin') || !isAdmin()) {
            $_SESSION['error'] = 'You do not have permission to access this page.';
            header('Location: ' . Router::url('home'));
            exit;
        }

        return true;
    }
}
