<?php
/**
 * Encryption at Rest Manager
 * Provides AES-256-GCM encryption for files and data
 */

class Encryption {
    private const CIPHER = 'aes-256-gcm';
    private const KEY_LENGTH = 32; // 256 bits
    private const IV_LENGTH = 12;  // 96 bits for GCM
    private const TAG_LENGTH = 16; // 128 bits for GCM auth tag

    private static ?string $masterKey = null;

    /**
     * Initialize encryption with master key
     */
    public static function init(): void {
        if (self::$masterKey !== null) {
            return;
        }

        // Try to load master key from environment or config
        $key = getenv('SILO_ENCRYPTION_KEY');

        if (!$key && function_exists('getSetting')) {
            $key = getSetting('encryption_master_key', '');
        }

        if (!$key) {
            // Check for key file
            $keyFile = __DIR__ . '/../storage/.encryption_key';
            if (file_exists($keyFile)) {
                $key = trim(file_get_contents($keyFile));
            }
        }

        if ($key) {
            // Decode if base64
            if (strlen($key) === 44 && preg_match('/^[A-Za-z0-9+\/=]+$/', $key)) {
                $key = base64_decode($key);
            }
            self::$masterKey = $key;
        }
    }

    /**
     * Check if encryption is enabled and configured
     */
    public static function isEnabled(): bool {
        self::init();
        return self::$masterKey !== null && strlen(self::$masterKey) === self::KEY_LENGTH;
    }

    /**
     * Generate a new master encryption key
     */
    public static function generateKey(): string {
        $key = random_bytes(self::KEY_LENGTH);
        return base64_encode($key);
    }

    /**
     * Set the master key (for setup/rotation)
     */
    public static function setMasterKey(string $key): bool {
        // Decode if base64
        if (strlen($key) === 44) {
            $decoded = base64_decode($key, true);
            if ($decoded && strlen($decoded) === self::KEY_LENGTH) {
                $key = $decoded;
            }
        }

        if (strlen($key) !== self::KEY_LENGTH) {
            return false;
        }

        self::$masterKey = $key;

        // Store in key file
        $keyFile = __DIR__ . '/../storage/.encryption_key';
        $dir = dirname($keyFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        file_put_contents($keyFile, base64_encode($key));
        chmod($keyFile, 0600);

        return true;
    }

    /**
     * Derive a key for a specific purpose (e.g., file encryption, data encryption)
     */
    private static function deriveKey(string $purpose): string {
        self::init();

        if (!self::$masterKey) {
            throw new Exception('Encryption not configured');
        }

        // Use HKDF to derive purpose-specific key
        return hash_hkdf('sha256', self::$masterKey, self::KEY_LENGTH, $purpose, 'silo-encryption');
    }

    /**
     * Encrypt data
     */
    public static function encrypt(string $plaintext, string $purpose = 'data'): string {
        $key = self::deriveKey($purpose);
        $iv = random_bytes(self::IV_LENGTH);

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new Exception('Encryption failed: ' . openssl_error_string());
        }

        // Format: version (1 byte) + iv (12 bytes) + tag (16 bytes) + ciphertext
        return chr(1) . $iv . $tag . $ciphertext;
    }

    /**
     * Decrypt data
     */
    public static function decrypt(string $encrypted, string $purpose = 'data'): string {
        if (strlen($encrypted) < 1 + self::IV_LENGTH + self::TAG_LENGTH + 1) {
            throw new Exception('Invalid encrypted data');
        }

        $version = ord($encrypted[0]);
        if ($version !== 1) {
            throw new Exception('Unsupported encryption version');
        }

        $key = self::deriveKey($purpose);
        $iv = substr($encrypted, 1, self::IV_LENGTH);
        $tag = substr($encrypted, 1 + self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($encrypted, 1 + self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new Exception('Decryption failed: ' . openssl_error_string());
        }

        return $plaintext;
    }

    /**
     * Encrypt a file and save to destination
     */
    public static function encryptFile(string $sourcePath, string $destPath): bool {
        if (!file_exists($sourcePath)) {
            throw new Exception('Source file not found');
        }

        $key = self::deriveKey('file');
        $iv = random_bytes(self::IV_LENGTH);

        // Open files
        $sourceHandle = fopen($sourcePath, 'rb');
        $destHandle = fopen($destPath, 'wb');

        if (!$sourceHandle || !$destHandle) {
            throw new Exception('Failed to open file handles');
        }

        try {
            // Write header: version + iv + placeholder for tag
            fwrite($destHandle, chr(1));
            fwrite($destHandle, $iv);
            $tagPosition = ftell($destHandle);
            fwrite($destHandle, str_repeat("\0", self::TAG_LENGTH));

            // Encrypt in chunks
            $chunkSize = 1024 * 1024; // 1MB chunks
            $allCiphertext = '';

            while (!feof($sourceHandle)) {
                $chunk = fread($sourceHandle, $chunkSize);
                if ($chunk === false) break;

                $encrypted = openssl_encrypt(
                    $chunk,
                    'aes-256-cbc', // Use CBC for streaming
                    $key,
                    OPENSSL_RAW_DATA,
                    $iv
                );

                // Update IV for next chunk (CBC mode)
                $iv = substr($encrypted, -16);
                fwrite($destHandle, $encrypted);
                $allCiphertext .= $encrypted;
            }

            // Calculate and write auth tag (HMAC of all ciphertext)
            $tag = hash_hmac('sha256', $allCiphertext, $key, true);
            $tag = substr($tag, 0, self::TAG_LENGTH);

            fseek($destHandle, $tagPosition);
            fwrite($destHandle, $tag);

            return true;
        } finally {
            fclose($sourceHandle);
            fclose($destHandle);
        }
    }

    /**
     * Decrypt a file and save to destination
     */
    public static function decryptFile(string $sourcePath, string $destPath): bool {
        if (!file_exists($sourcePath)) {
            throw new Exception('Source file not found');
        }

        $key = self::deriveKey('file');

        $sourceHandle = fopen($sourcePath, 'rb');
        $destHandle = fopen($destPath, 'wb');

        if (!$sourceHandle || !$destHandle) {
            throw new Exception('Failed to open file handles');
        }

        try {
            // Read header
            $version = ord(fread($sourceHandle, 1));
            if ($version !== 1) {
                throw new Exception('Unsupported file encryption version');
            }

            $iv = fread($sourceHandle, self::IV_LENGTH);
            $storedTag = fread($sourceHandle, self::TAG_LENGTH);
            $headerSize = 1 + self::IV_LENGTH + self::TAG_LENGTH;

            // Read all ciphertext and verify tag
            $ciphertext = stream_get_contents($sourceHandle);
            $computedTag = substr(hash_hmac('sha256', $ciphertext, $key, true), 0, self::TAG_LENGTH);

            if (!hash_equals($storedTag, $computedTag)) {
                throw new Exception('File authentication failed - file may be corrupted or tampered');
            }

            // Decrypt in chunks
            $chunkSize = 1024 * 1024 + 16; // 1MB + padding
            $offset = 0;

            while ($offset < strlen($ciphertext)) {
                $chunk = substr($ciphertext, $offset, $chunkSize);
                $offset += strlen($chunk);

                $decrypted = openssl_decrypt(
                    $chunk,
                    'aes-256-cbc',
                    $key,
                    OPENSSL_RAW_DATA,
                    $iv
                );

                if ($decrypted === false) {
                    throw new Exception('Decryption failed');
                }

                // Update IV for next chunk
                $iv = substr($chunk, -16);
                fwrite($destHandle, $decrypted);
            }

            return true;
        } finally {
            fclose($sourceHandle);
            fclose($destHandle);
        }
    }

    /**
     * Encrypt a file in-place (replaces original)
     */
    public static function encryptFileInPlace(string $path): bool {
        $tempPath = $path . '.enc.tmp';

        try {
            self::encryptFile($path, $tempPath);
            unlink($path);
            rename($tempPath, $path);
            return true;
        } catch (Exception $e) {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            throw $e;
        }
    }

    /**
     * Decrypt a file in-place (replaces encrypted)
     */
    public static function decryptFileInPlace(string $path): bool {
        $tempPath = $path . '.dec.tmp';

        try {
            self::decryptFile($path, $tempPath);
            unlink($path);
            rename($tempPath, $path);
            return true;
        } catch (Exception $e) {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            throw $e;
        }
    }

    /**
     * Check if a file is encrypted
     */
    public static function isFileEncrypted(string $path): bool {
        if (!file_exists($path)) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            return false;
        }

        $header = fread($handle, 1);
        fclose($handle);

        return $header === chr(1);
    }

    /**
     * Get a stream filter for transparent encryption/decryption
     */
    public static function getDecryptedStream(string $encryptedPath) {
        if (!self::isFileEncrypted($encryptedPath)) {
            // Not encrypted, return normal stream
            return fopen($encryptedPath, 'rb');
        }

        // Create temp file with decrypted content
        $tempPath = sys_get_temp_dir() . '/silo_dec_' . md5($encryptedPath . microtime());
        self::decryptFile($encryptedPath, $tempPath);

        // Open stream and register cleanup
        $stream = fopen($tempPath, 'rb');

        // Clean up temp file when stream closes
        register_shutdown_function(function() use ($tempPath) {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        });

        return $stream;
    }

    /**
     * Encrypt all files in storage (migration)
     */
    public static function encryptAllFiles(string $basePath, callable $progressCallback = null): array {
        if (!self::isEnabled()) {
            throw new Exception('Encryption not configured');
        }

        $results = [
            'total' => 0,
            'encrypted' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $results['total']++;
            $path = $file->getPathname();

            try {
                if (self::isFileEncrypted($path)) {
                    $results['skipped']++;
                } else {
                    self::encryptFileInPlace($path);
                    $results['encrypted']++;
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = $path . ': ' . $e->getMessage();
            }

            if ($progressCallback) {
                $progressCallback($results);
            }
        }

        return $results;
    }

    /**
     * Decrypt all files in storage (migration/export)
     */
    public static function decryptAllFiles(string $basePath, callable $progressCallback = null): array {
        if (!self::isEnabled()) {
            throw new Exception('Encryption not configured');
        }

        $results = [
            'total' => 0,
            'decrypted' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $results['total']++;
            $path = $file->getPathname();

            try {
                if (!self::isFileEncrypted($path)) {
                    $results['skipped']++;
                } else {
                    self::decryptFileInPlace($path);
                    $results['decrypted']++;
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = $path . ': ' . $e->getMessage();
            }

            if ($progressCallback) {
                $progressCallback($results);
            }
        }

        return $results;
    }

    /**
     * Rotate encryption key
     * Re-encrypts all files with new key
     */
    public static function rotateKey(string $newKey, string $basePath): array {
        // First decrypt everything with current key
        $decryptResults = self::decryptAllFiles($basePath);

        if ($decryptResults['failed'] > 0) {
            return [
                'success' => false,
                'error' => 'Failed to decrypt some files',
                'details' => $decryptResults,
            ];
        }

        // Set new key
        $oldKey = self::$masterKey;
        if (!self::setMasterKey($newKey)) {
            // Restore old key
            self::$masterKey = $oldKey;
            return [
                'success' => false,
                'error' => 'Invalid new key',
            ];
        }

        // Re-encrypt with new key
        $encryptResults = self::encryptAllFiles($basePath);

        return [
            'success' => $encryptResults['failed'] === 0,
            'decrypted' => $decryptResults['decrypted'],
            'encrypted' => $encryptResults['encrypted'],
            'failed' => $encryptResults['failed'],
            'errors' => $encryptResults['errors'],
        ];
    }

    /**
     * Get encryption status for admin panel
     */
    public static function getStatus(): array {
        self::init();

        return [
            'enabled' => self::isEnabled(),
            'cipher' => self::CIPHER,
            'key_configured' => self::$masterKey !== null,
            'key_valid' => self::$masterKey !== null && strlen(self::$masterKey) === self::KEY_LENGTH,
            'openssl_version' => OPENSSL_VERSION_TEXT,
        ];
    }
}

/**
 * Encrypted storage wrapper
 * Drop-in replacement for file storage with transparent encryption
 */
class EncryptedStorage implements StorageInterface {
    private StorageInterface $storage;
    private bool $encryptionEnabled;

    public function __construct(StorageInterface $storage) {
        $this->storage = $storage;
        $this->encryptionEnabled = Encryption::isEnabled() &&
            (function_exists('getSetting') ? getSetting('encryption_at_rest', '0') === '1' : false);
    }

    public function put($path, $content) {
        if ($this->encryptionEnabled) {
            $content = Encryption::encrypt($content, 'storage');
        }
        return $this->storage->put($path, $content);
    }

    public function putFile($path, $localFile) {
        if ($this->encryptionEnabled) {
            $tempPath = sys_get_temp_dir() . '/silo_enc_' . uniqid();
            Encryption::encryptFile($localFile, $tempPath);
            $result = $this->storage->putFile($path, $tempPath);
            unlink($tempPath);
            return $result;
        }
        return $this->storage->putFile($path, $localFile);
    }

    public function get($path) {
        $content = $this->storage->get($path);
        if ($content === null) {
            return null;
        }

        // Check if content is encrypted
        if (strlen($content) > 0 && ord($content[0]) === 1) {
            try {
                return Encryption::decrypt($content, 'storage');
            } catch (Exception $e) {
                // May not be encrypted, return as-is
                return $content;
            }
        }

        return $content;
    }

    public function delete($path) {
        return $this->storage->delete($path);
    }

    public function exists($path) {
        return $this->storage->exists($path);
    }

    public function url($path, $expiry = 3600) {
        // For encrypted files, we can't provide direct URLs
        // Need to serve through PHP
        if ($this->encryptionEnabled) {
            return route('actions.download', ['path' => $path]);
        }
        return $this->storage->url($path, $expiry);
    }

    public function size($path) {
        return $this->storage->size($path);
    }

    public function copy($from, $to) {
        return $this->storage->copy($from, $to);
    }

    public function move($from, $to) {
        return $this->storage->move($from, $to);
    }

    public function listFiles($prefix = '') {
        return $this->storage->listFiles($prefix);
    }
}
