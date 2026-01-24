<?php
/**
 * Asset Pipeline
 *
 * Provides asset management with:
 * - Cache busting via content hashing or version numbers
 * - CSS/JS minification (optional, no external dependencies)
 * - Asset combining (optional)
 * - Inline critical CSS support
 */

class Asset {
    private static ?self $instance = null;
    private string $basePath;
    private string $baseUrl;
    private string $cachePath;
    private bool $minify = false;
    private bool $versioning = true;
    private array $registered = ['css' => [], 'js' => []];
    private array $inline = ['css' => [], 'js' => []];
    private string $manifestFile;
    private array $manifest = [];

    /**
     * Get singleton instance
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->basePath = dirname(__DIR__) . '/';
        $this->baseUrl = '/';
        $this->cachePath = dirname(__DIR__) . '/storage/cache/assets/';
        $this->manifestFile = $this->cachePath . 'manifest.json';

        // Create cache directory
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }

        // Load manifest
        if (file_exists($this->manifestFile)) {
            $this->manifest = json_decode(file_get_contents($this->manifestFile), true) ?: [];
        }
    }

    /**
     * Configure the asset manager
     */
    public function configure(array $options): self {
        if (isset($options['base_path'])) {
            $this->basePath = rtrim($options['base_path'], '/') . '/';
        }
        if (isset($options['base_url'])) {
            $this->baseUrl = rtrim($options['base_url'], '/') . '/';
        }
        if (isset($options['cache_path'])) {
            $this->cachePath = rtrim($options['cache_path'], '/') . '/';
        }
        if (isset($options['minify'])) {
            $this->minify = (bool)$options['minify'];
        }
        if (isset($options['versioning'])) {
            $this->versioning = (bool)$options['versioning'];
        }
        return $this;
    }

    /**
     * Get versioned URL for an asset
     */
    public function url(string $path): string {
        // Remove leading slash
        $path = ltrim($path, '/');

        // Check manifest first
        if (isset($this->manifest[$path])) {
            return $this->baseUrl . $this->manifest[$path];
        }

        $fullPath = $this->basePath . $path;

        if (!$this->versioning || !file_exists($fullPath)) {
            return $this->baseUrl . $path;
        }

        // Generate version hash
        $hash = substr(md5_file($fullPath), 0, 8);

        // Add hash as query parameter
        $separator = strpos($path, '?') !== false ? '&' : '?';
        return $this->baseUrl . $path . $separator . 'v=' . $hash;
    }

    /**
     * Get versioned URL using file modification time
     */
    public function urlMtime(string $path): string {
        $path = ltrim($path, '/');
        $fullPath = $this->basePath . $path;

        if (!file_exists($fullPath)) {
            return $this->baseUrl . $path;
        }

        $mtime = filemtime($fullPath);
        $separator = strpos($path, '?') !== false ? '&' : '?';
        return $this->baseUrl . $path . $separator . 'v=' . $mtime;
    }

    /**
     * Register a CSS file
     */
    public function css(string $path, array $attributes = [], string $group = 'default'): self {
        $this->registered['css'][$group][] = [
            'path' => $path,
            'attributes' => $attributes
        ];
        return $this;
    }

    /**
     * Register a JS file
     */
    public function js(string $path, array $attributes = [], string $group = 'default'): self {
        $this->registered['js'][$group][] = [
            'path' => $path,
            'attributes' => $attributes
        ];
        return $this;
    }

    /**
     * Add inline CSS
     */
    public function inlineCss(string $css, string $group = 'default'): self {
        $this->inline['css'][$group][] = $css;
        return $this;
    }

    /**
     * Add inline JS
     */
    public function inlineJs(string $js, string $group = 'default'): self {
        $this->inline['js'][$group][] = $js;
        return $this;
    }

    /**
     * Render CSS tags
     */
    public function renderCss(string $group = 'default'): string {
        $html = '';

        // Render inline CSS first (critical CSS)
        if (!empty($this->inline['css'][$group])) {
            $html .= '<style>' . implode("\n", $this->inline['css'][$group]) . '</style>' . "\n";
        }

        // Render linked CSS
        if (!empty($this->registered['css'][$group])) {
            foreach ($this->registered['css'][$group] as $asset) {
                $url = $this->url($asset['path']);
                $attrs = $this->buildAttributes($asset['attributes']);
                $html .= '<link rel="stylesheet" href="' . htmlspecialchars($url) . '"' . $attrs . '>' . "\n";
            }
        }

        return $html;
    }

    /**
     * Render JS tags
     */
    public function renderJs(string $group = 'default'): string {
        $html = '';

        // Render linked JS
        if (!empty($this->registered['js'][$group])) {
            foreach ($this->registered['js'][$group] as $asset) {
                $url = $this->url($asset['path']);
                $attrs = $this->buildAttributes($asset['attributes']);
                $html .= '<script src="' . htmlspecialchars($url) . '"' . $attrs . '></script>' . "\n";
            }
        }

        // Render inline JS
        if (!empty($this->inline['js'][$group])) {
            $html .= '<script>' . implode("\n", $this->inline['js'][$group]) . '</script>' . "\n";
        }

        return $html;
    }

    /**
     * Build HTML attributes string
     */
    private function buildAttributes(array $attributes): string {
        $html = '';
        foreach ($attributes as $key => $value) {
            if ($value === true) {
                $html .= ' ' . htmlspecialchars($key);
            } elseif ($value !== false && $value !== null) {
                $html .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
            }
        }
        return $html;
    }

    /**
     * Minify CSS
     */
    public function minifyCss(string $css): string {
        if (!$this->minify) return $css;

        // Remove comments
        $css = preg_replace('/\/\*[\s\S]*?\*\//', '', $css);

        // Remove whitespace
        $css = preg_replace('/\s+/', ' ', $css);

        // Remove spaces around special characters
        $css = preg_replace('/\s*([{};:,>+~])\s*/', '$1', $css);

        // Remove trailing semicolons before closing braces
        $css = str_replace(';}', '}', $css);

        // Remove leading/trailing whitespace
        $css = trim($css);

        return $css;
    }

    /**
     * Minify JS (basic - removes comments and extra whitespace)
     */
    public function minifyJs(string $js): string {
        if (!$this->minify) return $js;

        // Remove single-line comments (but not URLs)
        $js = preg_replace('#(?<!:)//(?!/).*$#m', '', $js);

        // Remove multi-line comments
        $js = preg_replace('/\/\*[\s\S]*?\*\//', '', $js);

        // Normalize whitespace
        $js = preg_replace('/\s+/', ' ', $js);

        // Remove spaces around operators (careful with edge cases)
        $js = preg_replace('/\s*([{};:,=\+\-\*\/\(\)\[\]<>!&\|])\s*/', '$1', $js);

        // Restore spaces after keywords
        $js = preg_replace('/\b(return|var|let|const|if|else|for|while|function|new|typeof|instanceof)\b/', ' $1 ', $js);

        return trim($js);
    }

    /**
     * Combine multiple CSS files
     */
    public function combineCss(array $files, string $output): bool {
        $combined = '';
        foreach ($files as $file) {
            $path = $this->basePath . ltrim($file, '/');
            if (file_exists($path)) {
                $content = file_get_contents($path);

                // Rewrite relative URLs
                $dir = dirname($file);
                $content = preg_replace_callback(
                    '/url\([\'"]?(?!data:|https?:|\/)([^\'")]+)[\'"]?\)/',
                    function($matches) use ($dir) {
                        return 'url(' . $dir . '/' . $matches[1] . ')';
                    },
                    $content
                );

                $combined .= "/* Source: $file */\n" . $content . "\n";
            }
        }

        if ($this->minify) {
            $combined = $this->minifyCss($combined);
        }

        $outputPath = $this->cachePath . $output;
        $result = file_put_contents($outputPath, $combined);

        // Update manifest
        if ($result !== false) {
            $hash = substr(md5($combined), 0, 8);
            $this->manifest[ltrim($output, '/')] = $output . '?v=' . $hash;
            $this->saveManifest();
        }

        return $result !== false;
    }

    /**
     * Combine multiple JS files
     */
    public function combineJs(array $files, string $output): bool {
        $combined = '';
        foreach ($files as $file) {
            $path = $this->basePath . ltrim($file, '/');
            if (file_exists($path)) {
                $content = file_get_contents($path);
                $combined .= "/* Source: $file */\n" . $content . ";\n";
            }
        }

        if ($this->minify) {
            $combined = $this->minifyJs($combined);
        }

        $outputPath = $this->cachePath . $output;
        $result = file_put_contents($outputPath, $combined);

        // Update manifest
        if ($result !== false) {
            $hash = substr(md5($combined), 0, 8);
            $this->manifest[ltrim($output, '/')] = $output . '?v=' . $hash;
            $this->saveManifest();
        }

        return $result !== false;
    }

    /**
     * Save manifest file
     */
    private function saveManifest(): void {
        file_put_contents($this->manifestFile, json_encode($this->manifest, JSON_PRETTY_PRINT));
    }

    /**
     * Clear asset cache
     */
    public function clearCache(): bool {
        $files = glob($this->cachePath . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->manifest = [];
        return true;
    }

    /**
     * Preload a critical asset
     */
    public function preload(string $path, string $as = 'style'): string {
        $url = $this->url($path);
        return '<link rel="preload" href="' . htmlspecialchars($url) . '" as="' . htmlspecialchars($as) . '">';
    }

    /**
     * Generate integrity hash for subresource integrity
     */
    public function integrity(string $path): ?string {
        $fullPath = $this->basePath . ltrim($path, '/');
        if (!file_exists($fullPath)) {
            return null;
        }
        $content = file_get_contents($fullPath);
        return 'sha384-' . base64_encode(hash('sha384', $content, true));
    }

    /**
     * Generate a data URI for small assets
     */
    public function dataUri(string $path): ?string {
        $fullPath = $this->basePath . ltrim($path, '/');
        if (!file_exists($fullPath)) {
            return null;
        }

        $content = file_get_contents($fullPath);
        $mime = mime_content_type($fullPath);

        return 'data:' . $mime . ';base64,' . base64_encode($content);
    }

    /**
     * Check if asset exists
     */
    public function exists(string $path): bool {
        return file_exists($this->basePath . ltrim($path, '/'));
    }

    /**
     * Get asset file size
     */
    public function size(string $path): ?int {
        $fullPath = $this->basePath . ltrim($path, '/');
        return file_exists($fullPath) ? filesize($fullPath) : null;
    }
}

// ========================================
// Helper Functions
// ========================================

/**
 * Get asset URL with versioning
 */
if (!function_exists('asset')) {
    function asset(string $path): string {
        return Asset::getInstance()->url($path);
    }
}

/**
 * Get asset URL with mtime versioning
 */
if (!function_exists('asset_mtime')) {
    function asset_mtime(string $path): string {
        return Asset::getInstance()->urlMtime($path);
    }
}

/**
 * Register and render CSS
 */
if (!function_exists('asset_css')) {
    function asset_css(string $path, array $attributes = []): string {
        $url = Asset::getInstance()->url($path);
        $attrs = '';
        foreach ($attributes as $key => $value) {
            $attrs .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
        }
        return '<link rel="stylesheet" href="' . htmlspecialchars($url) . '"' . $attrs . '>';
    }
}

/**
 * Register and render JS
 */
if (!function_exists('asset_js')) {
    function asset_js(string $path, array $attributes = []): string {
        $url = Asset::getInstance()->url($path);
        $attrs = '';
        foreach ($attributes as $key => $value) {
            if ($value === true) {
                $attrs .= ' ' . htmlspecialchars($key);
            } else {
                $attrs .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
            }
        }
        return '<script src="' . htmlspecialchars($url) . '"' . $attrs . '></script>';
    }
}
