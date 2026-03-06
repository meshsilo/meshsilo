<?php

/**
 * Configuration Validator
 *
 * Validates application configuration at startup.
 * Run early in the application lifecycle to catch misconfigurations.
 */

class ConfigValidator
{
    private array $errors = [];
    private array $warnings = [];

    /**
     * Run all validation checks
     */
    public function validate(): bool
    {
        $this->errors = [];
        $this->warnings = [];

        $this->checkRequiredConstants();
        $this->checkDirectoryPermissions();
        $this->checkDatabaseConnection();
        $this->checkSessionConfiguration();
        $this->checkSecuritySettings();
        $this->checkFeatureDependencies();

        return empty($this->errors);
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get validation warnings
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Check required constants are defined
     */
    private function checkRequiredConstants(): void
    {
        $required = ['SITE_URL', 'STORAGE_PATH'];

        foreach ($required as $const) {
            if (!defined($const)) {
                $this->errors[] = "Required constant {$const} is not defined.";
            }
        }

        // Check SITE_URL format
        if (defined('SITE_URL')) {
            $url = SITE_URL;
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $this->errors[] = "SITE_URL '{$url}' is not a valid URL.";
            } elseif (strpos($url, 'http://') === 0 && strpos($url, 'localhost') === false) {
                $this->warnings[] = "SITE_URL uses HTTP instead of HTTPS. Consider using HTTPS for production.";
            }
        }
    }

    /**
     * Check directory permissions
     */
    private function checkDirectoryPermissions(): void
    {
        if (!defined('STORAGE_PATH')) {
            return;
        }

        $storagePath = STORAGE_PATH;

        // Check storage directory exists and is writable
        if (!is_dir($storagePath)) {
            $this->errors[] = "Storage directory does not exist: {$storagePath}";
            return;
        }

        if (!is_writable($storagePath)) {
            $this->errors[] = "Storage directory is not writable: {$storagePath}";
        }

        // Check subdirectories
        $subdirs = ['assets', 'logs', 'cache', 'db'];
        foreach ($subdirs as $subdir) {
            $path = $storagePath . '/' . $subdir;
            if (is_dir($path) && !is_writable($path)) {
                $this->warnings[] = "Storage subdirectory is not writable: {$path}";
            }
        }
    }

    /**
     * Check database connection
     */
    private function checkDatabaseConnection(): void
    {
        try {
            if (function_exists('getDB')) {
                $db = getDB();
                // Simple query to test connection
                $db->querySingle("SELECT 1");
            }
        } catch (Exception $e) {
            $this->errors[] = "Database connection failed: " . $e->getMessage();
        }
    }

    /**
     * Check session configuration
     */
    private function checkSessionConfiguration(): void
    {
        if (session_status() === PHP_SESSION_DISABLED) {
            $this->errors[] = "PHP sessions are disabled. Sessions are required for authentication.";
            return;
        }

        // Check session save path is writable
        $savePath = session_save_path();
        if (!empty($savePath) && !is_writable($savePath)) {
            $this->warnings[] = "Session save path is not writable: {$savePath}";
        }
    }

    /**
     * Check security settings
     */
    private function checkSecuritySettings(): void
    {
        // Check for development/debug mode in production
        if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
            $this->warnings[] = "DEBUG_MODE is enabled. Disable in production.";
        }

        // Check for secure cookie settings
        if (
            ini_get('session.cookie_secure') !== '1' &&
            defined('SITE_URL') && strpos(SITE_URL, 'https://') === 0
        ) {
            $this->warnings[] = "session.cookie_secure is not enabled for HTTPS site.";
        }

        // Check for httponly cookies
        if (ini_get('session.cookie_httponly') !== '1') {
            $this->warnings[] = "session.cookie_httponly is not enabled.";
        }
    }

    /**
     * Check feature dependencies
     */
    private function checkFeatureDependencies(): void
    {
        if (!function_exists('isFeatureEnabled') || !function_exists('areFeatureDependenciesMet')) {
            return;
        }

        if (!function_exists('getAvailableFeatures')) {
            return;
        }

        $features = getAvailableFeatures();
        foreach ($features as $key => $meta) {
            if (isFeatureEnabled($key) && !areFeatureDependenciesMet($key)) {
                $missing = getMissingDependencies($key);
                $this->warnings[] = "Feature '{$meta['name']}' is enabled but requires disabled features: " . implode(', ', $missing);
            }
        }
    }

    /**
     * Run validation and log results
     */
    public static function validateAndLog(): bool
    {
        $validator = new self();
        $valid = $validator->validate();

        foreach ($validator->getErrors() as $error) {
            if (function_exists('logError')) {
                logError('Configuration error: ' . $error);
            }
        }

        foreach ($validator->getWarnings() as $warning) {
            if (function_exists('logWarning')) {
                logWarning('Configuration warning: ' . $warning);
            }
        }

        return $valid;
    }

    /**
     * Get a summary of validation results
     */
    public function getSummary(): array
    {
        return [
            'valid' => empty($this->errors),
            'error_count' => count($this->errors),
            'warning_count' => count($this->warnings),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
