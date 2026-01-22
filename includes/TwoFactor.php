<?php
/**
 * Two-Factor Authentication (2FA) for Silo
 *
 * Implements TOTP (Time-based One-Time Password) compatible with:
 * - Google Authenticator
 * - Authy
 * - Microsoft Authenticator
 * - 1Password
 * - Any TOTP app
 *
 * Usage:
 *   $secret = TwoFactor::generateSecret();
 *   $qrUrl = TwoFactor::getQRCodeUrl($secret, 'user@example.com');
 *   $valid = TwoFactor::verify($secret, $code);
 */

class TwoFactor {
    // TOTP settings
    private const CODE_LENGTH = 6;
    private const TIME_STEP = 30; // seconds
    private const SECRET_LENGTH = 16; // bytes (128 bits)
    private const BACKUP_CODE_COUNT = 10;
    private const BACKUP_CODE_LENGTH = 8;

    // Base32 alphabet for secret encoding
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a new secret key
     */
    public static function generateSecret(): string {
        $bytes = random_bytes(self::SECRET_LENGTH);
        return self::base32Encode($bytes);
    }

    /**
     * Generate a TOTP code
     */
    public static function generateCode(string $secret, ?int $timestamp = null): string {
        $timestamp = $timestamp ?? time();
        $timeSlice = floor($timestamp / self::TIME_STEP);

        $secretBytes = self::base32Decode($secret);
        $timeBytes = pack('N*', 0, $timeSlice);

        $hash = hash_hmac('sha1', $timeBytes, $secretBytes, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % pow(10, self::CODE_LENGTH);

        return str_pad((string)$code, self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a TOTP code
     *
     * @param string $secret User's secret key
     * @param string $code Code to verify
     * @param int $window Number of time steps to check before/after current time
     * @return bool True if code is valid
     */
    public static function verify(string $secret, string $code, int $window = 1): bool {
        $code = trim($code);

        if (strlen($code) !== self::CODE_LENGTH || !ctype_digit($code)) {
            return false;
        }

        $timestamp = time();

        // Check current time and ± window time steps
        for ($i = -$window; $i <= $window; $i++) {
            $checkTime = $timestamp + ($i * self::TIME_STEP);
            $validCode = self::generateCode($secret, $checkTime);

            if (hash_equals($validCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get QR code URL for setting up authenticator app
     *
     * @param string $secret User's secret key
     * @param string $account User's email or username
     * @param string|null $issuer Application name (default: SITE_NAME)
     * @return string URL for QR code image
     */
    public static function getQRCodeUrl(string $secret, string $account, ?string $issuer = null): string {
        $issuer = $issuer ?? (defined('SITE_NAME') ? SITE_NAME : 'Silo');
        $otpUrl = self::getOTPAuthUrl($secret, $account, $issuer);

        // Use Google Charts API for QR code generation
        return 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=' . urlencode($otpUrl);
    }

    /**
     * Get otpauth:// URL for manual entry
     */
    public static function getOTPAuthUrl(string $secret, string $account, ?string $issuer = null): string {
        $issuer = $issuer ?? (defined('SITE_NAME') ? SITE_NAME : 'Silo');

        $params = [
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::CODE_LENGTH,
            'period' => self::TIME_STEP
        ];

        return sprintf(
            'otpauth://totp/%s:%s?%s',
            urlencode($issuer),
            urlencode($account),
            http_build_query($params)
        );
    }

    /**
     * Generate backup codes
     *
     * @return array Array of backup codes
     */
    public static function generateBackupCodes(): array {
        $codes = [];

        for ($i = 0; $i < self::BACKUP_CODE_COUNT; $i++) {
            $code = '';
            for ($j = 0; $j < self::BACKUP_CODE_LENGTH; $j++) {
                $code .= random_int(0, 9);
            }
            // Format as XXXX-XXXX for readability
            $codes[] = substr($code, 0, 4) . '-' . substr($code, 4, 4);
        }

        return $codes;
    }

    /**
     * Hash backup codes for storage
     *
     * @param array $codes Plain backup codes
     * @return array Hashed backup codes
     */
    public static function hashBackupCodes(array $codes): array {
        return array_map(function($code) {
            // Remove formatting
            $code = str_replace('-', '', $code);
            return password_hash($code, PASSWORD_DEFAULT);
        }, $codes);
    }

    /**
     * Verify a backup code
     *
     * @param string $code Code to verify
     * @param array $hashedCodes Array of hashed backup codes
     * @return int|false Index of matched code or false
     */
    public static function verifyBackupCode(string $code, array $hashedCodes): int|false {
        // Remove formatting
        $code = str_replace('-', '', trim($code));

        foreach ($hashedCodes as $index => $hashedCode) {
            if (password_verify($code, $hashedCode)) {
                return $index;
            }
        }

        return false;
    }

    /**
     * Enable 2FA for a user
     *
     * @param int $userId User ID
     * @param string $secret Secret key
     * @param array $backupCodes Plain backup codes (will be hashed)
     * @return bool Success
     */
    public static function enable(int $userId, string $secret, array $backupCodes): bool {
        if (!function_exists('getDB')) {
            return false;
        }

        $db = getDB();

        $hashedCodes = self::hashBackupCodes($backupCodes);

        $stmt = $db->prepare('
            UPDATE users SET
                two_factor_secret = :secret,
                two_factor_backup_codes = :backup_codes,
                two_factor_enabled = 1,
                two_factor_enabled_at = CURRENT_TIMESTAMP
            WHERE id = :user_id
        ');

        $stmt->bindValue(':secret', $secret, SQLITE3_TEXT);
        $stmt->bindValue(':backup_codes', json_encode($hashedCodes), SQLITE3_TEXT);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);

        $result = $stmt->execute();

        if ($result && class_exists('Events')) {
            Events::emit('user.2fa_enabled', ['user_id' => $userId]);
        }

        return (bool)$result;
    }

    /**
     * Disable 2FA for a user
     */
    public static function disable(int $userId): bool {
        if (!function_exists('getDB')) {
            return false;
        }

        $db = getDB();

        $stmt = $db->prepare('
            UPDATE users SET
                two_factor_secret = NULL,
                two_factor_backup_codes = NULL,
                two_factor_enabled = 0,
                two_factor_enabled_at = NULL
            WHERE id = :user_id
        ');

        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();

        if ($result && class_exists('Events')) {
            Events::emit('user.2fa_disabled', ['user_id' => $userId]);
        }

        return (bool)$result;
    }

    /**
     * Check if 2FA is enabled for a user
     */
    public static function isEnabled(int $userId): bool {
        if (!function_exists('getDB')) {
            return false;
        }

        $db = getDB();
        $stmt = $db->prepare('SELECT two_factor_enabled FROM users WHERE id = :user_id');
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return (bool)($row['two_factor_enabled'] ?? false);
    }

    /**
     * Get user's 2FA secret
     */
    public static function getSecret(int $userId): ?string {
        if (!function_exists('getDB')) {
            return null;
        }

        $db = getDB();
        $stmt = $db->prepare('SELECT two_factor_secret FROM users WHERE id = :user_id');
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return $row['two_factor_secret'] ?? null;
    }

    /**
     * Verify 2FA code for a user (TOTP or backup code)
     *
     * @param int $userId User ID
     * @param string $code Code to verify
     * @return bool True if valid
     */
    public static function verifyForUser(int $userId, string $code): bool {
        if (!function_exists('getDB')) {
            return false;
        }

        $db = getDB();
        $stmt = $db->prepare('
            SELECT two_factor_secret, two_factor_backup_codes
            FROM users WHERE id = :user_id
        ');
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $user = $result->fetchArray(SQLITE3_ASSOC);

        if (!$user || empty($user['two_factor_secret'])) {
            return false;
        }

        // Try TOTP first
        if (self::verify($user['two_factor_secret'], $code)) {
            return true;
        }

        // Try backup codes
        $backupCodes = json_decode($user['two_factor_backup_codes'] ?? '[]', true);
        if (!empty($backupCodes)) {
            $matchedIndex = self::verifyBackupCode($code, $backupCodes);
            if ($matchedIndex !== false) {
                // Remove used backup code
                unset($backupCodes[$matchedIndex]);
                $backupCodes = array_values($backupCodes);

                $updateStmt = $db->prepare('
                    UPDATE users SET two_factor_backup_codes = :codes WHERE id = :user_id
                ');
                $updateStmt->bindValue(':codes', json_encode($backupCodes), SQLITE3_TEXT);
                $updateStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                $updateStmt->execute();

                if (class_exists('Events')) {
                    Events::emit('user.2fa_backup_code_used', [
                        'user_id' => $userId,
                        'remaining_codes' => count($backupCodes)
                    ]);
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Get remaining backup codes count
     */
    public static function getRemainingBackupCodes(int $userId): int {
        if (!function_exists('getDB')) {
            return 0;
        }

        $db = getDB();
        $stmt = $db->prepare('SELECT two_factor_backup_codes FROM users WHERE id = :user_id');
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        $codes = json_decode($row['two_factor_backup_codes'] ?? '[]', true);
        return count($codes);
    }

    /**
     * Regenerate backup codes for a user
     */
    public static function regenerateBackupCodes(int $userId): array {
        $codes = self::generateBackupCodes();

        if (function_exists('getDB')) {
            $db = getDB();
            $hashedCodes = self::hashBackupCodes($codes);

            $stmt = $db->prepare('
                UPDATE users SET two_factor_backup_codes = :codes WHERE id = :user_id
            ');
            $stmt->bindValue(':codes', json_encode($hashedCodes), SQLITE3_TEXT);
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $stmt->execute();
        }

        return $codes;
    }

    /**
     * Base32 encode
     */
    private static function base32Encode(string $data): string {
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        $chunks = str_split($binary, 5);
        foreach ($chunks as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= self::BASE32_CHARS[bindec($chunk)];
        }

        return $encoded;
    }

    /**
     * Base32 decode
     */
    private static function base32Decode(string $data): string {
        $data = strtoupper($data);
        $binary = '';

        foreach (str_split($data) as $char) {
            $index = strpos(self::BASE32_CHARS, $char);
            if ($index !== false) {
                $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
            }
        }

        $decoded = '';
        $chunks = str_split($binary, 8);
        foreach ($chunks as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }

    /**
     * Generate QR code as SVG (no external service needed)
     */
    public static function generateQRCodeSVG(string $secret, string $account, ?string $issuer = null): string {
        $url = self::getOTPAuthUrl($secret, $account, $issuer);

        // Simple QR code generation - for production, use a proper library
        // This is a placeholder that returns a link instead
        return '<a href="' . htmlspecialchars(self::getQRCodeUrl($secret, $account, $issuer)) . '" target="_blank">View QR Code</a>';
    }
}

/**
 * 2FA Middleware
 *
 * Requires 2FA verification for users with 2FA enabled
 */
class TwoFactorMiddleware implements MiddlewareInterface {
    public function handle(array $params): bool {
        // Check if user is logged in
        if (!function_exists('isLoggedIn') || !isLoggedIn()) {
            return true; // Let auth middleware handle this
        }

        $user = getCurrentUser();

        // Check if 2FA is enabled for this user
        if (!TwoFactor::isEnabled($user['id'])) {
            return true; // No 2FA required
        }

        // Check if 2FA has been verified this session
        if (!empty($_SESSION['2fa_verified']) && $_SESSION['2fa_verified'] === $user['id']) {
            return true; // Already verified
        }

        // Redirect to 2FA verification page
        $_SESSION['2fa_return_url'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . (function_exists('route') ? route('2fa.verify') : '/2fa-verify'));
        exit;
    }
}
