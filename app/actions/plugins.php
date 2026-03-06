<?php
/**
 * Plugin Management Actions
 *
 * Handles AJAX requests for plugin operations
 */

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

// Require admin permission
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// CSRF check for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action = $_POST['action'] ?? '';
$pluginManager = PluginManager::getInstance();

/**
 * Sanitize a plugin ID to contain only lowercase alphanumeric characters and hyphens.
 */
function sanitizePluginId(string $id): string
{
    return preg_replace('/[^a-z0-9\-]/', '', strtolower($id));
}

switch ($action) {
    case 'enable':
        $pluginId = sanitizePluginId($_POST['plugin_id'] ?? '');

        if (empty($pluginId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Plugin ID is required']);
            exit;
        }

        $result = $pluginManager->enablePlugin($pluginId);

        if ($result) {
            logInfo('Plugin enabled', [
                'plugin_id' => $pluginId,
                'by' => getCurrentUser()['username']
            ]);
            echo json_encode(['success' => true, 'message' => 'Plugin enabled']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Failed to enable plugin']);
        }
        break;

    case 'disable':
        $pluginId = sanitizePluginId($_POST['plugin_id'] ?? '');

        if (empty($pluginId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Plugin ID is required']);
            exit;
        }

        $result = $pluginManager->disablePlugin($pluginId);

        if ($result) {
            logInfo('Plugin disabled', [
                'plugin_id' => $pluginId,
                'by' => getCurrentUser()['username']
            ]);
            echo json_encode(['success' => true, 'message' => 'Plugin disabled']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Failed to disable plugin']);
        }
        break;

    case 'uninstall':
        $pluginId = sanitizePluginId($_POST['plugin_id'] ?? '');

        if (empty($pluginId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Plugin ID is required']);
            exit;
        }

        $result = $pluginManager->uninstallPlugin($pluginId);

        if ($result) {
            logInfo('Plugin uninstalled', [
                'plugin_id' => $pluginId,
                'by' => getCurrentUser()['username']
            ]);
            echo json_encode(['success' => true, 'message' => 'Plugin uninstalled']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Failed to uninstall plugin']);
        }
        break;

    case 'install-upload':
        if (!isset($_FILES['plugin_zip']) || $_FILES['plugin_zip']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No valid file uploaded']);
            exit;
        }

        $fileName = $_FILES['plugin_zip']['name'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($ext !== 'zip') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Only .zip files are allowed']);
            exit;
        }

        $result = $pluginManager->installPlugin($_FILES['plugin_zip']['tmp_name']);

        if ($result['success']) {
            logInfo('Plugin installed via upload', [
                'plugin' => $result['plugin']['id'] ?? $fileName,
                'by' => getCurrentUser()['username']
            ]);
        }

        echo json_encode($result);
        break;

    case 'install-repo':
        $pluginId = sanitizePluginId($_POST['plugin_id'] ?? '');
        $source = json_decode($_POST['plugin_source'] ?? '{}', true);

        if (empty($pluginId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Plugin ID is required']);
            exit;
        }

        if (!is_array($source) || empty($source['repo'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid source configuration is required']);
            exit;
        }

        $result = $pluginManager->installFromRepo($pluginId, $source);

        if ($result['success']) {
            logInfo('Plugin installed from repository', [
                'plugin_id' => $pluginId,
                'source' => $source['repo'] ?? '',
                'by' => getCurrentUser()['username']
            ]);
        }

        echo json_encode($result);
        break;

    case 'run-migrations':
        $pluginId = sanitizePluginId($_POST['plugin_id'] ?? '');

        if (empty($pluginId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Plugin ID is required']);
            exit;
        }

        try {
            $stats = $pluginManager->runPluginMigrations($pluginId);
            logInfo('Plugin migrations executed', [
                'plugin_id' => $pluginId,
                'stats' => $stats,
                'by' => getCurrentUser()['username']
            ]);
            echo json_encode(['success' => true, 'stats' => $stats]);
        } catch (Throwable $e) {
            logException($e, ['action' => 'run-migrations', 'plugin_id' => $pluginId]);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Migration failed: ' . $e->getMessage()]);
        }
        break;

    case 'add-repo':
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['url'] ?? '');

        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Repository name is required']);
            exit;
        }

        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'A valid repository URL is required']);
            exit;
        }

        $result = $pluginManager->addRepository($name, $url);

        if ($result) {
            logInfo('Plugin repository added', [
                'name' => $name,
                'url' => $url,
                'by' => getCurrentUser()['username']
            ]);
            echo json_encode(['success' => true, 'message' => 'Repository added']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Failed to add repository']);
        }
        break;

    case 'remove-repo':
        $repoId = $_POST['repo_id'] ?? '';

        if (empty($repoId) || !is_numeric($repoId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'A valid numeric repository ID is required']);
            exit;
        }

        $result = $pluginManager->removeRepository((int)$repoId);

        if ($result) {
            logInfo('Plugin repository removed', [
                'repo_id' => (int)$repoId,
                'by' => getCurrentUser()['username']
            ]);
            echo json_encode(['success' => true, 'message' => 'Repository removed']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Failed to remove repository']);
        }
        break;

    case 'refresh-repos':
        $repos = $pluginManager->getRepositories();
        $fetched = 0;
        $failed = 0;

        foreach ($repos as $repo) {
            $registry = $pluginManager->fetchRegistry($repo['url']);
            if ($registry !== null) {
                $fetched++;
            } else {
                $failed++;
            }
        }

        logInfo('Plugin repositories refreshed', [
            'fetched' => $fetched,
            'failed' => $failed,
            'by' => getCurrentUser()['username']
        ]);

        echo json_encode([
            'success' => true,
            'message' => "Refreshed {$fetched} repositories" . ($failed > 0 ? " ({$failed} failed)" : ''),
            'fetched' => $fetched
        ]);
        break;

    case 'check-updates':
        $updates = $pluginManager->checkUpdates();

        echo json_encode(['success' => true, 'updates' => $updates]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        break;
}
