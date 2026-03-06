<?php
/**
 * Lightweight Markdown Parser
 *
 * Converts a subset of Markdown to HTML. Input is HTML-escaped first
 * for security, then markdown syntax is transformed.
 *
 * Supported syntax:
 * - Headings (## h2 through ###### h6)
 * - Bold (**text** or __text__)
 * - Italic (*text* or _text_)
 * - Strikethrough (~~text~~)
 * - Links [text](url)
 * - Images ![alt](url)
 * - Inline code (`code`)
 * - Code blocks (```)
 * - Unordered lists (- or *)
 * - Ordered lists (1.)
 * - Blockquotes (>)
 * - Horizontal rules (--- or ***)
 * - Line breaks
 */

class Markdown
{
    /**
     * Convert markdown text to HTML
     *
     * @param string $text Raw markdown text
     * @return string Safe HTML output
     */
    public static function render(string $text): string
    {
        // Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Escape HTML for security
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Process block-level elements first
        $text = self::processCodeBlocks($text);
        $text = self::processBlockquotes($text);
        $text = self::processHeadings($text);
        $text = self::processHorizontalRules($text);
        $text = self::processLists($text);
        $text = self::processParagraphs($text);

        // Process inline elements
        $text = self::processInlineCode($text);
        $text = self::processImages($text);
        $text = self::processLinks($text);
        $text = self::processBoldItalic($text);
        $text = self::processStrikethrough($text);

        return trim($text);
    }

    /**
     * Process fenced code blocks (```)
     */
    private static function processCodeBlocks(string $text): string
    {
        return preg_replace_callback(
            '/^```(\w*)\n(.*?)^```/ms',
            function ($matches) {
                $lang = $matches[1];
                $code = $matches[2];
                // Code is already HTML-escaped, escape lang attribute to prevent XSS
                $langAttr = $lang ? ' class="language-' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '"' : '';
                return '<pre><code' . $langAttr . '>' . rtrim($code) . '</code></pre>';
            },
            $text
        );
    }

    /**
     * Process inline code (`code`)
     */
    private static function processInlineCode(string $text): string
    {
        return preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $text);
    }

    /**
     * Process headings (## text)
     * Only h2-h6 to avoid h1 conflicts with page titles
     */
    private static function processHeadings(string $text): string
    {
        $text = preg_replace('/^######\s+(.+)$/m', '<h6>$1</h6>', $text);
        $text = preg_replace('/^#####\s+(.+)$/m', '<h5>$1</h5>', $text);
        $text = preg_replace('/^####\s+(.+)$/m', '<h4>$1</h4>', $text);
        $text = preg_replace('/^###\s+(.+)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^##\s+(.+)$/m', '<h2>$1</h2>', $text);
        return $text;
    }

    /**
     * Process horizontal rules (---, ***, ___)
     */
    private static function processHorizontalRules(string $text): string
    {
        return preg_replace('/^[-*_]{3,}\s*$/m', '<hr>', $text);
    }

    /**
     * Process blockquotes (> text)
     */
    private static function processBlockquotes(string $text): string
    {
        return preg_replace_callback(
            '/(?:^&gt;\s?.+\n?)+/m',
            function ($matches) {
                $content = preg_replace('/^&gt;\s?/m', '', $matches[0]);
                return '<blockquote>' . trim($content) . '</blockquote>';
            },
            $text
        );
    }

    /**
     * Process unordered and ordered lists
     */
    private static function processLists(string $text): string
    {
        // Unordered lists (- or * at line start)
        $text = preg_replace_callback(
            '/(?:^[\-\*]\s+.+\n?)+/m',
            function ($matches) {
                $items = preg_split('/^[\-\*]\s+/m', trim($matches[0]));
                $items = array_filter($items);
                $html = '<ul>';
                foreach ($items as $item) {
                    $html .= '<li>' . trim($item) . '</li>';
                }
                $html .= '</ul>';
                return $html;
            },
            $text
        );

        // Ordered lists (1. at line start)
        $text = preg_replace_callback(
            '/(?:^\d+\.\s+.+\n?)+/m',
            function ($matches) {
                $items = preg_split('/^\d+\.\s+/m', trim($matches[0]));
                $items = array_filter($items);
                $html = '<ol>';
                foreach ($items as $item) {
                    $html .= '<li>' . trim($item) . '</li>';
                }
                $html .= '</ol>';
                return $html;
            },
            $text
        );

        return $text;
    }

    /**
     * Process images ![alt](url)
     */
    private static function processImages(string $text): string
    {
        return preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)]+)\)/',
            function ($matches) {
                $alt = $matches[1];
                $url = $matches[2];
                // Only allow http(s) and relative URLs
                if (!self::isSafeUrl($url)) {
                    return $matches[0];
                }
                return '<img src="' . $url . '" alt="' . $alt . '" loading="lazy">';
            },
            $text
        );
    }

    /**
     * Process links [text](url)
     */
    private static function processLinks(string $text): string
    {
        return preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            function ($matches) {
                $linkText = $matches[1];
                $url = $matches[2];
                // Only allow http(s) and relative URLs
                if (!self::isSafeUrl($url)) {
                    return $matches[0];
                }
                return '<a href="' . $url . '" rel="noopener noreferrer">' . $linkText . '</a>';
            },
            $text
        );
    }

    /**
     * Process bold and italic
     */
    private static function processBoldItalic(string $text): string
    {
        // Bold + Italic (***text*** or ___text___)
        $text = preg_replace('/\*{3}(.+?)\*{3}/', '<strong><em>$1</em></strong>', $text);
        $text = preg_replace('/_{3}(.+?)_{3}/', '<strong><em>$1</em></strong>', $text);

        // Bold (**text** or __text__)
        $text = preg_replace('/\*{2}(.+?)\*{2}/', '<strong>$1</strong>', $text);
        $text = preg_replace('/_{2}(.+?)_{2}/', '<strong>$1</strong>', $text);

        // Italic (*text* or _text_)
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/(?<![a-zA-Z0-9])_(.+?)_(?![a-zA-Z0-9])/', '<em>$1</em>', $text);

        return $text;
    }

    /**
     * Process strikethrough (~~text~~)
     */
    private static function processStrikethrough(string $text): string
    {
        return preg_replace('/~~(.+?)~~/', '<del>$1</del>', $text);
    }

    /**
     * Wrap loose lines in paragraph tags
     */
    private static function processParagraphs(string $text): string
    {
        // Split on double newlines
        $blocks = preg_split('/\n{2,}/', $text);
        $result = [];

        foreach ($blocks as $block) {
            $block = trim($block);
            if (empty($block)) continue;

            // Don't wrap block-level elements
            if (preg_match('/^<(h[2-6]|ul|ol|li|blockquote|pre|hr|div|table)/i', $block)) {
                $result[] = $block;
            } else {
                // Convert single newlines to <br> within paragraphs
                $block = str_replace("\n", "<br>\n", $block);
                $result[] = '<p>' . $block . '</p>';
            }
        }

        return implode("\n", $result);
    }

    /**
     * Check if a URL is safe (http, https, or relative)
     */
    private static function isSafeUrl(string $url): bool
    {
        // Already HTML-escaped, so decode for checking
        $decoded = html_entity_decode($url, ENT_QUOTES, 'UTF-8');

        // Allow relative URLs
        if (strpos($decoded, '/') === 0 || strpos($decoded, './') === 0) {
            return true;
        }
        // Allow http(s) URLs
        if (preg_match('#^https?://#i', $decoded)) {
            return true;
        }
        return false;
    }
}
