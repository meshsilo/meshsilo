<?php

/**
 * CSS Purger
 *
 * Removes unused CSS rules by analyzing PHP templates.
 * Benefits:
 * - Can reduce CSS size by 60-80%
 * - Faster page loads
 * - Lower bandwidth usage
 *
 * Usage:
 *   php cli/purge-css.php --analyze    Show unused CSS
 *   php cli/purge-css.php --purge      Generate purged CSS
 */

class CssPurger
{
    private array $usedSelectors = [];
    private array $allSelectors = [];
    private array $cssPaths;
    private string $outputPath;
    private array $safelist = [];

    public function __construct()
    {
        $basePath = dirname(__DIR__);
        $this->cssPaths = [
            $basePath . '/public/css/base.css',
            $basePath . '/public/css/layout.css',
            $basePath . '/public/css/components.css',
            $basePath . '/public/css/pages.css',
            $basePath . '/public/css/admin.css',
        ];
        $this->outputPath = $basePath . '/public/css/style.purged.css';

        // Selectors that should never be purged (dynamic classes, JS-added classes)
        $this->safelist = [
            // Theme classes
            '/\[data-theme/',
            '/\.dark/',
            '/\.light/',

            // Dynamic states
            '/\.active/',
            '/\.open/',
            '/\.loading/',
            '/\.loaded/',
            '/\.error/',
            '/\.success/',
            '/\.visible/',
            '/\.hidden/',
            '/\.disabled/',
            '/\.selected/',
            '/\.expanded/',
            '/\.collapsed/',

            // JavaScript-added classes
            '/\.mobile-open/',
            '/\.mobile-menu-open/',
            '/\.dropdown-open/',
            '/\.modal-open/',
            '/\.is-/',
            '/\.has-/',
            '/\.no-/',

            // Animation classes
            '/\.fade/',
            '/\.slide/',
            '/\.shimmer/',
            '/\.spin/',

            // Responsive utilities
            '/@media/',

            // Pseudo selectors (keep all)
            '/:hover/',
            '/:focus/',
            '/:active/',
            '/:visited/',
            '/:first/',
            '/:last/',
            '/:nth/',
            '/:before/',
            '/:after/',
            '/::/',
        ];
    }

    /**
     * Analyze templates and find used selectors
     */
    public function analyze(): array
    {
        $basePath = dirname(__DIR__);

        // Directories to scan for templates
        $templateDirs = [
            $basePath . '/app/pages',
            $basePath . '/app/admin',
            $basePath . '/app/actions',
            $basePath . '/includes',
            $basePath . '/public/js',
        ];

        $this->usedSelectors = [];

        foreach ($templateDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $this->scanDirectory($dir);
        }

        // Parse CSS to get all selectors
        $this->allSelectors = $this->parseCssSelectors();

        // Find unused selectors
        $unused = [];
        foreach ($this->allSelectors as $selector) {
            if (!$this->isSelectorUsed($selector) && !$this->isInSafelist($selector)) {
                $unused[] = $selector;
            }
        }

        return [
            'total_selectors' => count($this->allSelectors),
            'used_selectors' => count($this->allSelectors) - count($unused),
            'unused_selectors' => count($unused),
            'unused_list' => $unused,
            'potential_savings' => $this->estimateSavings($unused),
        ];
    }

    /**
     * Generate purged CSS
     */
    public function purge(): array
    {
        $analysis = $this->analyze();

        $css = $this->readAllCss();
        $originalSize = strlen($css);

        // Remove unused rules
        $purgedCss = $this->removeUnusedRules($css, $analysis['unused_list']);

        // Minify the result
        $purgedCss = $this->minify($purgedCss);

        $purgedSize = strlen($purgedCss);

        // Save purged CSS
        file_put_contents($this->outputPath, $purgedCss);

        return [
            'original_size' => $originalSize,
            'purged_size' => $purgedSize,
            'savings' => $originalSize - $purgedSize,
            'savings_percent' => round((1 - $purgedSize / $originalSize) * 100, 1),
            'output_file' => $this->outputPath,
        ];
    }

    /**
     * Scan directory for templates
     */
    private function scanDirectory(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $ext = $file->getExtension();
            if (!in_array($ext, ['php', 'html', 'js'])) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            $this->extractSelectors($content);
        }
    }

    /**
     * Extract potential CSS selectors from content
     */
    private function extractSelectors(string $content): void
    {
        // Extract class names from class="..." attributes
        preg_match_all('/class\s*=\s*["\']([^"\']+)["\']/', $content, $matches);
        foreach ($matches[1] as $classString) {
            $classes = preg_split('/\s+/', $classString);
            foreach ($classes as $class) {
                $class = trim($class);
                if ($class && !preg_match('/^</', $class)) {
                    $this->usedSelectors['.' . $class] = true;
                }
            }
        }

        // Extract IDs from id="..." attributes
        preg_match_all('/id\s*=\s*["\']([^"\']+)["\']/', $content, $matches);
        foreach ($matches[1] as $id) {
            $this->usedSelectors['#' . trim($id)] = true;
        }

        // Extract element names
        preg_match_all('/<([a-z][a-z0-9]*)/i', $content, $matches);
        foreach ($matches[1] as $tag) {
            $this->usedSelectors[strtolower($tag)] = true;
        }

        // Extract classes from JavaScript (classList.add, addClass, etc.)
        preg_match_all('/classList\.(?:add|remove|toggle)\s*\(\s*["\']([^"\']+)["\']/', $content, $matches);
        foreach ($matches[1] as $class) {
            $this->usedSelectors['.' . trim($class)] = true;
        }

        // Extract classes from jQuery-style addClass
        preg_match_all('/\.addClass\s*\(\s*["\']([^"\']+)["\']/', $content, $matches);
        foreach ($matches[1] as $classString) {
            $classes = preg_split('/\s+/', $classString);
            foreach ($classes as $class) {
                $this->usedSelectors['.' . trim($class)] = true;
            }
        }
    }

    /**
     * Parse CSS and extract all selectors
     */
    private function readAllCss(): string
    {
        $parts = [];
        foreach ($this->cssPaths as $path) {
            if (file_exists($path)) {
                $parts[] = file_get_contents($path);
            }
        }
        return implode("\n", $parts);
    }

    private function parseCssSelectors(): array
    {
        $css = $this->readAllCss();
        $selectors = [];

        // Remove comments
        $css = preg_replace('/\/\*[\s\S]*?\*\//', '', $css);

        // Match selectors before { }
        preg_match_all('/([^{}]+)\s*\{[^{}]*\}/', $css, $matches);

        foreach ($matches[1] as $selectorGroup) {
            // Split multiple selectors
            $parts = explode(',', $selectorGroup);
            foreach ($parts as $selector) {
                $selector = trim($selector);
                if ($selector && !preg_match('/^@/', $selector)) {
                    $selectors[] = $selector;
                }
            }
        }

        return array_unique($selectors);
    }

    /**
     * Check if selector is used
     */
    private function isSelectorUsed(string $selector): bool
    {
        // Extract the main class/id/element from the selector
        $parts = preg_split('/[\s>+~\[:]+/', $selector);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            // Check for class
            if (preg_match('/^\.([a-zA-Z_-][a-zA-Z0-9_-]*)/', $part, $m)) {
                if (isset($this->usedSelectors['.' . $m[1]])) {
                    return true;
                }
            }

            // Check for ID
            if (preg_match('/^#([a-zA-Z_-][a-zA-Z0-9_-]*)/', $part, $m)) {
                if (isset($this->usedSelectors['#' . $m[1]])) {
                    return true;
                }
            }

            // Check for element
            if (preg_match('/^([a-z][a-z0-9]*)$/i', $part)) {
                if (isset($this->usedSelectors[strtolower($part)])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if selector is in safelist
     */
    private function isInSafelist(string $selector): bool
    {
        foreach ($this->safelist as $pattern) {
            if (preg_match($pattern, $selector)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove unused CSS rules
     */
    private function removeUnusedRules(string $css, array $unusedSelectors): string
    {
        foreach ($unusedSelectors as $selector) {
            // Escape special regex characters
            $escaped = preg_quote($selector, '/');

            // Remove the rule (selector and its block)
            $pattern = '/' . $escaped . '\s*\{[^{}]*\}/s';
            $css = preg_replace($pattern, '', $css);
        }

        // Clean up empty media queries
        $css = preg_replace('/@media[^{]+\{\s*\}/', '', $css);

        return $css;
    }

    /**
     * Minify CSS
     */
    private function minify(string $css): string
    {
        // Remove comments
        $css = preg_replace('/\/\*[\s\S]*?\*\//', '', $css);

        // Remove whitespace
        $css = preg_replace('/\s+/', ' ', $css);

        // Remove spaces around special characters
        $css = preg_replace('/\s*([{};:,>+~])\s*/', '$1', $css);

        // Remove trailing semicolons
        $css = str_replace(';}', '}', $css);

        // Remove empty rules
        $css = preg_replace('/[^{}]+\{\s*\}/', '', $css);

        return trim($css);
    }

    /**
     * Estimate size savings
     */
    private function estimateSavings(array $unusedSelectors): string
    {
        $css = $this->readAllCss();
        $totalSize = strlen($css);

        $unusedSize = 0;
        foreach ($unusedSelectors as $selector) {
            $escaped = preg_quote($selector, '/');
            if (preg_match('/' . $escaped . '\s*\{([^{}]*)\}/', $css, $m)) {
                $unusedSize += strlen($m[0]);
            }
        }

        $percent = $totalSize > 0 ? round($unusedSize / $totalSize * 100, 1) : 0;
        return $this->formatBytes($unusedSize) . " (~{$percent}%)";
    }

    /**
     * Add to safelist
     */
    public function addToSafelist(string $pattern): self
    {
        $this->safelist[] = $pattern;
        return $this;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
