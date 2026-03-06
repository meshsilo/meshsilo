<?php

/**
 * Authentication Middleware
 *
 * Ensures user is logged in before accessing protected routes.
 */

require_once __DIR__ . '/MiddlewareInterface.php';

class AuthMiddleware implements MiddlewareInterface
{
    /**
     * Handle the middleware
     */
    public function handle(array $params): bool
    {
        if (!function_exists('isLoggedIn') || !isLoggedIn()) {
            // Store intended destination (sanitize to prevent open redirect)
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            if (str_starts_with($uri, '/') && !str_starts_with($uri, '//')) {
                $_SESSION['redirect_after_login'] = $uri;
            }

            // Redirect to login
            header('Location: ' . Router::url('login'));
            exit;
        }

        return true;
    }
}
