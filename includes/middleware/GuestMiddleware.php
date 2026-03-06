<?php
/**
 * Guest Middleware
 *
 * Ensures user is NOT logged in (for login/register pages).
 */

require_once __DIR__ . '/MiddlewareInterface.php';

class GuestMiddleware implements MiddlewareInterface {
    /**
     * Handle the middleware
     */
    public function handle(array $params): bool {
        if (function_exists('isLoggedIn') && isLoggedIn()) {
            // Already logged in, redirect to home
            header('Location: ' . Router::url('home'));
            exit;
        }

        return true;
    }
}
