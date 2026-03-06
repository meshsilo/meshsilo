<?php
/**
 * Maintenance Mode Middleware
 *
 * Displays a maintenance page when the site is in maintenance mode.
 * Admins can bypass maintenance mode if configured.
 */

require_once __DIR__ . '/MiddlewareInterface.php';

class MaintenanceMiddleware implements MiddlewareInterface {
    /**
     * Handle the middleware
     */
    public function handle(array $params): bool {
        // Check if maintenance mode is enabled
        if (!$this->isMaintenanceMode()) {
            return true;
        }

        // Allow admins to bypass if configured
        if ($this->canBypass()) {
            return true;
        }

        // Allow specific routes (login, logout, admin) to work during maintenance
        $allowedRoutes = ['login', 'login.post', 'logout'];
        $currentRoute = $_SERVER['ROUTE_NAME'] ?? '';

        if (in_array($currentRoute, $allowedRoutes)) {
            return true;
        }

        // Also allow admin routes for logged-in admins
        if (strpos($currentRoute, 'admin.') === 0 && function_exists('isAdmin') && isAdmin()) {
            return true;
        }

        // Show maintenance page
        $this->showMaintenancePage();
        return false;
    }

    /**
     * Check if maintenance mode is enabled
     */
    private function isMaintenanceMode(): bool {
        // Check for maintenance file (quick toggle)
        if (file_exists(__DIR__ . '/../../.maintenance')) {
            return true;
        }

        // Check database setting
        if (function_exists('getSetting')) {
            return getSetting('maintenance_mode', '0') === '1';
        }

        return false;
    }

    /**
     * Check if current user can bypass maintenance mode
     */
    private function canBypass(): bool {
        // Check for bypass cookie (set by admin)
        $bypassSecret = $this->getBypassSecret();
        if ($bypassSecret && isset($_COOKIE['maintenance_bypass'])) {
            if (hash_equals($bypassSecret, $_COOKIE['maintenance_bypass'])) {
                return true;
            }
        }

        // Check if user is admin
        if (function_exists('isAdmin') && isAdmin()) {
            $adminBypass = function_exists('getSetting')
                ? getSetting('maintenance_admin_bypass', '1')
                : '1';
            return $adminBypass === '1';
        }

        // Check IP whitelist
        $whitelistedIps = $this->getWhitelistedIps();
        if (!empty($whitelistedIps)) {
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            if (in_array($clientIp, $whitelistedIps)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get bypass secret from settings
     */
    private function getBypassSecret(): ?string {
        if (function_exists('getSetting')) {
            $secret = getSetting('maintenance_bypass_secret', '');
            return !empty($secret) ? $secret : null;
        }
        return null;
    }

    /**
     * Get whitelisted IPs
     */
    private function getWhitelistedIps(): array {
        if (function_exists('getSetting')) {
            $ips = getSetting('maintenance_whitelist_ips', '');
            if (!empty($ips)) {
                return array_map('trim', explode(',', $ips));
            }
        }
        return [];
    }

    /**
     * Display the maintenance page
     */
    private function showMaintenancePage(): void {
        http_response_code(503);
        header('Retry-After: 3600'); // Suggest retry in 1 hour

        // Get custom message if set
        $message = '';
        $title = 'Maintenance Mode';
        if (function_exists('getSetting')) {
            $message = getSetting('maintenance_message', '');
            $title = getSetting('maintenance_title', 'Maintenance Mode');
        }

        if (empty($message)) {
            $message = 'We are currently performing scheduled maintenance. Please check back soon.';
        }

        // Check if custom maintenance page exists
        $customPage = __DIR__ . '/../../maintenance.php';
        if (file_exists($customPage)) {
            require $customPage;
            exit;
        }

        // Default maintenance page
        $siteName = defined('SITE_NAME') ? SITE_NAME : 'Silo';
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> - <?= htmlspecialchars($siteName) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #fff;
            padding: 2rem;
        }
        .maintenance-container {
            text-align: center;
            max-width: 600px;
        }
        .icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        h1 {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: #fff;
        }
        p {
            font-size: 1.1rem;
            line-height: 1.6;
            color: rgba(255,255,255,0.8);
            margin-bottom: 2rem;
        }
        .status {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: rgba(255,255,255,0.1);
            border-radius: 2rem;
            font-size: 0.875rem;
            color: rgba(255,255,255,0.7);
        }
    </style>
</head>
<body>
    <div class="maintenance-container">
        <div class="icon">&#9881;</div>
        <h1><?= htmlspecialchars($title) ?></h1>
        <p><?= nl2br(htmlspecialchars($message)) ?></p>
        <div class="status">We'll be back shortly</div>
    </div>
</body>
</html>
        <?php
        exit;
    }
}

/**
 * Helper function to enable maintenance mode programmatically
 */
function enableMaintenanceMode(string $message = '', int $duration = 0): void {
    $maintenanceFile = __DIR__ . '/../../.maintenance';
    $data = [
        'enabled_at' => time(),
        'message' => $message,
        'expires_at' => $duration > 0 ? time() + $duration : null
    ];
    file_put_contents($maintenanceFile, json_encode($data));
}

/**
 * Helper function to disable maintenance mode
 */
function disableMaintenanceMode(): void {
    $maintenanceFile = __DIR__ . '/../../.maintenance';
    if (file_exists($maintenanceFile)) {
        unlink($maintenanceFile);
    }
}

/**
 * Check if site is in maintenance mode
 */
function isMaintenanceMode(): bool {
    if (file_exists(__DIR__ . '/../../.maintenance')) {
        return true;
    }
    if (function_exists('getSetting')) {
        return getSetting('maintenance_mode', '0') === '1';
    }
    return false;
}
