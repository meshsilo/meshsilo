<?php

/**
 * HTTP Caching Helper
 *
 * Provides HTTP caching headers for optimal browser caching:
 * - Cache-Control headers
 * - ETag support
 * - Last-Modified headers
 * - Conditional request handling (304 responses)
 */

class HttpCache
{
    /**
     * Set cache headers for static content
     */
    public static function staticContent(int $maxAge = 86400, bool $public = true): void
    {
        $visibility = $public ? 'public' : 'private';
        header("Cache-Control: {$visibility}, max-age={$maxAge}");
        header('Vary: Accept-Encoding');
    }

    /**
     * Set cache headers for dynamic content that can be cached
     */
    public static function dynamic(int $maxAge = 300, bool $public = false): void
    {
        $visibility = $public ? 'public' : 'private';
        header("Cache-Control: {$visibility}, max-age={$maxAge}, must-revalidate");
        header('Vary: Accept-Encoding, Cookie');
    }

    /**
     * Disable caching entirely
     */
    public static function noCache(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    }

    /**
     * Set cache for immutable content (versioned assets)
     */
    public static function immutable(int $maxAge = 31536000): void
    {
        header("Cache-Control: public, max-age={$maxAge}, immutable");
    }

    /**
     * Set ETag header and check for conditional request
     * Returns true if client cache is valid (304 should be sent)
     */
    public static function etag(string $content, bool $weak = false): bool
    {
        $hash = md5($content);
        $etag = $weak ? 'W/"' . $hash . '"' : '"' . $hash . '"';

        header('ETag: ' . $etag);

        // Check If-None-Match header
        $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($clientEtag === $etag) {
            return true;
        }

        return false;
    }

    /**
     * Set ETag from file and check conditional request
     */
    public static function etagFile(string $filepath): bool
    {
        if (!file_exists($filepath)) {
            return false;
        }

        $stat = stat($filepath);
        $etag = '"' . $stat['ino'] . '-' . $stat['size'] . '-' . $stat['mtime'] . '"';

        header('ETag: ' . $etag);

        $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($clientEtag === $etag) {
            return true;
        }

        return false;
    }

    /**
     * Set Last-Modified header and check conditional request
     * Returns true if client cache is valid (304 should be sent)
     */
    public static function lastModified(int $timestamp): bool
    {
        $lastModified = gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
        header('Last-Modified: ' . $lastModified);

        // Check If-Modified-Since header
        $clientModified = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
        if ($clientModified) {
            $clientTime = strtotime($clientModified);
            if ($clientTime >= $timestamp) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set Last-Modified from file
     */
    public static function lastModifiedFile(string $filepath): bool
    {
        if (!file_exists($filepath)) {
            return false;
        }

        return self::lastModified(filemtime($filepath));
    }

    /**
     * Send 304 Not Modified response
     */
    public static function notModified(): void
    {
        http_response_code(304);
        // Remove content-related headers
        header_remove('Content-Type');
        header_remove('Content-Length');
        exit;
    }

    /**
     * Handle caching for a file (complete workflow)
     */
    public static function serveFile(string $filepath, int $maxAge = 86400): void
    {
        if (!file_exists($filepath)) {
            http_response_code(404);
            exit;
        }

        // Set cache headers
        self::staticContent($maxAge);

        // Check conditional requests
        if (self::etagFile($filepath) || self::lastModifiedFile($filepath)) {
            self::notModified();
        }

        // Set content type
        $mimeType = mime_content_type($filepath);
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($filepath));

        // Output file
        readfile($filepath);
        exit;
    }

    /**
     * Handle caching for dynamic content (complete workflow)
     */
    public static function serveDynamic(string $content, int $maxAge = 300, ?string $contentType = null): void
    {
        // Check ETag
        if (self::etag($content)) {
            self::notModified();
        }

        // Set headers
        self::dynamic($maxAge);

        if ($contentType) {
            header('Content-Type: ' . $contentType);
        }

        header('Content-Length: ' . strlen($content));

        echo $content;
        exit;
    }

    /**
     * Set Expires header (for older HTTP/1.0 clients)
     */
    public static function expires(int $seconds): void
    {
        $expires = gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT';
        header('Expires: ' . $expires);
    }

    /**
     * Set caching for API responses
     */
    public static function api(int $maxAge = 60, bool $public = false): void
    {
        $visibility = $public ? 'public' : 'private';
        header("Cache-Control: {$visibility}, max-age={$maxAge}");
        header('Vary: Accept, Accept-Encoding, Authorization');
    }

    /**
     * Set stale-while-revalidate for better UX
     */
    public static function staleWhileRevalidate(int $maxAge = 300, int $staleAge = 86400): void
    {
        header("Cache-Control: public, max-age={$maxAge}, stale-while-revalidate={$staleAge}");
    }

    /**
     * Get cache-safe headers for thumbnails/images
     */
    public static function thumbnail(int $maxAge = 604800): void
    {
        self::staticContent($maxAge, true);
        header('Accept-Ranges: bytes');
    }

    /**
     * Set download headers with caching
     */
    public static function download(string $filename, int $size, int $maxAge = 3600): void
    {
        self::staticContent($maxAge);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $size);
        header('Accept-Ranges: bytes');
    }

    /**
     * Handle range requests for large files
     */
    public static function rangeRequest(string $filepath): void
    {
        if (!file_exists($filepath)) {
            http_response_code(404);
            exit;
        }

        $size = filesize($filepath);
        $mimeType = mime_content_type($filepath);

        // Check for range request
        if (isset($_SERVER['HTTP_RANGE'])) {
            self::handleRangeRequest($filepath, $size, $mimeType);
        } else {
            // Full file
            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . $size);
            header('Accept-Ranges: bytes');
            readfile($filepath);
        }
        exit;
    }

    /**
     * Handle byte range request
     */
    private static function handleRangeRequest(string $filepath, int $size, string $mimeType): void
    {
        $range = $_SERVER['HTTP_RANGE'];

        // Parse range header
        if (!preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
            http_response_code(416); // Range Not Satisfiable
            header('Content-Range: bytes */' . $size);
            exit;
        }

        $start = $matches[1] !== '' ? (int)$matches[1] : 0;
        $end = $matches[2] !== '' ? (int)$matches[2] : $size - 1;

        // Validate range
        if ($start > $end || $end >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }

        $length = $end - $start + 1;

        http_response_code(206); // Partial Content
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $length);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Accept-Ranges: bytes');

        // Output range
        $fp = fopen($filepath, 'rb');
        fseek($fp, $start);
        echo fread($fp, $length);
        fclose($fp);
    }
}
