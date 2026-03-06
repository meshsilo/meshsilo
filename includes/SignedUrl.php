<?php
/**
 * Signed URLs for Silo
 *
 * Generate and verify secure, temporary URLs with expiration.
 * Useful for download links, share links, password reset links, etc.
 */

class SignedUrl {
    private const HASH_ALGO = 'sha256';
    private const SIGNATURE_PARAM = 'signature';
    private const EXPIRES_PARAM = 'expires';

    /**
     * Get the signing key
     */
    private static function getKey(): string {
        // Use app secret if defined, otherwise generate from database path
        if (defined('APP_SECRET')) {
            return APP_SECRET;
        }

        // Fallback: use a combination of site-specific values
        $salt = '';
        if (function_exists('getSetting')) {
            $salt = getSetting('signed_url_secret', '');
        }

        if (empty($salt)) {
            // Generate from database path (unique per installation)
            $salt = defined('DB_PATH') ? md5(DB_PATH) : md5(__DIR__);
        }

        return $salt;
    }

    /**
     * Generate a signed URL
     *
     * @param string $url The base URL to sign
     * @param int|null $expiresAt Unix timestamp when URL expires (null = no expiration)
     * @param array $additionalParams Additional parameters to include in signature
     * @return string The signed URL
     *
     * @example
     * SignedUrl::create('/download/123', time() + 3600);  // Expires in 1 hour
     * SignedUrl::create('/share/abc', time() + 86400);    // Expires in 24 hours
     */
    public static function create(string $url, ?int $expiresAt = null, array $additionalParams = []): string {
        // Parse the URL
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? $url;
        $query = [];

        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }

        // Add additional params
        $query = array_merge($query, $additionalParams);

        // Add expiration if set
        if ($expiresAt !== null) {
            $query[self::EXPIRES_PARAM] = $expiresAt;
        }

        // Sort parameters for consistent signing
        ksort($query);

        // Generate signature
        $dataToSign = $path . '?' . http_build_query($query);
        $signature = self::generateSignature($dataToSign);

        // Add signature to query
        $query[self::SIGNATURE_PARAM] = $signature;

        // Build final URL
        $finalUrl = $path;
        /** @phpstan-ignore if.alwaysTrue */
        if ($query) {
            $finalUrl .= '?' . http_build_query($query);
        }

        // Add base URL if configured
        if (defined('SITE_URL') && !str_starts_with($finalUrl, 'http')) {
            $finalUrl = rtrim(SITE_URL, '/') . $finalUrl;
        }

        return $finalUrl;
    }

    /**
     * Generate a signed URL for a named route
     *
     * @param string $routeName Route name
     * @param array $routeParams Route parameters
     * @param int|null $expiresAt Expiration timestamp
     * @param array $queryParams Additional query parameters
     * @return string Signed URL
     *
     * @example
     * SignedUrl::route('download', ['id' => 123], time() + 3600);
     */
    public static function route(string $routeName, array $routeParams = [], ?int $expiresAt = null, array $queryParams = []): string {
        $url = Router::url($routeName, $routeParams, []);
        return self::create($url, $expiresAt, $queryParams);
    }

    /**
     * Verify a signed URL
     *
     * @param string|null $url URL to verify (null = current request URL)
     * @return bool True if signature is valid and not expired
     */
    public static function verify(?string $url = null): bool {
        if ($url === null) {
            // Use current request
            $url = $_SERVER['REQUEST_URI'] ?? '';
        }

        // Parse URL
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';
        $query = [];

        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }

        // Also check $_GET for current request
        if ($url === $_SERVER['REQUEST_URI']) {
            $query = array_merge($query, $_GET);
            unset($query['route']); // Remove router param
        }

        // Get and remove signature
        $providedSignature = $query[self::SIGNATURE_PARAM] ?? '';
        unset($query[self::SIGNATURE_PARAM]);

        if (empty($providedSignature)) {
            return false;
        }

        // Check expiration
        if (isset($query[self::EXPIRES_PARAM])) {
            $expiresAt = (int)$query[self::EXPIRES_PARAM];
            if ($expiresAt < time()) {
                return false; // Expired
            }
        }

        // Sort parameters
        ksort($query);

        // Regenerate signature
        $dataToSign = $path . '?' . http_build_query($query);
        $expectedSignature = self::generateSignature($dataToSign);

        // Constant-time comparison
        return hash_equals($expectedSignature, $providedSignature);
    }

    /**
     * Verify current request has a valid signature
     * Aborts with 403 if invalid
     */
    public static function verifyOrFail(): void {
        if (!self::verify()) {
            http_response_code(403);

            if (self::isExpired()) {
                $message = 'This link has expired.';
            } else {
                $message = 'Invalid or tampered URL.';
            }

            // Check if API/AJAX request
            $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false
                || !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

            if ($isApi) {
                header('Content-Type: application/json');
                echo json_encode(['error' => $message]);
            } else {
                echo '<!DOCTYPE html><html><head><title>Access Denied</title></head>';
                echo '<body style="font-family: sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0;">';
                echo '<div style="text-align: center;"><h1>Access Denied</h1><p>' . htmlspecialchars($message) . '</p></div>';
                echo '</body></html>';
            }

            exit;
        }
    }

    /**
     * Check if the current URL signature is expired
     */
    public static function isExpired(): bool {
        $expiresAt = $_GET[self::EXPIRES_PARAM] ?? null;
        if ($expiresAt === null) {
            return false;
        }
        return (int)$expiresAt < time();
    }

    /**
     * Get expiration timestamp from current URL
     */
    public static function getExpiration(): ?int {
        $expiresAt = $_GET[self::EXPIRES_PARAM] ?? null;
        return $expiresAt !== null ? (int)$expiresAt : null;
    }

    /**
     * Generate HMAC signature
     */
    private static function generateSignature(string $data): string {
        $signature = hash_hmac(self::HASH_ALGO, $data, self::getKey(), true);
        return rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    /**
     * Create a temporary download link
     *
     * @param int $fileId File/part ID
     * @param int $ttlSeconds Time to live in seconds (default: 1 hour)
     * @return string Signed download URL
     */
    public static function downloadLink(int $fileId, int $ttlSeconds = 3600): string {
        return self::route('download', ['id' => $fileId], time() + $ttlSeconds);
    }

    /**
     * Create a temporary share link
     *
     * @param string $token Share token
     * @param int $ttlSeconds Time to live in seconds (default: 7 days)
     * @return string Signed share URL
     */
    public static function shareLink(string $token, int $ttlSeconds = 604800): string {
        return self::route('share.view', ['token' => $token], time() + $ttlSeconds);
    }

    /**
     * Create a model view link with tracking
     *
     * @param int $modelId Model ID
     * @param int|null $expiresAt Expiration (null = no expiration)
     * @param array $tracking Additional tracking params
     * @return string Signed URL
     */
    public static function modelLink(int $modelId, ?int $expiresAt = null, array $tracking = []): string {
        return self::route('model.show', ['id' => $modelId], $expiresAt, $tracking);
    }
}

/**
 * Middleware to verify signed URLs
 */
class SignedUrlMiddleware implements MiddlewareInterface {
    public function handle(array $params): bool {
        if (!SignedUrl::verify()) {
            http_response_code(403);

            header('Content-Type: application/json');
            echo json_encode([
                'error' => SignedUrl::isExpired() ? 'Link expired' : 'Invalid signature',
                'expired' => SignedUrl::isExpired()
            ]);

            return false;
        }

        return true;
    }
}

// Helper functions for templates

/**
 * Generate a signed URL
 */
function signedUrl(string $url, ?int $expiresAt = null): string {
    return SignedUrl::create($url, $expiresAt);
}

/**
 * Generate a signed route URL
 */
function signedRoute(string $name, array $params = [], ?int $expiresAt = null): string {
    return SignedUrl::route($name, $params, $expiresAt);
}

/**
 * Generate a temporary download link
 */
function temporaryDownloadLink(int $fileId, int $ttlSeconds = 3600): string {
    return SignedUrl::downloadLink($fileId, $ttlSeconds);
}
