<?php
/**
 * Permission Middleware
 *
 * Ensures user has a specific permission before accessing routes.
 * Usage: 'permission:upload', 'permission:edit', 'permission:delete'
 */

require_once __DIR__ . '/MiddlewareInterface.php';

class PermissionMiddleware implements MiddlewareInterface {
    private string $permission;

    /**
     * Create middleware instance
     *
     * @param string $permission Permission name (upload, edit, delete, admin, view_stats)
     */
    public function __construct(string $permission) {
        $this->permission = $permission;
    }

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

        // Check the permission
        $permConstant = 'PERM_' . strtoupper($this->permission);

        if (defined($permConstant) && function_exists('hasPermission')) {
            if (!hasPermission(constant($permConstant))) {
                $_SESSION['error'] = 'You do not have permission to perform this action.';

                // For AJAX requests, return JSON
                if ($this->isAjaxRequest()) {
                    http_response_code(403);
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => 'Permission denied']);
                    exit;
                }

                header('Location: ' . Router::url('home'));
                exit;
            }
        }

        return true;
    }

    /**
     * Check if this is an AJAX request
     */
    private function isAjaxRequest(): bool {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}
