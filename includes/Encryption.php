<?php

/**
 * Encryption at Rest Manager
 * Provides AES-256-GCM encryption for files and data
 */

// EncryptedStorage (below) implements StorageInterface, so autoloading this
// file outside the web bootstrap (CLI, plugin flows) fatals unless the
// interface is loaded first. Definitions only - no side effects.
require_once __DIR__ . '/storage.php';

/**
 * Master key configuration (in the order init() resolves them):
 *
 *   1. SILO_ENCRYPTION_KEY environment variable  - RECOMMENDED
 *      Set it in the web server / container environment (docker-compose
 *      `environment:`, systemd `Environment=`, php-fpm pool `env[]`). The key
 *      never touches the application's own storage.
 *
 *   2. storage/.encryption_key file (0600, written by setMasterKey())
 *      Acceptable when the environment cannot carry secrets. Keep it outside
 *      any database backup and out of version control (it is gitignored).
 *
 *   3. settings.encryption_master_key in the database  - INSECURE, LEGACY
 *      Kept only so existing installs stay readable. The key then lives in the
 *      same database as the ciphertext it protects, so a single DB dump yields
 *      both and encryption-at-rest provides no protection. getStatus() reports
 *      this case as insecure and init() logs a warning. To migrate: put the
 *      same key value into SILO_ENCRYPTION_KEY (or storage/.encryption_key),
 *      confirm files still decrypt, then delete the database setting.
 */
class Encryption
{
    private const CIPHER = 'aes-256-gcm';
    private const KEY_LENGTH = 32; // 256 bits
    private const IV_LENGTH = 12;  // 96 bits for GCM
    private const TAG_LENGTH = 16; // 128 bits for GCM auth tag

    // Where the master key currently in use came from (see getStatus()).
    public const KEY_SOURCE_ENV = 'env';
    public const KEY_SOURCE_FILE = 'file';
    public const KEY_SOURCE_DATABASE = 'database';
    public const KEY_SOURCE_NONE = 'none';

    private static ?string $masterKey = null;
    private static ?string $keySource = null;

    /**
     * Initialize encryption with master key
     */
    public static function init(): void
    {
        if (self::$masterKey !== null) {
            return;
        }

        // Prefer key material from the environment or a local (0600) key file
        // over the database, so the master key is not sourced from
        // application-managed storage when a stronger source is available.
        $key = getenv('SILO_ENCRYPTION_KEY');
        $source = $key ? self::KEY_SOURCE_ENV : self::KEY_SOURCE_NONE;

        if (!$key) {
            // Check for key file (created with 0600 permissions by setMasterKey)
            $keyFile = __DIR__ . '/../storage/.encryption_key';
            if (file_exists($keyFile)) {
                $key = trim(file_get_contents($keyFile));
                if ($key) {
                    $source = self::KEY_SOURCE_FILE;
                }
            }
        }

        // Backward-compatible fallback for installs that only store the key in the DB.
        // Insecure (key and ciphertext share one dump) but load-bearing: removing it
        // would make those installs' data unreadable. Flag it instead of dropping it.
        if (!$key && function_exists('getSetting')) {
            $key = getSetting('encryption_master_key', '');
            if ($key) {
                $source = self::KEY_SOURCE_DATABASE;
                self::warnDatabaseKeySource();
            }
        }

        self::$keySource = $source;

        if ($key) {
            // Decode if base64
            if (strlen($key) === 44 && preg_match('/^[A-Za-z0-9+\/=]+$/', $key)) {
                $key = base64_decode($key);
            }
            self::$masterKey = $key;
        }
    }

    /**
     * Warn (once per request) that the master key is being read from the same
     * database it protects. Silence would let an install believe it has
     * encryption at rest when a single DB dump defeats it.
     */
    private static function warnDatabaseKeySource(): void
    {
        static $warned = false;
        if ($warned) {
            return;
        }
        $warned = true;

        if (function_exists('logWarning')) {
            logWarning(
                'Encryption master key is being read from the database (settings.encryption_master_key). '
                . 'The key and the data it protects are then in the same dump, so encryption at rest '
                . 'provides no protection. Move the key to the SILO_ENCRYPTION_KEY environment variable '
                . 'or to storage/.encryption_key, then delete the database setting.'
            );
        }
    }

    /**
     * Check if encryption is enabled and configured
     */
    public static function isEnabled(): bool
    {
        self::init();
        return self::$masterKey !== null && strlen(self::$masterKey) === self::KEY_LENGTH;
    }

    /**
     * Generate a new master encryption key
     */
    public static function generateKey(): string
    {
        $key = random_bytes(self::KEY_LENGTH);
        return base64_encode($key);
    }

    /**
     * Set the master key (for setup/rotation)
     */
    public static function setMasterKey(string $key): bool
    {
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
        self::$keySource = self::KEY_SOURCE_FILE;

        return true;
    }

    /**
     * Derive a key for a specific purpose (e.g., file encryption, data encryption)
     */
    private static function deriveKey(string $purpose): string
    {
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
    public static function encrypt(string $plaintext, string $purpose = 'data'): string
    {
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
    public static function decrypt(string $encrypted, string $purpose = 'data'): string
    {
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
     *
     * Format: version (1 byte) + chunk_count (4 bytes) + [iv (12) + tag (16) + ciphertext]...
     * Each chunk is independently encrypted with AES-256-GCM using a unique IV.
     */
    public static function encryptFile(string $sourcePath, string $destPath): bool
    {
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
            // Write header: version (2 = GCM chunked)
            fwrite($destHandle, chr(2));
            // Placeholder for chunk count (4 bytes, big-endian)
            $chunkCountPos = ftell($destHandle);
            fwrite($destHandle, pack('N', 0));

            $chunkSize = 1024 * 1024; // 1MB chunks
            $chunkCount = 0;

            while (!feof($sourceHandle)) {
                $chunk = fread($sourceHandle, $chunkSize);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $iv = random_bytes(self::IV_LENGTH);
                $ciphertext = openssl_encrypt(
                    $chunk,
                    self::CIPHER,
                    $key,
                    OPENSSL_RAW_DATA,
                    $iv,
                    $tag,
                    pack('N', $chunkCount), // AAD: chunk index prevents reordering
                    self::TAG_LENGTH
                );

                if ($ciphertext === false) {
                    throw new Exception('Encryption failed: ' . openssl_error_string());
                }

                // Write: iv + tag + ciphertext_length (4 bytes) + ciphertext
                fwrite($destHandle, $iv);
                fwrite($destHandle, $tag);
                fwrite($destHandle, pack('N', strlen($ciphertext)));
                fwrite($destHandle, $ciphertext);
                $chunkCount++;
            }

            // Write actual chunk count
            fseek($destHandle, $chunkCountPos);
            fwrite($destHandle, pack('N', $chunkCount));

            return true;
        } finally {
            fclose($sourceHandle);
            fclose($destHandle);
        }
    }

    /**
     * Decrypt a file and save to destination
     *
     * Supports both v1 (legacy CBC+HMAC) and v2 (GCM chunked) formats.
     */
    public static function decryptFile(string $sourcePath, string $destPath): bool
    {
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
            $version = ord(fread($sourceHandle, 1));

            if ($version === 2) {
                // GCM chunked format
                $chunkCountData = fread($sourceHandle, 4);
                $chunkCount = unpack('N', $chunkCountData)[1];

                for ($i = 0; $i < $chunkCount; $i++) {
                    $iv = fread($sourceHandle, self::IV_LENGTH);
                    $tag = fread($sourceHandle, self::TAG_LENGTH);
                    $lenData = fread($sourceHandle, 4);
                    $ciphertextLen = unpack('N', $lenData)[1];
                    $ciphertext = fread($sourceHandle, $ciphertextLen);

                    $decrypted = openssl_decrypt(
                        $ciphertext,
                        self::CIPHER,
                        $key,
                        OPENSSL_RAW_DATA,
                        $iv,
                        $tag,
                        pack('N', $i) // AAD: chunk index
                    );

                    if ($decrypted === false) {
                        throw new Exception('Decryption failed at chunk ' . $i . ': ' . openssl_error_string());
                    }

                    fwrite($destHandle, $decrypted);
                }
            } elseif ($version === 1) {
                // Legacy CBC+HMAC format (read-only support for migration)
                $iv = fread($sourceHandle, self::IV_LENGTH);
                $storedTag = fread($sourceHandle, self::TAG_LENGTH);

                $ciphertext = stream_get_contents($sourceHandle);
                $computedTag = substr(hash_hmac('sha256', $ciphertext, $key, true), 0, self::TAG_LENGTH);

                if (!hash_equals($storedTag, $computedTag)) {
                    throw new Exception('File authentication failed - file may be corrupted or tampered');
                }

                // Decrypt CBC chunks
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

                    $iv = substr($chunk, -16);
                    fwrite($destHandle, $decrypted);
                }
            } else {
                throw new Exception('Unsupported file encryption version: ' . $version);
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
    public static function encryptFileInPlace(string $path): bool
    {
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
    public static function decryptFileInPlace(string $path): bool
    {
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
    public static function isFileEncrypted(string $path): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        $size = filesize($path);
        if ($size === false || $size < 1) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            return false;
        }

        try {
            $first = fread($handle, 1);
            if ($first === false || $first === '') {
                return false;
            }
            $version = ord($first);

            // A single leading 0x01/0x02 byte is far too weak: real model files
            // (STL/3MF/etc.) routinely begin with those bytes, so the old check
            // misclassified plaintext as encrypted -- encryptAllFiles() then
            // silently skipped it, leaving it in the clear. Validate the actual
            // container layout instead of just the version byte.

            if ($version === 2) {
                // v2 GCM chunked container (Encryption::encryptFile):
                //   version(1) + chunkCount(4, big-endian)
                //   + [ iv(12) + tag(16) + ctLen(4, big-endian) + ct(ctLen) ] * chunkCount
                // Walk the declared chunk table; a genuine container consumes the
                // file EXACTLY. Random binary that merely starts with 0x02 will
                // essentially never have a self-consistent table that ends at EOF.
                $countData = fread($handle, 4);
                if (strlen($countData) !== 4) {
                    return false;
                }
                $chunkCount = unpack('N', $countData)[1];

                // Encrypted empty source: version + count only, no chunks.
                if ($chunkCount === 0) {
                    return $size === 5;
                }
                if ($chunkCount > 1000000) {
                    return false; // implausible; treat as not-a-container
                }

                $offset = 5; // version(1) + chunkCount(4) already consumed
                $metaLen = self::IV_LENGTH + self::TAG_LENGTH + 4;
                for ($i = 0; $i < $chunkCount; $i++) {
                    $meta = fread($handle, $metaLen);
                    if (strlen($meta) !== $metaLen) {
                        return false;
                    }
                    $ctLen = unpack('N', substr($meta, self::IV_LENGTH + self::TAG_LENGTH, 4))[1];
                    // Each plaintext chunk is at most 1MB; GCM ciphertext is the
                    // same length. Reject implausible lengths early.
                    if ($ctLen < 1 || $ctLen > 1024 * 1024 + 1024) {
                        return false;
                    }
                    $offset += $metaLen + $ctLen;
                    if ($offset > $size) {
                        return false;
                    }
                    if (fseek($handle, $offset) !== 0) {
                        return false;
                    }
                }
                // A valid container ends exactly at EOF.
                return $offset === $size;
            }

            if ($version === 1) {
                // v1 single-block GCM (Encryption::encrypt) OR legacy CBC+HMAC
                // file: version(1) + iv(12) + tag(16) + ciphertext(>=1). There is
                // no length constraint on GCM ciphertext, so require at least the
                // fixed header plus one byte of ciphertext.
                return $size >= 1 + self::IV_LENGTH + self::TAG_LENGTH + 1;
            }

            return false;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Get a stream filter for transparent encryption/decryption
     */
    public static function getDecryptedStream(string $encryptedPath)
    {
        if (!self::isFileEncrypted($encryptedPath)) {
            // Not encrypted, return normal stream
            return fopen($encryptedPath, 'rb');
        }

        // Create the temp file up front with tempnam() so it exists with
        // owner-only (0600) permissions BEFORE any plaintext is written into
        // it. Writing decrypted plaintext into the shared sys_get_temp_dir()
        // with the default umask would otherwise leave it world-readable.
        $tempPath = tempnam(sys_get_temp_dir(), 'silo_dec_');
        if ($tempPath === false) {
            throw new Exception('Failed to create temporary file for decryption');
        }
        @chmod($tempPath, 0600);

        self::decryptFile($encryptedPath, $tempPath);
        @chmod($tempPath, 0600);

        // Open stream and register cleanup
        $stream = fopen($tempPath, 'rb');

        // Backstop cleanup: remove the temp file at shutdown. (The stream is
        // handed to the caller, so consumption cannot be detected here; the
        // 0600 permissions above prevent exposure while it lives.)
        register_shutdown_function(function () use ($tempPath) {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        });

        return $stream;
    }

    /**
     * Encrypt all files in storage (migration)
     */
    public static function encryptAllFiles(string $basePath, ?callable $progressCallback = null): array
    {
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
    public static function decryptAllFiles(string $basePath, ?callable $progressCallback = null): array
    {
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
    public static function rotateKey(string $newKey, string $basePath): array
    {
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
     *
     * 'key_source' names where the master key came from and
     * 'key_source_secure' is false when it came from the database it protects
     * (see the class docblock); 'key_source_warning' carries operator-facing
     * text for that case, or null.
     */
    public static function getStatus(): array
    {
        self::init();

        $source = self::$keySource ?? self::KEY_SOURCE_NONE;
        $insecure = ($source === self::KEY_SOURCE_DATABASE);

        return [
            'enabled' => self::isEnabled(),
            'cipher' => self::CIPHER,
            'key_configured' => self::$masterKey !== null,
            'key_valid' => self::$masterKey !== null && strlen(self::$masterKey) === self::KEY_LENGTH,
            'key_source' => $source,
            'key_source_secure' => ($source === self::KEY_SOURCE_ENV || $source === self::KEY_SOURCE_FILE),
            'key_source_warning' => $insecure
                ? 'INSECURE: the master key is stored in the same database as the encrypted data, '
                    . 'so a single database dump exposes both. Move it to the SILO_ENCRYPTION_KEY '
                    . 'environment variable or to storage/.encryption_key, then delete the '
                    . 'encryption_master_key setting.'
                : null,
            'openssl_version' => OPENSSL_VERSION_TEXT,
        ];
    }
}

/**
 * Encrypted storage wrapper
 * Drop-in replacement for file storage with transparent encryption
 */
class EncryptedStorage implements StorageInterface
{
    private StorageInterface $storage;
    private bool $encryptionEnabled;

    public function __construct(StorageInterface $storage)
    {
        $this->storage = $storage;
        $this->encryptionEnabled = Encryption::isEnabled() &&
            (function_exists('getSetting') ? getSetting('encryption_at_rest', '0') === '1' : false);
    }

    public function put($path, $content)
    {
        if ($this->encryptionEnabled) {
            $content = Encryption::encrypt($content, 'storage');
        }
        return $this->storage->put($path, $content);
    }

    public function putFile($path, $localFile)
    {
        if ($this->encryptionEnabled) {
            $tempPath = sys_get_temp_dir() . '/silo_enc_' . uniqid();
            Encryption::encryptFile($localFile, $tempPath);
            $result = $this->storage->putFile($path, $tempPath);
            unlink($tempPath);
            return $result;
        }
        return $this->storage->putFile($path, $localFile);
    }

    public function get($path)
    {
        $content = $this->storage->get($path);
        if ($content === null) {
            return null;
        }

        if (strlen($content) === 0) {
            return $content;
        }

        $version = ord($content[0]);

        // v1: single-block AES-256-GCM written by Encryption::encrypt() via put().
        if ($version === 1) {
            try {
                return Encryption::decrypt($content, 'storage');
            } catch (Exception $e) {
                // Decrypt failure must NOT leak ciphertext as plaintext.
                if (function_exists('logError')) {
                    logError('EncryptedStorage: failed to decrypt v1 content for ' . $path . ': ' . $e->getMessage());
                }
                return null;
            }
        }

        // v2: chunked AES-256-GCM file format written by Encryption::encryptFile()
        // via putFile(). It has no in-memory decryptor, so round-trip through
        // owner-only (0600) temp files.
        if ($version === 2) {
            $encTmp = tempnam(sys_get_temp_dir(), 'silo_encget_');
            $decTmp = tempnam(sys_get_temp_dir(), 'silo_decget_');
            try {
                if ($encTmp === false || $decTmp === false) {
                    throw new Exception('Failed to create temporary files for decryption');
                }
                @chmod($decTmp, 0600);
                file_put_contents($encTmp, $content);
                Encryption::decryptFile($encTmp, $decTmp);
                @chmod($decTmp, 0600);
                $plaintext = file_get_contents($decTmp);
                return $plaintext === false ? null : $plaintext;
            } catch (Exception $e) {
                if (function_exists('logError')) {
                    logError('EncryptedStorage: failed to decrypt v2 content for ' . $path . ': ' . $e->getMessage());
                }
                return null;
            } finally {
                if ($encTmp !== false && file_exists($encTmp)) {
                    @unlink($encTmp);
                }
                if ($decTmp !== false && file_exists($decTmp)) {
                    @unlink($decTmp);
                }
            }
        }

        // Not encrypted, return as-is.
        return $content;
    }

    public function delete($path)
    {
        return $this->storage->delete($path);
    }

    public function exists($path)
    {
        return $this->storage->exists($path);
    }

    public function url($path, $expiry = 3600)
    {
        // For encrypted files, we can't provide direct URLs
        // Need to serve through PHP
        if ($this->encryptionEnabled) {
            return route('actions.download', ['path' => $path]);
        }
        return $this->storage->url($path, $expiry);
    }

    public function size($path)
    {
        return $this->storage->size($path);
    }

    public function copy($from, $to)
    {
        return $this->storage->copy($from, $to);
    }

    public function move($from, $to)
    {
        return $this->storage->move($from, $to);
    }

    public function listFiles($prefix = '')
    {
        return $this->storage->listFiles($prefix);
    }
}
