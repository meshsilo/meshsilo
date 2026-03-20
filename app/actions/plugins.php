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
    jsonError('Permission denied', 403);
}

// CSRF check for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    jsonError('Invalid CSRF token', 403);
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
            jsonError('Plugin ID is required', 400);
        }

        $result = $pluginManager->enablePlugin($pluginId);

        if ($result) {
            logInfo('Plugin enabled', [
                'plugin_id' => $pluginId,
                'by' => getCurrentUser()['username']
            ]);
            jsonSuccess(['message' => 'Plugin enabled']);
        } else {
            http_response_code(400);
            jsonError('Failed to enable plugin');
        }
        break;

    case 'disable':
        $pluginId = sanitizePluginId($_POST['plugin_id'] ?? '');

        if (empty($pluginId)) {
            jsonError('Plugin ID is required', 400);
        }

        $result = $pluginManager->disablePlugin($pluginId);

        if ($result) {
            logInfo('Plugin disabled', [
                'plugin_id' => $pluginId,
                'by' => getCurrentUser()['username']
            ]);
            jsonSuccess(['message' => 'Plugin disabled']);
        } else {
            http_response_code(400);
            jsonError('Failed to disable plugin');
        }
        break;

    case 'uninstall':
        $pluginId = sanitizePluginId($_POST['plugin_id'] ?? '');

        if (empty($pluginId)) {
            jsonError('Plugin ID is required', 400);
        }

        $result = $pluginManager->uninstallPlugin($pluginId);

        if ($result) {
            logInfo('Plugin uninstalled', [
                'plugin_id' => $pluginId,
                'by' => getCurrentUser()['username']
            ]);
            jsonSuccess(['message' => 'Plugin uninstalled']);
        } else {
            http_response_code(400);
            jsonError('Failed to uninstall plugin');
        }
        break;

    case 'install-upload':
        if (!isset($_FILES['plugin_zip']) || $_FILES['plugin_zip']['error'] !== UPLOAD_ERR_OK) {
            jsonError('No valid file uploaded', 400);
        }

        $fileName = $_FILES['plugin_zip']['name'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($ext !== 'zip') {
            jsonError('Only .zip files are allowed', 400);
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
            jsonError('Plugin ID is required', 400);
        }

        if (!is_array($source) || empty($source['repo'])) {
            jsonError('Valid source configuration is required', 400);
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
            jsonError('Plugin ID is required', 400);
        }

        try {
            $stats = $pluginManager->runPluginMigrations($pluginId);
            logInfo('Plugin migrations executed', [
                'plugin_id' => $pluginId,
                'stats' => $stats,
                'by' => getCurrentUser()['username']
            ]);
            jsonSuccess(['stats' => $stats]);
        } catch (Throwable $e) {
            logException($e, ['action' => 'run-migrations', 'plugin_id' => $pluginId]);
            http_response_code(500);
            jsonError('Migration failed: ' . $e->getMessage());
        }
        break;

    case 'add-repo':
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['url'] ?? '');

        if (empty($name)) {
            jsonError('Repository name is required', 400);
        }

        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            jsonError('A valid repository URL is required', 400);
        }

        $result = $pluginManager->addRepository($name, $url);

        if ($result) {
            logInfo('Plugin repository added', [
                'name' => $name,
                'url' => $url,
                'by' => getCurrentUser()['username']
            ]);
            jsonSuccess(['message' => 'Repository added']);
        } else {
            http_response_code(400);
            jsonError('Failed to add repository');
        }
        break;

    case 'remove-repo':
        $repoId = $_POST['repo_id'] ?? '';

        if (empty($repoId) || !is_numeric($repoId)) {
            jsonError('A valid numeric repository ID is required', 400);
        }

        $result = $pluginManager->removeRepository((int)$repoId);

        if ($result) {
            logInfo('Plugin repository removed', [
                'repo_id' => (int)$repoId,
                'by' => getCurrentUser()['username']
            ]);
            jsonSuccess(['message' => 'Repository removed']);
        } else {
            http_response_code(400);
            jsonError('Failed to remove repository');
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

        jsonSuccess(['updates' => $updates]);
        break;

    default:
        http_response_code(400);
        jsonError('Unknown action');
        break;
}
