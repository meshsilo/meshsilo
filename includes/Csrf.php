<?php

/**
 * CSRF Protection
 *
 * Provides Cross-Site Request Forgery protection for forms and AJAX requests.
 * Uses per-session tokens with optional per-form tokens for sensitive operations.
 */

class Csrf
{
    const TOKEN_NAME = 'csrf_token';
    const HEADER_NAME = 'X-CSRF-Token';
    const TOKEN_LENGTH = 32;
    const TOKEN_LIFETIME = 3600; // 1 hour for per-request tokens

    /**
     * Get or generate the session CSRF token
     */
    public static function getToken(): string
    {
        self::ensureSession();

        if (empty($_SESSION[self::TOKEN_NAME])) {
            $_SESSION[self::TOKEN_NAME] = self::generateToken();
        }

        return $_SESSION[self::TOKEN_NAME];
    }

    /**
     * Generate a new token (for regeneration after sensitive actions)
     */
    public static function regenerateToken(): string
    {
        self::ensureSession();
        $_SESSION[self::TOKEN_NAME] = self::generateToken();
        return $_SESSION[self::TOKEN_NAME];
    }

    /**
     * Generate a time-limited token for specific forms
     */
    public static function getTimedToken(string $formId = 'default'): string
    {
        self::ensureSession();

        $data = [
            'token' => self::generateToken(),
            'form' => $formId,
            'expires' => time() + self::TOKEN_LIFETIME
        ];

        // Store in session
        if (!isset($_SESSION['csrf_timed_tokens'])) {
            $_SESSION['csrf_timed_tokens'] = [];
        }

        // Clean expired tokens
        $_SESSION['csrf_timed_tokens'] = array_filter(
            $_SESSION['csrf_timed_tokens'],
            fn($t) => $t['expires'] > time()
        );

        // Limit stored tokens
        if (count($_SESSION['csrf_timed_tokens']) > 20) {
            array_shift($_SESSION['csrf_timed_tokens']);
        }

        $_SESSION['csrf_timed_tokens'][$data['token']] = $data;

        return $data['token'];
    }

    /**
     * Validate CSRF token from request
     */
    public static function validate(?string $token = null): bool
    {
        self::ensureSession();

        // Get token from various sources
        if ($token === null) {
            $token = self::getTokenFromRequest();
        }

        if (empty($token)) {
            return false;
        }

        // Check session token
        if (hash_equals($_SESSION[self::TOKEN_NAME] ?? '', $token)) {
            return true;
        }

        // Check timed tokens
        if (isset($_SESSION['csrf_timed_tokens'][$token])) {
            $timedToken = $_SESSION['csrf_timed_tokens'][$token];
            if ($timedToken['expires'] > time()) {
                // Remove used token (one-time use)
                unset($_SESSION['csrf_timed_tokens'][$token]);
                return true;
            }
            unset($_SESSION['csrf_timed_tokens'][$token]);
        }

        return false;
    }

    /**
     * Validate and throw exception on failure
     */
    public static function validateOrFail(?string $token = null): void
    {
        if (!self::validate($token)) {
            if (function_exists('logWarning')) {
                logWarning('CSRF validation failed', [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
                ]);
            }
            throw new CsrfException('CSRF token validation failed');
        }
    }

    /**
     * Get token from request (POST, header, or JSON body)
     */
    public static function getTokenFromRequest(): ?string
    {
        // Check POST data
        if (!empty($_POST[self::TOKEN_NAME])) {
            return $_POST[self::TOKEN_NAME];
        }

        // Check legacy field name for backward compatibility
        if (!empty($_POST['_token'])) {
            return $_POST['_token'];
        }

        // Check header (for AJAX requests)
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        if (!empty($headers[self::HEADER_NAME])) {
            return $headers[self::HEADER_NAME];
        }

        // Check alternate header format
        $headerKey = 'HTTP_' . str_replace('-', '_', strtoupper(self::HEADER_NAME));
        if (!empty($_SERVER[$headerKey])) {
            return $_SERVER[$headerKey];
        }

        // Check JSON body
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $body = file_get_contents('php://input');
            $data = json_decode($body, true);
            if (!empty($data[self::TOKEN_NAME])) {
                return $data[self::TOKEN_NAME];
            }
        }

        // Query string tokens intentionally not supported (leak in logs/referrer)

        return null;
    }

    /**
     * Generate HTML hidden input field
     */
    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars(self::TOKEN_NAME),
            htmlspecialchars(self::getToken())
        );
    }

    /**
     * Generate HTML hidden input field with timed token
     */
    public static function timedField(string $formId = 'default'): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars(self::TOKEN_NAME),
            htmlspecialchars(self::getTimedToken($formId))
        );
    }

    /**
     * Generate meta tag for AJAX requests
     */
    public static function metaTag(): string
    {
        return sprintf(
            '<meta name="csrf-token" content="%s">',
            htmlspecialchars(self::getToken())
        );
    }

    /**
     * Generate a cryptographically secure token
     */
    private static function generateToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_LENGTH));
    }

    /**
     * Ensure session is started
     */
    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Middleware-style check for routes
     */
    public static function check(): bool
    {
        // Skip for safe methods
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
            return true;
        }

        return self::validate();
    }

    /**
     * Get JavaScript code for AJAX setup
     */
    public static function ajaxSetupScript(): string
    {
        $token = self::getToken();
        return <<<JS
<script>
(function() {
    const csrfToken = '{$token}';

    // Add to all fetch requests
    const originalFetch = window.fetch;
    window.fetch = function(url, options = {}) {
        options.headers = options.headers || {};
        if (typeof options.headers.append === 'function') {
            options.headers.append('X-CSRF-Token', csrfToken);
        } else {
            options.headers['X-CSRF-Token'] = csrfToken;
        }
        return originalFetch(url, options);
    };

    // Add to all XMLHttpRequest
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function() {
        const result = originalOpen.apply(this, arguments);
        this.setRequestHeader('X-CSRF-Token', csrfToken);
        return result;
    };

    // Add to jQuery if present
    if (typeof jQuery !== 'undefined') {
        jQuery.ajaxSetup({
            headers: { 'X-CSRF-Token': csrfToken }
        });
    }
})();
</script>
JS;
    }
}

/**
 * CSRF Exception
 */
class CsrfException extends Exception
{
    public function __construct(string $message = 'CSRF validation failed', int $code = 403)
    {
        parent::__construct($message, $code);
    }
}

/**
 * Helper functions for templates
 */
if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::getToken();
    }
}

if (!function_exists('csrf_meta')) {
    function csrf_meta(): string
    {
        return Csrf::metaTag();
    }
}
