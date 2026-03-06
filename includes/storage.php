<?php

/**
 * Storage Abstraction Layer
 * Supports local filesystem and S3-compatible object storage
 */

interface StorageInterface
{
    public function put($path, $content);
    public function putFile($path, $localFile);
    public function get($path);
    public function delete($path);
    public function exists($path);
    public function url($path, $expiry = 3600);
    public function size($path);
    public function copy($from, $to);
    public function move($from, $to);
    public function listFiles($prefix = '');
}

/**
 * Local Filesystem Storage
 */
class LocalStorage implements StorageInterface
{
    private $basePath;
    private $baseUrl;

    public function __construct($basePath, $baseUrl = '')
    {
        $this->basePath = rtrim($basePath, '/');
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function put($path, $content)
    {
        $fullPath = $this->fullPath($path);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return file_put_contents($fullPath, $content) !== false;
    }

    public function putFile($path, $localFile)
    {
        $fullPath = $this->fullPath($path);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return copy($localFile, $fullPath);
    }

    public function get($path)
    {
        $fullPath = $this->fullPath($path);
        return file_exists($fullPath) ? file_get_contents($fullPath) : null;
    }

    public function delete($path)
    {
        $fullPath = $this->fullPath($path);
        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }
        return true;
    }

    public function exists($path)
    {
        return file_exists($this->fullPath($path));
    }

    public function url($path, $expiry = 3600)
    {
        // Local storage returns direct URL
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    public function size($path)
    {
        $fullPath = $this->fullPath($path);
        return file_exists($fullPath) ? filesize($fullPath) : 0;
    }

    public function copy($from, $to)
    {
        return copy($this->fullPath($from), $this->fullPath($to));
    }

    public function move($from, $to)
    {
        return rename($this->fullPath($from), $this->fullPath($to));
    }

    public function listFiles($prefix = '')
    {
        $files = [];
        $dir = $this->fullPath($prefix);
        if (is_dir($dir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = str_replace($this->basePath . '/', '', $file->getPathname());
                }
            }
        }
        return $files;
    }

    private function fullPath($path)
    {
        return $this->basePath . '/' . ltrim($path, '/');
    }

    public function getBasePath()
    {
        return $this->basePath;
    }
}

/**
 * S3-Compatible Object Storage
 */
class S3Storage implements StorageInterface
{
    private $endpoint;
    private $bucket;
    private $accessKey;
    private $secretKey;
    private $usePathStyle;
    private $publicUrl;

    public function __construct($config)
    {
        $this->endpoint = rtrim($config['endpoint'] ?? '', '/');
        $this->bucket = $config['bucket'] ?? '';
        $this->accessKey = $config['access_key'] ?? '';
        $this->secretKey = $config['secret_key'] ?? '';
        $this->usePathStyle = $config['use_path_style'] ?? false;
        $this->publicUrl = rtrim($config['public_url'] ?? '', '/');
    }

    public function put($path, $content)
    {
        return $this->putObject($path, $content, 'application/octet-stream');
    }

    public function putFile($path, $localFile)
    {
        $content = file_get_contents($localFile);
        $contentType = mime_content_type($localFile) ?: 'application/octet-stream';
        return $this->putObject($path, $content, $contentType);
    }

    public function get($path)
    {
        $response = $this->request('GET', $path);
        return $response['success'] ? $response['body'] : null;
    }

    public function delete($path)
    {
        $response = $this->request('DELETE', $path);
        return $response['success'] || $response['http_code'] === 404;
    }

    public function exists($path)
    {
        $response = $this->request('HEAD', $path);
        return $response['http_code'] === 200;
    }

    public function url($path, $expiry = 3600)
    {
        // If public URL is configured, return it
        if ($this->publicUrl) {
            return $this->publicUrl . '/' . ltrim($path, '/');
        }

        // Generate pre-signed URL
        return $this->getPresignedUrl($path, $expiry);
    }

    public function size($path)
    {
        $response = $this->request('HEAD', $path);
        if ($response['success'] && isset($response['headers']['content-length'])) {
            return (int)$response['headers']['content-length'];
        }
        return 0;
    }

    public function copy($from, $to)
    {
        $content = $this->get($from);
        if ($content === null) {
            return false;
        }
        return $this->put($to, $content);
    }

    public function move($from, $to)
    {
        if ($this->copy($from, $to)) {
            return $this->delete($from);
        }
        return false;
    }

    public function listFiles($prefix = '')
    {
        $files = [];
        $marker = '';

        do {
            $query = ['prefix' => $prefix, 'max-keys' => 1000];
            if ($marker) {
                $query['marker'] = $marker;
            }

            $response = $this->request('GET', '', $query);
            if (!$response['success']) {
                break;
            }

            $xml = simplexml_load_string($response['body']);
            if (!$xml) {
                break;
            }

            foreach ($xml->Contents as $item) {
                $files[] = (string)$item->Key;
                $marker = (string)$item->Key;
            }

            $isTruncated = (string)$xml->IsTruncated === 'true';
        } while ($isTruncated);

        return $files;
    }

    private function putObject($path, $content, $contentType)
    {
        $response = $this->request('PUT', $path, [], $content, [
            'Content-Type' => $contentType,
            'Content-Length' => strlen($content)
        ]);
        return $response['success'];
    }

    private function request($method, $path, $query = [], $body = '', $extraHeaders = [])
    {
        $path = '/' . ltrim($path, '/');
        $date = gmdate('D, d M Y H:i:s T');

        // Build URL
        if ($this->usePathStyle) {
            $host = parse_url($this->endpoint, PHP_URL_HOST);
            $url = $this->endpoint . '/' . $this->bucket . $path;
        } else {
            $host = $this->bucket . '.' . parse_url($this->endpoint, PHP_URL_HOST);
            $url = str_replace('://', '://' . $this->bucket . '.', $this->endpoint) . $path;
        }

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        // Prepare headers
        $contentMd5 = '';
        $contentType = $extraHeaders['Content-Type'] ?? '';

        // Build string to sign (AWS Signature V2)
        $canonicalizedResource = '/' . $this->bucket . $path;
        $stringToSign = "$method\n$contentMd5\n$contentType\n$date\n$canonicalizedResource";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));

        $headers = array_merge([
            'Date' => $date,
            'Authorization' => "AWS {$this->accessKey}:$signature",
            'Host' => $host
        ], $extraHeaders);

        // Make request
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => array_map(function ($k, $v) {
                return "$k: $v";
            }, array_keys($headers), $headers),
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 30
        ]);

        if ($body) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $responseHeaders = $this->parseHeaders(substr($response, 0, $headerSize));
        $responseBody = substr($response, $headerSize);

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'headers' => $responseHeaders,
            'body' => $responseBody
        ];
    }

    private function parseHeaders($headerString)
    {
        $headers = [];
        foreach (explode("\r\n", $headerString) as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        return $headers;
    }

    private function getPresignedUrl($path, $expiry)
    {
        $expires = time() + $expiry;
        $path = '/' . ltrim($path, '/');

        $canonicalizedResource = '/' . $this->bucket . $path;
        $stringToSign = "GET\n\n\n$expires\n$canonicalizedResource";
        $signature = urlencode(base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true)));

        if ($this->usePathStyle) {
            $url = $this->endpoint . '/' . $this->bucket . $path;
        } else {
            $url = str_replace('://', '://' . $this->bucket . '.', $this->endpoint) . $path;
        }

        return $url . "?AWSAccessKeyId={$this->accessKey}&Expires=$expires&Signature=$signature";
    }
}

/**
 * Get the storage instance based on configuration
 */
function getStorage()
{
    static $storage = null;

    if ($storage === null) {
        $storageType = getSetting('storage_type', 'local');

        if ($storageType === 's3') {
            $storage = new S3Storage([
                'endpoint' => getSetting('s3_endpoint', ''),
                'bucket' => getSetting('s3_bucket', ''),
                'access_key' => getSetting('s3_access_key', ''),
                'secret_key' => getSetting('s3_secret_key', ''),
                'region' => getSetting('s3_region', 'us-east-1'),
                'use_path_style' => getSetting('s3_path_style', '0') === '1',
                'public_url' => getSetting('s3_public_url', '')
            ]);
        } else {
            $siteUrl = getSetting('site_url', '');
            $storage = new LocalStorage(UPLOAD_PATH, $siteUrl . '/assets');
        }
    }

    return $storage;
}

/**
 * Store a file in the configured storage
 */
function storeFile($path, $localFile)
{
    return getStorage()->putFile($path, $localFile);
}

/**
 * Get a file from storage
 */
function getFile($path)
{
    return getStorage()->get($path);
}

/**
 * Delete a file from storage
 */
function deleteFile($path)
{
    return getStorage()->delete($path);
}

/**
 * Check if file exists in storage
 */
function fileExists($path)
{
    return getStorage()->exists($path);
}

/**
 * Get URL for a file (may be pre-signed for S3)
 */
function getFileUrl($path, $expiry = 3600)
{
    return getStorage()->url($path, $expiry);
}

/**
 * Migrate files from local to S3 storage
 */
function migrateToS3($progressCallback = null)
{
    $localStorage = new LocalStorage(UPLOAD_PATH);
    $s3Storage = new S3Storage([
        'endpoint' => getSetting('s3_endpoint', ''),
        'bucket' => getSetting('s3_bucket', ''),
        'access_key' => getSetting('s3_access_key', ''),
        'secret_key' => getSetting('s3_secret_key', ''),
        'region' => getSetting('s3_region', 'us-east-1'),
        'use_path_style' => getSetting('s3_path_style', '0') === '1',
        'public_url' => getSetting('s3_public_url', '')
    ]);

    $files = $localStorage->listFiles();
    $total = count($files);
    $migrated = 0;
    $failed = 0;
    $errors = [];

    foreach ($files as $file) {
        try {
            $content = $localStorage->get($file);
            if ($content !== null && $s3Storage->put($file, $content)) {
                $migrated++;
            } else {
                $failed++;
                $errors[] = "Failed to migrate: $file";
            }
        } catch (Exception $e) {
            $failed++;
            $errors[] = "Error migrating $file: " . $e->getMessage();
        }

        if ($progressCallback) {
            $progressCallback($migrated + $failed, $total, $file);
        }
    }

    return [
        'total' => $total,
        'migrated' => $migrated,
        'failed' => $failed,
        'errors' => $errors
    ];
}

/**
 * Test S3 connection
 */
function testS3Connection($config)
{
    try {
        $s3 = new S3Storage($config);

        // Try to list files (empty prefix) to test connection
        $testPath = '.silo-test-' . uniqid();
        $testContent = 'Silo S3 connection test';

        // Write test file
        if (!$s3->put($testPath, $testContent)) {
            return ['success' => false, 'error' => 'Failed to write test file'];
        }

        // Read test file
        $readContent = $s3->get($testPath);
        if ($readContent !== $testContent) {
            return ['success' => false, 'error' => 'Failed to read test file'];
        }

        // Delete test file
        $s3->delete($testPath);

        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
