<?php
/**
 * API Versioning
 *
 * Supports multiple versioning strategies:
 * - URL path: /api/v1/models
 * - Header: Accept: application/vnd.meshsilo.v1+json
 * - Query param: /api/models?version=1
 */

class ApiVersion {
    /** Current/default API version */
    const CURRENT_VERSION = 1;

    /** Supported versions */
    const SUPPORTED_VERSIONS = [1];

    /** Minimum supported version */
    const MIN_VERSION = 1;

    /** Version that will be deprecated */
    const DEPRECATED_VERSIONS = [];

    /** Version sunset dates (version => date string) */
    const SUNSET_DATES = [];

    private int $version;
    private string $detectedFrom;

    public function __construct(?int $version = null, string $detectedFrom = 'default') {
        $this->version = $version ?? self::CURRENT_VERSION;
        $this->detectedFrom = $detectedFrom;
    }

    /**
     * Detect version from request
     */
    public static function fromRequest(): self {
        // 1. Check URL path for /api/v{N}/
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (preg_match('#/api/v(\d+)/#', $uri, $matches)) {
            return new self((int)$matches[1], 'url');
        }

        // 2. Check Accept header
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (preg_match('#application/vnd\.meshsilo\.v(\d+)\+json#', $accept, $matches)) {
            return new self((int)$matches[1], 'header');
        }

        // 3. Check query parameter
        if (isset($_GET['version']) && is_numeric($_GET['version'])) {
            return new self((int)$_GET['version'], 'query');
        }

        // 4. Check X-API-Version header
        if (!empty($_SERVER['HTTP_X_API_VERSION']) && is_numeric($_SERVER['HTTP_X_API_VERSION'])) {
            return new self((int)$_SERVER['HTTP_X_API_VERSION'], 'header');
        }

        // Default to current version
        return new self(self::CURRENT_VERSION, 'default');
    }

    /**
     * Get the detected version
     */
    public function getVersion(): int {
        return $this->version;
    }

    /**
     * Get how the version was detected
     */
    public function getDetectedFrom(): string {
        return $this->detectedFrom;
    }

    /**
     * Check if this version is supported
     */
    public function isSupported(): bool {
        return in_array($this->version, self::SUPPORTED_VERSIONS);
    }

    /**
     * Check if this version is deprecated
     */
    public function isDeprecated(): bool {
        return in_array($this->version, self::DEPRECATED_VERSIONS);
    }

    /**
     * Get sunset date for deprecated version
     */
    public function getSunsetDate(): ?string {
        return self::SUNSET_DATES[$this->version] ?? null;
    }

    /**
     * Validate version and throw exception if unsupported
     */
    public function validate(): void {
        if (!$this->isSupported()) {
            $supported = implode(', ', self::SUPPORTED_VERSIONS);
            throw new Exception(
                "API version {$this->version} is not supported. Supported versions: {$supported}",
                400
            );
        }
    }

    /**
     * Add deprecation headers if needed
     */
    public function addDeprecationHeaders(): void {
        if ($this->isDeprecated()) {
            header('Deprecation: true');
            if ($sunsetDate = $this->getSunsetDate()) {
                header("Sunset: {$sunsetDate}");
            }
            header('X-API-Warn: This API version is deprecated. Please upgrade to v' . self::CURRENT_VERSION);
        }

        // Always add current version header
        header('X-API-Version: ' . $this->version);
    }

    /**
     * Check if version is at least the specified version
     */
    public function isAtLeast(int $minVersion): bool {
        return $this->version >= $minVersion;
    }

    /**
     * Get version-specific route file if it exists
     */
    public function getRouteFile(string $baseFile): string {
        $dir = dirname($baseFile);
        $name = basename($baseFile, '.php');

        // Check for version-specific file
        $versionFile = "{$dir}/v{$this->version}/{$name}.php";
        if (file_exists($versionFile)) {
            return $versionFile;
        }

        // Fall back to base file
        return $baseFile;
    }

    /**
     * Get version info for API response
     */
    public function getVersionInfo(): array {
        return [
            'version' => $this->version,
            'current' => self::CURRENT_VERSION,
            'supported' => self::SUPPORTED_VERSIONS,
            'deprecated' => $this->isDeprecated(),
            'sunset' => $this->getSunsetDate(),
        ];
    }

    /**
     * Strip version prefix from URI
     */
    public static function stripVersionFromUri(string $uri): string {
        return preg_replace('#/v\d+/#', '/', $uri);
    }

    /**
     * Get documentation URL for version
     */
    public function getDocsUrl(): string {
        $baseUrl = defined('SITE_URL') ? SITE_URL : '';
        return "{$baseUrl}/api/v{$this->version}/docs";
    }
}

/**
 * Helper function to get current API version
 */
function api_version(): ApiVersion {
    static $version = null;
    if ($version === null) {
        $version = ApiVersion::fromRequest();
    }
    return $version;
}

/**
 * Helper to check minimum version requirement
 */
function api_requires_version(int $minVersion): void {
    $version = api_version();
    if (!$version->isAtLeast($minVersion)) {
        throw new Exception(
            "This endpoint requires API version {$minVersion} or higher.",
            400
        );
    }
}
