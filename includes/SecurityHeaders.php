<?php
/**
 * Security Headers Manager
 * Configure and apply security headers (CSP, HSTS, X-Frame-Options, etc.)
 */

class SecurityHeaders {
    private static array $headers = [];
    private static bool $initialized = false;

    /**
     * Initialize headers from settings
     */
    public static function init(): void {
        if (self::$initialized) {
            return;
        }

        self::$headers = self::loadSettings();
        self::$initialized = true;
    }

    /**
     * Load security header settings
     */
    private static function loadSettings(): array {
        $defaults = self::getDefaults();

        if (!function_exists('getSetting')) {
            return $defaults;
        }

        $saved = getSetting('security_headers', '');
        if (!empty($saved)) {
            $savedHeaders = json_decode($saved, true);
            if ($savedHeaders) {
                return array_merge($defaults, $savedHeaders);
            }
        }

        return $defaults;
    }

    /**
     * Get default security headers configuration
     */
    public static function getDefaults(): array {
        return [
            'hsts' => [
                'enabled' => false,
                'max_age' => 31536000, // 1 year
                'include_subdomains' => false,
                'preload' => false,
            ],
            'csp' => [
                'enabled' => false,
                'report_only' => true,
                'directives' => [
                    'default-src' => ["'self'"],
                    'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'", "https://cdn.jsdelivr.net", "https://cdnjs.cloudflare.com"],
                    'style-src' => ["'self'", "'unsafe-inline'", "https://fonts.googleapis.com", "https://cdn.jsdelivr.net"],
                    'img-src' => ["'self'", "data:", "blob:", "https:"],
                    'font-src' => ["'self'", "https://fonts.gstatic.com", "https://cdn.jsdelivr.net"],
                    'connect-src' => ["'self'"],
                    'frame-src' => ["'self'"],
                    'object-src' => ["'none'"],
                    'base-uri' => ["'self'"],
                    'form-action' => ["'self'"],
                    'frame-ancestors' => ["'self'"],
                ],
                'report_uri' => '',
            ],
            'x_frame_options' => [
                'enabled' => true,
                'value' => 'SAMEORIGIN', // DENY, SAMEORIGIN, or ALLOW-FROM uri
            ],
            'x_content_type_options' => [
                'enabled' => true,
            ],
            'x_xss_protection' => [
                'enabled' => true,
                'mode' => 'block', // 0, 1, block
            ],
            'referrer_policy' => [
                'enabled' => true,
                'value' => 'strict-origin-when-cross-origin',
            ],
            'permissions_policy' => [
                'enabled' => false,
                'directives' => [
                    'camera' => [],
                    'microphone' => [],
                    'geolocation' => [],
                    'payment' => [],
                    'usb' => [],
                ],
            ],
            'cross_origin_embedder_policy' => [
                'enabled' => false,
                'value' => 'require-corp',
            ],
            'cross_origin_opener_policy' => [
                'enabled' => false,
                'value' => 'same-origin',
            ],
            'cross_origin_resource_policy' => [
                'enabled' => false,
                'value' => 'same-origin',
            ],
        ];
    }

    /**
     * Apply all configured security headers
     */
    public static function apply(): void {
        self::init();

        if (headers_sent()) {
            return;
        }

        // HSTS
        if (self::$headers['hsts']['enabled']) {
            self::applyHSTS();
        }

        // Content Security Policy
        if (self::$headers['csp']['enabled']) {
            self::applyCSP();
        }

        // X-Frame-Options
        if (self::$headers['x_frame_options']['enabled']) {
            header('X-Frame-Options: ' . self::$headers['x_frame_options']['value']);
        }

        // X-Content-Type-Options
        if (self::$headers['x_content_type_options']['enabled']) {
            header('X-Content-Type-Options: nosniff');
        }

        // X-XSS-Protection
        if (self::$headers['x_xss_protection']['enabled']) {
            $mode = self::$headers['x_xss_protection']['mode'];
            if ($mode === '0') {
                header('X-XSS-Protection: 0');
            } elseif ($mode === 'block') {
                header('X-XSS-Protection: 1; mode=block');
            } else {
                header('X-XSS-Protection: 1');
            }
        }

        // Referrer-Policy
        if (self::$headers['referrer_policy']['enabled']) {
            header('Referrer-Policy: ' . self::$headers['referrer_policy']['value']);
        }

        // Permissions-Policy
        if (self::$headers['permissions_policy']['enabled']) {
            self::applyPermissionsPolicy();
        }

        // Cross-Origin-Embedder-Policy
        if (self::$headers['cross_origin_embedder_policy']['enabled']) {
            header('Cross-Origin-Embedder-Policy: ' . self::$headers['cross_origin_embedder_policy']['value']);
        }

        // Cross-Origin-Opener-Policy
        if (self::$headers['cross_origin_opener_policy']['enabled']) {
            header('Cross-Origin-Opener-Policy: ' . self::$headers['cross_origin_opener_policy']['value']);
        }

        // Cross-Origin-Resource-Policy
        if (self::$headers['cross_origin_resource_policy']['enabled']) {
            header('Cross-Origin-Resource-Policy: ' . self::$headers['cross_origin_resource_policy']['value']);
        }
    }

    /**
     * Apply HSTS header
     */
    private static function applyHSTS(): void {
        $value = 'max-age=' . self::$headers['hsts']['max_age'];

        if (self::$headers['hsts']['include_subdomains']) {
            $value .= '; includeSubDomains';
        }

        if (self::$headers['hsts']['preload']) {
            $value .= '; preload';
        }

        header('Strict-Transport-Security: ' . $value);
    }

    /**
     * Apply Content Security Policy header
     */
    private static function applyCSP(): void {
        $directives = [];

        foreach (self::$headers['csp']['directives'] as $directive => $sources) {
            if (!empty($sources)) {
                $directives[] = $directive . ' ' . implode(' ', $sources);
            }
        }

        if (!empty(self::$headers['csp']['report_uri'])) {
            $directives[] = 'report-uri ' . self::$headers['csp']['report_uri'];
        }

        $policy = implode('; ', $directives);
        $headerName = self::$headers['csp']['report_only']
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        header($headerName . ': ' . $policy);
    }

    /**
     * Apply Permissions-Policy header
     */
    private static function applyPermissionsPolicy(): void {
        $directives = [];

        foreach (self::$headers['permissions_policy']['directives'] as $feature => $allowlist) {
            if (empty($allowlist)) {
                $directives[] = $feature . '=()';
            } else {
                $directives[] = $feature . '=(' . implode(' ', array_map(function($v) {
                    return '"' . $v . '"';
                }, $allowlist)) . ')';
            }
        }

        if (!empty($directives)) {
            header('Permissions-Policy: ' . implode(', ', $directives));
        }
    }

    /**
     * Get current configuration
     */
    public static function getConfig(): array {
        self::init();
        return self::$headers;
    }

    /**
     * Save security headers configuration
     */
    public static function saveConfig(array $config): bool {
        if (!function_exists('setSetting')) {
            return false;
        }

        // Validate and sanitize
        $validated = self::validateConfig($config);

        setSetting('security_headers', json_encode($validated));
        self::$headers = $validated;

        return true;
    }

    /**
     * Validate configuration
     */
    private static function validateConfig(array $config): array {
        $defaults = self::getDefaults();
        $validated = [];

        // HSTS
        $validated['hsts'] = [
            'enabled' => !empty($config['hsts']['enabled']),
            'max_age' => max(0, min(63072000, (int)($config['hsts']['max_age'] ?? $defaults['hsts']['max_age']))),
            'include_subdomains' => !empty($config['hsts']['include_subdomains']),
            'preload' => !empty($config['hsts']['preload']),
        ];

        // CSP
        $validated['csp'] = [
            'enabled' => !empty($config['csp']['enabled']),
            'report_only' => !empty($config['csp']['report_only']),
            'directives' => [],
            'report_uri' => filter_var($config['csp']['report_uri'] ?? '', FILTER_SANITIZE_URL),
        ];

        // Validate CSP directives
        $validDirectives = [
            'default-src', 'script-src', 'style-src', 'img-src', 'font-src',
            'connect-src', 'media-src', 'object-src', 'frame-src', 'child-src',
            'worker-src', 'manifest-src', 'base-uri', 'form-action', 'frame-ancestors',
            'navigate-to', 'report-to', 'upgrade-insecure-requests', 'block-all-mixed-content'
        ];

        foreach ($validDirectives as $directive) {
            if (isset($config['csp']['directives'][$directive])) {
                $sources = $config['csp']['directives'][$directive];
                if (is_array($sources)) {
                    $validated['csp']['directives'][$directive] = array_filter(
                        array_map('trim', $sources),
                        function($s) { return !empty($s); }
                    );
                } elseif (is_string($sources)) {
                    $validated['csp']['directives'][$directive] = array_filter(
                        array_map('trim', explode(' ', $sources)),
                        function($s) { return !empty($s); }
                    );
                }
            } else {
                $validated['csp']['directives'][$directive] = $defaults['csp']['directives'][$directive] ?? [];
            }
        }

        // X-Frame-Options
        $xfoValues = ['DENY', 'SAMEORIGIN'];
        $validated['x_frame_options'] = [
            'enabled' => !empty($config['x_frame_options']['enabled']),
            'value' => in_array($config['x_frame_options']['value'] ?? '', $xfoValues)
                ? $config['x_frame_options']['value']
                : 'SAMEORIGIN',
        ];

        // X-Content-Type-Options
        $validated['x_content_type_options'] = [
            'enabled' => $config['x_content_type_options']['enabled'] ?? true,
        ];

        // X-XSS-Protection
        $validated['x_xss_protection'] = [
            'enabled' => $config['x_xss_protection']['enabled'] ?? true,
            'mode' => in_array($config['x_xss_protection']['mode'] ?? '', ['0', '1', 'block'])
                ? $config['x_xss_protection']['mode']
                : 'block',
        ];

        // Referrer-Policy
        $referrerValues = [
            'no-referrer', 'no-referrer-when-downgrade', 'origin', 'origin-when-cross-origin',
            'same-origin', 'strict-origin', 'strict-origin-when-cross-origin', 'unsafe-url'
        ];
        $validated['referrer_policy'] = [
            'enabled' => $config['referrer_policy']['enabled'] ?? true,
            'value' => in_array($config['referrer_policy']['value'] ?? '', $referrerValues)
                ? $config['referrer_policy']['value']
                : 'strict-origin-when-cross-origin',
        ];

        // Permissions-Policy
        $validated['permissions_policy'] = [
            'enabled' => !empty($config['permissions_policy']['enabled']),
            'directives' => $config['permissions_policy']['directives'] ?? $defaults['permissions_policy']['directives'],
        ];

        // Cross-Origin policies
        $coepValues = ['unsafe-none', 'require-corp', 'credentialless'];
        $validated['cross_origin_embedder_policy'] = [
            'enabled' => !empty($config['cross_origin_embedder_policy']['enabled']),
            'value' => in_array($config['cross_origin_embedder_policy']['value'] ?? '', $coepValues)
                ? $config['cross_origin_embedder_policy']['value']
                : 'require-corp',
        ];

        $coopValues = ['unsafe-none', 'same-origin-allow-popups', 'same-origin'];
        $validated['cross_origin_opener_policy'] = [
            'enabled' => !empty($config['cross_origin_opener_policy']['enabled']),
            'value' => in_array($config['cross_origin_opener_policy']['value'] ?? '', $coopValues)
                ? $config['cross_origin_opener_policy']['value']
                : 'same-origin',
        ];

        $corpValues = ['same-site', 'same-origin', 'cross-origin'];
        $validated['cross_origin_resource_policy'] = [
            'enabled' => !empty($config['cross_origin_resource_policy']['enabled']),
            'value' => in_array($config['cross_origin_resource_policy']['value'] ?? '', $corpValues)
                ? $config['cross_origin_resource_policy']['value']
                : 'same-origin',
        ];

        return $validated;
    }

    /**
     * Test headers and get a security score
     */
    public static function analyze(): array {
        self::init();

        $score = 0;
        $maxScore = 100;
        $findings = [];

        // HSTS (20 points)
        if (self::$headers['hsts']['enabled']) {
            $score += 10;
            if (self::$headers['hsts']['max_age'] >= 31536000) {
                $score += 5;
            }
            if (self::$headers['hsts']['include_subdomains']) {
                $score += 3;
            }
            if (self::$headers['hsts']['preload']) {
                $score += 2;
            }
        } else {
            $findings[] = [
                'severity' => 'high',
                'header' => 'Strict-Transport-Security',
                'message' => 'HSTS is not enabled. Enable it to prevent downgrade attacks.',
            ];
        }

        // CSP (25 points)
        if (self::$headers['csp']['enabled']) {
            $score += 15;
            if (!self::$headers['csp']['report_only']) {
                $score += 10;
            } else {
                $findings[] = [
                    'severity' => 'medium',
                    'header' => 'Content-Security-Policy',
                    'message' => 'CSP is in report-only mode. Consider enforcing it after testing.',
                ];
            }

            // Check for unsafe directives
            $cspDirs = self::$headers['csp']['directives'];
            if (in_array("'unsafe-inline'", $cspDirs['script-src'] ?? [])) {
                $findings[] = [
                    'severity' => 'medium',
                    'header' => 'Content-Security-Policy',
                    'message' => "script-src contains 'unsafe-inline'. Consider using nonces or hashes.",
                ];
            }
            if (in_array("'unsafe-eval'", $cspDirs['script-src'] ?? [])) {
                $findings[] = [
                    'severity' => 'medium',
                    'header' => 'Content-Security-Policy',
                    'message' => "script-src contains 'unsafe-eval'. This allows eval() which is a security risk.",
                ];
            }
        } else {
            $findings[] = [
                'severity' => 'high',
                'header' => 'Content-Security-Policy',
                'message' => 'CSP is not enabled. Enable it to prevent XSS and injection attacks.',
            ];
        }

        // X-Frame-Options (15 points)
        if (self::$headers['x_frame_options']['enabled']) {
            $score += 15;
        } else {
            $findings[] = [
                'severity' => 'high',
                'header' => 'X-Frame-Options',
                'message' => 'X-Frame-Options is not set. Your site may be vulnerable to clickjacking.',
            ];
        }

        // X-Content-Type-Options (10 points)
        if (self::$headers['x_content_type_options']['enabled']) {
            $score += 10;
        } else {
            $findings[] = [
                'severity' => 'medium',
                'header' => 'X-Content-Type-Options',
                'message' => 'X-Content-Type-Options is not set. Enable nosniff to prevent MIME sniffing.',
            ];
        }

        // Referrer-Policy (10 points)
        if (self::$headers['referrer_policy']['enabled']) {
            $score += 10;
        } else {
            $findings[] = [
                'severity' => 'low',
                'header' => 'Referrer-Policy',
                'message' => 'Referrer-Policy is not set. Consider setting it to control referrer information.',
            ];
        }

        // Permissions-Policy (10 points)
        if (self::$headers['permissions_policy']['enabled']) {
            $score += 10;
        } else {
            $findings[] = [
                'severity' => 'low',
                'header' => 'Permissions-Policy',
                'message' => 'Permissions-Policy is not set. Consider restricting browser features.',
            ];
        }

        // Cross-Origin policies (10 points)
        $coPoints = 0;
        if (self::$headers['cross_origin_embedder_policy']['enabled']) $coPoints += 3;
        if (self::$headers['cross_origin_opener_policy']['enabled']) $coPoints += 4;
        if (self::$headers['cross_origin_resource_policy']['enabled']) $coPoints += 3;
        $score += $coPoints;

        if ($coPoints < 10) {
            $findings[] = [
                'severity' => 'low',
                'header' => 'Cross-Origin Policies',
                'message' => 'Some cross-origin policies are not enabled. Consider enabling them for additional isolation.',
            ];
        }

        // Calculate grade
        $grade = 'F';
        if ($score >= 90) $grade = 'A+';
        elseif ($score >= 80) $grade = 'A';
        elseif ($score >= 70) $grade = 'B';
        elseif ($score >= 60) $grade = 'C';
        elseif ($score >= 50) $grade = 'D';

        return [
            'score' => $score,
            'max_score' => $maxScore,
            'grade' => $grade,
            'findings' => $findings,
            'headers' => self::$headers,
        ];
    }

    /**
     * Generate .htaccess snippet for Apache
     */
    public static function generateApacheConfig(): string {
        self::init();
        $lines = ["# Security Headers - Generated by Silo", ""];

        if (self::$headers['hsts']['enabled']) {
            $value = 'max-age=' . self::$headers['hsts']['max_age'];
            if (self::$headers['hsts']['include_subdomains']) $value .= '; includeSubDomains';
            if (self::$headers['hsts']['preload']) $value .= '; preload';
            $lines[] = 'Header always set Strict-Transport-Security "' . $value . '"';
        }

        if (self::$headers['x_frame_options']['enabled']) {
            $lines[] = 'Header always set X-Frame-Options "' . self::$headers['x_frame_options']['value'] . '"';
        }

        if (self::$headers['x_content_type_options']['enabled']) {
            $lines[] = 'Header always set X-Content-Type-Options "nosniff"';
        }

        if (self::$headers['referrer_policy']['enabled']) {
            $lines[] = 'Header always set Referrer-Policy "' . self::$headers['referrer_policy']['value'] . '"';
        }

        if (self::$headers['csp']['enabled']) {
            $directives = [];
            foreach (self::$headers['csp']['directives'] as $dir => $sources) {
                if (!empty($sources)) {
                    $directives[] = $dir . ' ' . implode(' ', $sources);
                }
            }
            $policy = implode('; ', $directives);
            $headerName = self::$headers['csp']['report_only']
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';
            $lines[] = 'Header always set ' . $headerName . ' "' . $policy . '"';
        }

        return implode("\n", $lines);
    }

    /**
     * Generate nginx config snippet
     */
    public static function generateNginxConfig(): string {
        self::init();
        $lines = ["# Security Headers - Generated by Silo", ""];

        if (self::$headers['hsts']['enabled']) {
            $value = 'max-age=' . self::$headers['hsts']['max_age'];
            if (self::$headers['hsts']['include_subdomains']) $value .= '; includeSubDomains';
            if (self::$headers['hsts']['preload']) $value .= '; preload';
            $lines[] = 'add_header Strict-Transport-Security "' . $value . '" always;';
        }

        if (self::$headers['x_frame_options']['enabled']) {
            $lines[] = 'add_header X-Frame-Options "' . self::$headers['x_frame_options']['value'] . '" always;';
        }

        if (self::$headers['x_content_type_options']['enabled']) {
            $lines[] = 'add_header X-Content-Type-Options "nosniff" always;';
        }

        if (self::$headers['referrer_policy']['enabled']) {
            $lines[] = 'add_header Referrer-Policy "' . self::$headers['referrer_policy']['value'] . '" always;';
        }

        if (self::$headers['csp']['enabled']) {
            $directives = [];
            foreach (self::$headers['csp']['directives'] as $dir => $sources) {
                if (!empty($sources)) {
                    $directives[] = $dir . ' ' . implode(' ', $sources);
                }
            }
            $policy = implode('; ', $directives);
            $headerName = self::$headers['csp']['report_only']
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';
            $lines[] = 'add_header ' . $headerName . ' "' . $policy . '" always;';
        }

        return implode("\n", $lines);
    }
}
