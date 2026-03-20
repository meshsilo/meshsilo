<?php
/**
 * Feature Toggle Actions
 *
 * Handles AJAX requests to enable/disable features
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/features.php';

header('Content-Type: application/json');

// Require admin permission
if (!isLoggedIn() || !isAdmin()) {
    jsonError('Permission denied', 403);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF validation for state-changing actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    jsonError('Invalid CSRF token');
}

switch ($action) {
    case 'toggle':
        $feature = $_POST['feature'] ?? '';
        $enabled = filter_var($_POST['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (empty($feature)) {
            jsonError('Feature key is required', 400);
        }

        $features = getAvailableFeatures();
        if (!isset($features[$feature])) {
            jsonError('Invalid feature', 400);
        }

        if ($enabled) {
            enableFeature($feature);
        } else {
            disableFeature($feature);
        }

        logInfo('Feature toggled', [
            'feature' => $feature,
            'enabled' => $enabled,
            'by' => getCurrentUser()['username']
        ]);

        echo json_encode([
            'success' => true,
            'feature' => $feature,
            'enabled' => $enabled,
            'message' => $features[$feature]['name'] . ' ' . ($enabled ? 'enabled' : 'disabled')
        ]);
        break;

    default:
        http_response_code(400);
        jsonError('Invalid action');
        break;
}
