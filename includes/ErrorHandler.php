<?php
/**
 * Error Handler
 *
 * Provides friendly error pages and error tracking.
 * Displays detailed errors in development, friendly pages in production.
 */

class ErrorHandler {
    private static bool $debug = false;
    private static ?string $logPath = null;
    private static bool $registered = false;

    /**
     * Initialize error handler
     */
    public static function init(bool $debug = false): void {
        self::$debug = $debug;
        self::$logPath = dirname(__DIR__) . '/logs/';

        if (!self::$registered) {
            set_error_handler([self::class, 'handleError']);
            set_exception_handler([self::class, 'handleException']);
            register_shutdown_function([self::class, 'handleShutdown']);
            self::$registered = true;
        }
    }

    /**
     * Set debug mode
     */
    public static function setDebug(bool $debug): void {
        self::$debug = $debug;
    }

    /**
     * Handle PHP errors
     */
    public static function handleError(int $severity, string $message, string $file, int $line): bool {
        // Convert to ErrorException for consistent handling
        if (error_reporting() & $severity) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }
        return false;
    }

    /**
     * Handle uncaught exceptions
     */
    public static function handleException(\Throwable $e): void {
        // Log the error
        self::logError($e);

        // Determine response type
        $isApi = self::isApiRequest();

        // Get appropriate status code
        $code = self::getStatusCode($e);
        http_response_code($code);

        if ($isApi) {
            self::renderJsonError($e, $code);
        } else {
            self::renderHtmlError($e, $code);
        }

        exit;
    }

    /**
     * Handle fatal errors on shutdown
     */
    public static function handleShutdown(): void {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $e = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
            self::handleException($e);
        }
    }

    /**
     * Get HTTP status code for exception
     */
    private static function getStatusCode(\Throwable $e): int {
        $code = $e->getCode();

        // Map exception types to HTTP codes
        if ($e instanceof \InvalidArgumentException) {
            return 400;
        }
        if ($e instanceof CsrfException) {
            return 403;
        }
        if ($e instanceof ValidationException) {
            return 422;
        }

        // Use exception code if it's a valid HTTP code
        if ($code >= 400 && $code < 600) {
            return $code;
        }

        return 500;
    }

    /**
     * Check if request is an API request
     */
    private static function isApiRequest(): bool {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        return strpos($uri, '/api/') !== false ||
               strpos($accept, 'application/json') !== false ||
               !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    /**
     * Log error to file
     */
    private static function logError(\Throwable $e): void {
        if (function_exists('logException')) {
            logException($e);
            return;
        }

        // Fallback logging
        $logFile = self::$logPath . 'error.log';
        $message = sprintf(
            "[%s] %s: %s in %s:%d\nStack trace:\n%s\n",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );

        @file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
    }

    /**
     * Render JSON error response
     */
    private static function renderJsonError(\Throwable $e, int $code): void {
        header('Content-Type: application/json');

        $response = [
            'error' => true,
            'message' => self::$debug ? $e->getMessage() : self::getFriendlyMessage($code),
            'code' => $code
        ];

        if (self::$debug) {
            $response['debug'] = [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString())
            ];
        }

        if ($e instanceof ValidationException) {
            $response['errors'] = $e->errors();
        }

        echo json_encode($response, JSON_PRETTY_PRINT);
    }

    /**
     * Render HTML error page
     */
    private static function renderHtmlError(\Throwable $e, int $code): void {
        $title = self::getErrorTitle($code);
        $message = self::$debug ? $e->getMessage() : self::getFriendlyMessage($code);
        $showDetails = self::$debug;

        // Check for custom error page
        $customPage = dirname(__DIR__) . "/pages/errors/{$code}.php";
        if (file_exists($customPage)) {
            include $customPage;
            return;
        }

        // Default error page
        self::renderDefaultErrorPage($e, $code, $title, $message, $showDetails);
    }

    /**
     * Render default error page
     */
    private static function renderDefaultErrorPage(\Throwable $e, int $code, string $title, string $message, bool $showDetails): void {
        $siteName = defined('SITE_NAME') ? SITE_NAME : 'Silo';
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> - <?= htmlspecialchars($siteName) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #e8e8e8;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .error-container {
            max-width: 600px;
            width: 100%;
            text-align: center;
        }
        .error-code {
            font-size: 8rem;
            font-weight: bold;
            color: #e74c3c;
            line-height: 1;
            text-shadow: 0 4px 20px rgba(231, 76, 60, 0.3);
        }
        .error-title {
            font-size: 1.5rem;
            margin: 1rem 0;
            color: #fff;
        }
        .error-message {
            color: #a0a0a0;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        .error-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .btn-primary {
            background: #3498db;
            color: white;
        }
        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }
        .btn-secondary {
            background: rgba(255,255,255,0.1);
            color: #e8e8e8;
        }
        .btn-secondary:hover {
            background: rgba(255,255,255,0.2);
        }
        .error-details {
            margin-top: 2rem;
            text-align: left;
            background: rgba(0,0,0,0.3);
            padding: 1rem;
            border-radius: 8px;
            overflow-x: auto;
        }
        .error-details h4 {
            color: #e74c3c;
            margin-bottom: 0.5rem;
        }
        .error-details pre {
            font-family: 'Fira Code', monospace;
            font-size: 0.85rem;
            color: #a0a0a0;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .error-details .file-info {
            color: #f39c12;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-code"><?= $code ?></div>
        <h1 class="error-title"><?= htmlspecialchars($title) ?></h1>
        <p class="error-message"><?= htmlspecialchars($message) ?></p>

        <div class="error-actions">
            <a href="/" class="btn btn-primary">Go Home</a>
            <a href="javascript:history.back()" class="btn btn-secondary">Go Back</a>
        </div>

        <?php if ($showDetails): ?>
        <div class="error-details">
            <h4><?= htmlspecialchars(get_class($e)) ?></h4>
            <p class="file-info"><?= htmlspecialchars($e->getFile()) ?>:<?= $e->getLine() ?></p>
            <pre><?= htmlspecialchars($e->getTraceAsString()) ?></pre>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
        <?php
    }

    /**
     * Get error title for status code
     */
    private static function getErrorTitle(int $code): string {
        $titles = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Page Not Found',
            405 => 'Method Not Allowed',
            408 => 'Request Timeout',
            422 => 'Validation Error',
            429 => 'Too Many Requests',
            500 => 'Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
        ];

        return $titles[$code] ?? 'Error';
    }

    /**
     * Get friendly message for status code
     */
    private static function getFriendlyMessage(int $code): string {
        $messages = [
            400 => 'The request could not be understood. Please check your input and try again.',
            401 => 'You need to log in to access this page.',
            403 => 'You don\'t have permission to access this resource.',
            404 => 'The page you\'re looking for doesn\'t exist or has been moved.',
            405 => 'This action is not allowed.',
            422 => 'The submitted data was invalid. Please check your input.',
            429 => 'You\'ve made too many requests. Please wait a moment and try again.',
            500 => 'Something went wrong on our end. We\'ve been notified and are working on it.',
            502 => 'We\'re having trouble connecting to our servers. Please try again.',
            503 => 'We\'re temporarily unavailable for maintenance. Please check back soon.',
        ];

        return $messages[$code] ?? 'An unexpected error occurred. Please try again later.';
    }

    /**
     * Abort with error
     */
    public static function abort(int $code, ?string $message = null): void {
        throw new \Exception($message ?? self::getFriendlyMessage($code), $code);
    }

    /**
     * Abort if condition is true
     */
    public static function abortIf(bool $condition, int $code, ?string $message = null): void {
        if ($condition) {
            self::abort($code, $message);
        }
    }

    /**
     * Abort unless condition is true
     */
    public static function abortUnless(bool $condition, int $code, ?string $message = null): void {
        if (!$condition) {
            self::abort($code, $message);
        }
    }
}

// ========================================
// Helper Functions
// ========================================

/**
 * Abort with error code
 */
function abort(int $code, ?string $message = null): void {
    ErrorHandler::abort($code, $message);
}

/**
 * Abort if condition
 */
function abort_if(bool $condition, int $code, ?string $message = null): void {
    ErrorHandler::abortIf($condition, $code, $message);
}

/**
 * Abort unless condition
 */
function abort_unless(bool $condition, int $code, ?string $message = null): void {
    ErrorHandler::abortUnless($condition, $code, $message);
}

/**
 * Setup error handler
 */
function setupErrorHandler(bool $debug = false): void {
    ErrorHandler::init($debug);
}
