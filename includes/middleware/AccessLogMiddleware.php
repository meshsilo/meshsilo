<?php
/**
 * Access Log Middleware
 *
 * Logs all HTTP requests and responses to the access log channel.
 * This middleware should be registered first in the middleware chain
 * to capture accurate timing information.
 */

require_once __DIR__ . '/MiddlewareInterface.php';
require_once __DIR__ . '/../logger.php';

class AccessLogMiddleware implements MiddlewareInterface {
    private $skipPaths = [];
    private $skipExtensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot'];

    public function __construct($options = []) {
        if (isset($options['skip_paths'])) {
            $this->skipPaths = $options['skip_paths'];
        }
        if (isset($options['skip_extensions'])) {
            $this->skipExtensions = $options['skip_extensions'];
        }
    }

    public function handle(array $params): bool {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        // Skip static assets and specified paths
        if ($this->shouldSkip($path)) {
            return true;
        }

        // Log the request
        logRequest();

        // Register shutdown function to log response
        register_shutdown_function([$this, 'logShutdown']);

        return true;
    }

    private function shouldSkip($path) {
        // Check skip paths
        foreach ($this->skipPaths as $skipPath) {
            if (strpos($path, $skipPath) === 0) {
                return true;
            }
        }

        // Check file extensions
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, $this->skipExtensions)) {
            return true;
        }

        return false;
    }

    public function logShutdown() {
        // Get the actual response code
        $statusCode = http_response_code();

        // Check for errors
        $error = error_get_last();
        $context = [];

        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $context['error'] = [
                'type' => $error['type'],
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
            ];
            // If no status code set, it's likely a 500
            if ($statusCode === 200) {
                $statusCode = 500;
            }
        }

        // Log the response
        logResponse($statusCode, $context);
    }
}
