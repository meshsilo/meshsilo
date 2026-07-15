<?php

/**
 * PluginAdminActions
 *
 * Handles the POST actions for the admin plugin management page
 * (app/admin/plugins.php). Extracted verbatim from the page controller so
 * the page can stay focused on the admin gate, CSRF, PRG redirect, and HTML
 * rendering while the action logic (enable/disable/uninstall/save-settings/
 * install-upload/install-repo/run-migrations/update-plugin/reinstall/add-repo/
 * remove-repo/refresh-repos/check-updates) lives here.
 *
 * Behavior is identical to the previous inline switch: the same PluginManager
 * methods are called, the same success/error messages are produced, and the
 * same logInfo() audit entries are written.
 */

class PluginAdminActions
{
    private PluginManager $pluginManager;

    public function __construct(PluginManager $pluginManager)
    {
        $this->pluginManager = $pluginManager;
    }

    /**
     * Dispatch a POST action.
     *
     * @param string $action The action name (from $_POST['action']).
     * @param array  $post   The POST payload (pass $_POST).
     * @param array  $files  Uploaded files (pass $_FILES); needed for install-upload.
     *
     * @return array{message: string, error: string} Result messages for the page to apply.
     */
    public function handle(string $action, array $post, array $files = []): array
    {
        $message = '';
        $error = '';
        $pluginManager = $this->pluginManager;

        switch ($action) {
            case 'enable':
                $pluginId = preg_replace('/[^a-z0-9\-]/', '', strtolower($post['plugin_id'] ?? ''));
                $result = $pluginId !== ''
                    ? $pluginManager->enablePlugin($pluginId)
                    : ['success' => false, 'error' => 'Invalid plugin ID'];
                if ($result['success']) {
                    $message = 'Plugin enabled successfully.';
                    logInfo('Plugin enabled', ['plugin' => $pluginId, 'by' => getCurrentUser()['username']]);
                } else {
                    $error = 'Failed to enable plugin: ' . ($result['error'] ?? 'Unknown error');
                }
                break;

            case 'disable':
                $pluginId = preg_replace('/[^a-z0-9\-]/', '', strtolower($post['plugin_id'] ?? ''));
                $result = $pluginId !== ''
                    ? $pluginManager->disablePlugin($pluginId)
                    : ['success' => false, 'error' => 'Invalid plugin ID'];
                if ($result['success']) {
                    $message = 'Plugin disabled successfully.';
                    logInfo('Plugin disabled', ['plugin' => $pluginId, 'by' => getCurrentUser()['username']]);
                } else {
                    $error = 'Failed to disable plugin: ' . ($result['error'] ?? 'Unknown error');
                }
                break;

            case 'uninstall':
                $pluginId = preg_replace('/[^a-z0-9\-]/', '', strtolower($post['plugin_id'] ?? ''));
                if ($pluginId !== '' && $pluginManager->uninstallPlugin($pluginId)) {
                    $message = 'Plugin uninstalled successfully.';
                    logInfo('Plugin uninstalled', ['plugin' => $pluginId, 'by' => getCurrentUser()['username']]);
                } else {
                    $error = 'Failed to uninstall plugin.';
                }
                break;

            case 'save-settings':
                $pluginId = preg_replace('/[^a-z0-9\-]/', '', strtolower($post['plugin_id'] ?? ''));
                $pluginSettings = $post['plugin_settings'] ?? [];
                if ($pluginId !== '' && $pluginManager->savePluginSettings($pluginId, $pluginSettings)) {
                    $message = 'Plugin settings saved successfully.';
                } else {
                    $error = 'Failed to save plugin settings.';
                }
                break;

            case 'install-upload':
                if (isset($files['plugin_zip']) && $files['plugin_zip']['error'] === UPLOAD_ERR_OK) {
                    $tmpPath = $files['plugin_zip']['tmp_name'];
                    $result = $pluginManager->installPlugin($tmpPath);
                    if ($result['success']) {
                        $pluginName = htmlspecialchars($result['plugin']['name'] ?? 'Unknown');
                        $message = "Plugin \"$pluginName\" installed successfully.";
                        if (!empty($result['warning'])) {
                            $message .= ' Warning: ' . htmlspecialchars($result['warning']);
                        }
                        logInfo('Plugin installed from upload', ['plugin' => $result['plugin']['id'] ?? 'unknown', 'by' => getCurrentUser()['username']]);
                    } else {
                        $error = 'Installation failed: ' . ($result['error'] ?? 'Unknown error');
                    }
                } else {
                    $uploadError = $files['plugin_zip']['error'] ?? UPLOAD_ERR_NO_FILE;
                    $error = match ($uploadError) {
                        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the maximum file size.',
                        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
                        default => 'File upload failed. Please try again.',
                    };
                }
                break;

            case 'install-repo':
                $pluginId = preg_replace('/[^a-z0-9\-]/', '', strtolower($post['plugin_id'] ?? ''));
                $source = json_decode($post['plugin_source'] ?? '{}', true);
                if ($pluginId !== '' && is_array($source) && !empty($source['repo'])) {
                    $result = $pluginManager->installFromRepo($pluginId, $source);
                    if ($result['success']) {
                        $message = 'Plugin installed from repository successfully.';
                        if (!empty($result['warning'])) {
                            $message .= ' Warning: ' . htmlspecialchars($result['warning']);
                        }
                        logInfo('Plugin installed from repo', ['plugin' => $pluginId, 'by' => getCurrentUser()['username']]);
                    } else {
                        $error = 'Installation failed: ' . ($result['error'] ?? 'Unknown error');
                    }
                } else {
                    $error = 'Invalid plugin ID or source configuration.';
                }
                break;

            case 'run-migrations':
                $pluginId = preg_replace('/[^a-z0-9\-]/', '', strtolower($post['plugin_id'] ?? ''));
                if ($pluginId !== '') {
                    $stats = $pluginManager->runPluginMigrations($pluginId);
                    $message = "Migrations complete: {$stats['run']} applied, {$stats['skipped']} already up to date.";
                    logInfo('Plugin migrations run', ['plugin' => $pluginId, 'stats' => $stats, 'by' => getCurrentUser()['username']]);
                } else {
                    $error = 'Invalid plugin ID.';
                }
                break;

            case 'update-plugin':
            case 'reinstall':
                $pluginId = preg_replace('/[^a-z0-9\-]/', '', strtolower($post['plugin_id'] ?? ''));
                $source = json_decode($post['plugin_source'] ?? '{}', true);
                $isReinstall = $action === 'reinstall';
                if ($pluginId !== '' && is_array($source) && !empty($source['repo'])) {
                    $wasActive = $pluginManager->isPluginActive($pluginId);
                    $result = $pluginManager->installFromRepo($pluginId, $source);
                    if ($result['success']) {
                        if ($wasActive) {
                            $pluginManager->enablePlugin($pluginId);
                        }
                        $message = $isReinstall ? 'Plugin reinstalled successfully.' : 'Plugin updated successfully.';
                        logInfo($isReinstall ? 'Plugin reinstalled' : 'Plugin updated', ['plugin' => $pluginId, 'by' => getCurrentUser()['username']]);
                    } else {
                        $error = ($isReinstall ? 'Reinstall' : 'Update') . ' failed: ' . ($result['error'] ?? 'Unknown error');
                    }
                } else {
                    $error = 'Invalid plugin ID or source configuration.';
                }
                break;

            case 'add-repo':
                $repoName = trim($post['repo_name'] ?? '');
                $repoUrl = trim($post['repo_url'] ?? '');
                if ($repoName === '' || $repoUrl === '') {
                    $error = 'Repository name and URL are required.';
                } elseif ($pluginManager->addRepository($repoName, $repoUrl)) {
                    $message = 'Repository added successfully.';
                    logInfo('Plugin repository added', ['name' => $repoName, 'url' => $repoUrl, 'by' => getCurrentUser()['username']]);
                } else {
                    $error = 'Failed to add repository. Please check the URL is valid.';
                }
                break;

            case 'remove-repo':
                $repoId = (int)($post['repo_id'] ?? 0);
                if ($repoId > 0 && $pluginManager->removeRepository($repoId)) {
                    $message = 'Repository removed successfully.';
                    logInfo('Plugin repository removed', ['repo_id' => $repoId, 'by' => getCurrentUser()['username']]);
                } else {
                    $error = 'Failed to remove repository.';
                }
                break;

            case 'refresh-repos':
                $repos = $pluginManager->getRepositories();
                $results = $pluginManager->fetchRegistries(array_column($repos, 'url'));
                $refreshed = count(array_filter($results, fn($r) => $r !== null));
                $message = "Refreshed $refreshed of " . count($repos) . " repositories.";
                logInfo('Plugin repositories refreshed', ['refreshed' => $refreshed, 'total' => count($repos), 'by' => getCurrentUser()['username']]);
                break;

            case 'check-updates':
                // Handled by the page after redirect check since we need to display results
                break;
        }

        return ['message' => $message, 'error' => $error];
    }
}
