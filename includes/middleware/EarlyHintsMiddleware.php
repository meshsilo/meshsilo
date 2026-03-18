<?php

/**
 * Early Hints Middleware (HTTP 103)
 *
 * Sends preload hints to the browser before the main response is ready.
 * Benefits:
 * - Browser starts loading assets 50-100ms sooner
 * - Especially useful for pages with database queries
 * - Works with HTTP/2 and HTTP/3
 *
 * Note: Requires nginx proxy_early_hints on; or Apache with mod_http2
 */

class EarlyHintsMiddleware
{
    private array $hints = [];
    private bool $enabled = true;

    /**
     * Add a preload hint
     */
    public function preload(string $url, string $as, array $options = []): self
    {
        $hint = [
            'url' => $url,
            'as' => $as,
            'crossorigin' => $options['crossorigin'] ?? null,
            'type' => $options['type'] ?? null,
        ];

        $this->hints[] = $hint;
        return $this;
    }

    /**
     * Add a preconnect hint
     */
    public function preconnect(string $origin, bool $crossorigin = true): self
    {
        $this->hints[] = [
            'url' => $origin,
            'rel' => 'preconnect',
            'crossorigin' => $crossorigin,
        ];
        return $this;
    }

    /**
     * Send early hints
     */
    public function send(): void
    {
        if (!$this->enabled || empty($this->hints) || headers_sent()) {
            return;
        }

        // Check if we can send 103 status
        if (!$this->canSendEarlyHints()) {
            // Fall back to regular Link headers
            $this->sendAsLinkHeaders();
            return;
        }

        // Build Link header value
        $links = [];
        foreach ($this->hints as $hint) {
            $links[] = $this->buildLinkValue($hint);
        }

        // Send 103 Early Hints
        http_response_code(103);
        header('Link: ' . implode(', ', $links));

        // Flush to send immediately
        if (function_exists('fastcgi_finish_request')) {
            // This doesn't work for early hints, but included for completeness
        } else {
            flush();
        }
    }

    /**
     * Send hints as regular Link headers (fallback)
     */
    private function sendAsLinkHeaders(): void
    {
        foreach ($this->hints as $hint) {
            header('Link: ' . $this->buildLinkValue($hint), false);
        }
    }

    /**
     * Build a Link header value
     */
    private function buildLinkValue(array $hint): string
    {
        $parts = ['<' . $hint['url'] . '>'];

        if (isset($hint['rel'])) {
            $parts[] = 'rel=' . $hint['rel'];
        } else {
            $parts[] = 'rel=preload';
        }

        if (isset($hint['as'])) {
            $parts[] = 'as=' . $hint['as'];
        }

        if (!empty($hint['crossorigin'])) {
            $parts[] = 'crossorigin';
        }

        if (!empty($hint['type'])) {
            $parts[] = 'type="' . $hint['type'] . '"';
        }

        return implode('; ', $parts);
    }

    /**
     * Check if early hints can be sent
     */
    private function canSendEarlyHints(): bool
    {
        // Check for HTTP/2 or HTTP/3
        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? '';
        if (strpos($protocol, 'HTTP/2') === false && strpos($protocol, 'HTTP/3') === false) {
            return false;
        }

        // Check if running under a compatible server
        $server = $_SERVER['SERVER_SOFTWARE'] ?? '';
        if (strpos($server, 'nginx') !== false || strpos($server, 'Apache') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Get default hints for MeshSilo pages
     */
    public static function getDefaultHints(bool $needsViewer = false, bool $isAdmin = false): self
    {
        $middleware = new self();

        // Preconnect to CDNs
        $middleware->preconnect('https://cdnjs.cloudflare.com');
        $middleware->preconnect('https://cdn.jsdelivr.net');

        // Preload critical CSS
        $middleware->preload('/public/css/base.css', 'style');
        $middleware->preload('/public/css/layout.css', 'style');
        $middleware->preload('/public/css/components.css', 'style');
        $middleware->preload('/public/css/pages.css', 'style');
        if ($isAdmin) {
            $middleware->preload('/public/css/admin.css', 'style');
        }

        // Preload main JS
        $middleware->preload('/public/js/main.js', 'script');

        // Preload viewer if needed
        if ($needsViewer) {
            $middleware->preload('/public/js/viewer.js', 'script');
            $middleware->preload('https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js', 'script', ['crossorigin' => true]);
        }

        return $middleware;
    }
}

/**
 * Send early hints for the current page
 */
function sendEarlyHints(bool $needsViewer = false, bool $isAdmin = false): void
{
    EarlyHintsMiddleware::getDefaultHints($needsViewer, $isAdmin)->send();
}
