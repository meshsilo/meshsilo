<?php
/**
 * API Authentication Middleware
 *
 * Validates API key for API routes.
 */

require_once __DIR__ . '/MiddlewareInterface.php';

class ApiAuthMiddleware implements MiddlewareInterface {
    private bool $required;

    /**
     * Create middleware instance
     *
     * @param bool $required Whether API key is required (default: true)
     */
    public function __construct(bool $required = true) {
        $this->required = $required;
    }

    /**
     * Handle the middleware
     */
    public function handle(array $params): bool {
        header('Content-Type: application/json');

        // Get API key from header or query
        $apiKey = $this->getApiKey();

        if (empty($apiKey)) {
            if ($this->required) {
                http_response_code(401);
                echo json_encode(['error' => 'API key required']);
                return false;
            }
            return true;
        }

        // Validate API key using timing-safe comparison
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM api_keys WHERE is_active = 1');
        $result = $stmt->execute();

        $keyData = null;
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $storedKey = $row['api_key'] ?? '';
            if (hash_equals($storedKey, $apiKey)) {
                $keyData = $row;
                break;
            }
        }

        if (!$keyData) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid API key']);
            return false;
        }

        // Update last used timestamp
        $updateStmt = $db->prepare('UPDATE api_keys SET last_used = CURRENT_TIMESTAMP WHERE id = :id');
        $updateStmt->bindValue(':id', $keyData['id'], PDO::PARAM_INT);
        $updateStmt->execute();

        // Store API user info for route handlers
        $GLOBALS['apiUser'] = $keyData;

        return true;
    }

    /**
     * Extract API key from request
     */
    private function getApiKey(): ?string {
        // Check Authorization header (Bearer token)
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        // Check X-API-Key header
        if (!empty($_SERVER['HTTP_X_API_KEY'])) {
            return $_SERVER['HTTP_X_API_KEY'];
        }

        // Check query parameter
        if (!empty($_GET['api_key'])) {
            return $_GET['api_key'];
        }

        return null;
    }
}
