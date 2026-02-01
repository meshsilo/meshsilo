<?php
/**
 * Optimization CLI Commands
 *
 * Usage:
 *   php cli/optimize.php classmap     Generate optimized class map
 *   php cli/optimize.php clear        Clear all optimization caches
 *   php cli/optimize.php status       Show optimization status
 *   php cli/optimize.php all          Run all optimizations
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Autoloader.php';

$command = $argv[1] ?? 'status';

switch ($command) {
    case 'classmap':
        generateClassMap();
        break;

    case 'clear':
        clearOptimizations();
        break;

    case 'status':
        showStatus();
        break;

    case 'all':
        runAllOptimizations();
        break;

    case 'help':
    default:
        showHelp();
        break;
}

function generateClassMap(): void {
    echo "Generating optimized classmap...\n";

    $autoloader = Autoloader::getInstance();
    $classMap = $autoloader->generateClassMap();

    if ($autoloader->saveClassMap()) {
        echo "✓ Classmap generated with " . count($classMap) . " classes\n";

        // Show sample classes
        echo "\nClasses included:\n";
        $sample = array_slice(array_keys($classMap), 0, 10);
        foreach ($sample as $class) {
            echo "  - $class\n";
        }
        if (count($classMap) > 10) {
            echo "  ... and " . (count($classMap) - 10) . " more\n";
        }
    } else {
        echo "✗ Failed to save classmap\n";
        exit(1);
    }
}

function clearOptimizations(): void {
    echo "Clearing optimization caches...\n";

    $cleared = 0;

    // Clear classmap
    $autoloader = Autoloader::getInstance();
    if ($autoloader->clearCache()) {
        echo "✓ Classmap cache cleared\n";
        $cleared++;
    }

    // Clear route cache
    $routeCache = dirname(__DIR__) . '/storage/cache/routes.php';
    if (file_exists($routeCache) && unlink($routeCache)) {
        echo "✓ Route cache cleared\n";
        $cleared++;
    }

    // Clear asset manifest
    $assetManifest = dirname(__DIR__) . '/storage/cache/assets/manifest.json';
    if (file_exists($assetManifest) && unlink($assetManifest)) {
        echo "✓ Asset manifest cleared\n";
        $cleared++;
    }

    // Clear query cache
    if (class_exists('Cache')) {
        Cache::getInstance()->flush();
        echo "✓ Query cache cleared\n";
        $cleared++;
    }

    // Clear image cache
    $imageCache = dirname(__DIR__) . '/storage/cache/images/';
    if (is_dir($imageCache)) {
        $files = glob($imageCache . '*');
        foreach ($files as $file) {
            if (is_file($file)) unlink($file);
        }
        echo "✓ Image cache cleared (" . count($files) . " files)\n";
        $cleared++;
    }

    echo "\nCleared $cleared optimization caches\n";
}

function showStatus(): void {
    echo "=== MeshSilo Optimization Status ===\n\n";

    // Classmap status
    $autoloader = Autoloader::getInstance();
    $stats = $autoloader->stats();
    echo "Classmap:\n";
    echo "  Cached: " . ($stats['cached'] ? 'Yes' : 'No') . "\n";
    echo "  Classes: " . $stats['classes'] . "\n";

    // OPcache status
    if (function_exists('opcache_get_status')) {
        $opcache = opcache_get_status(false);
        if ($opcache) {
            echo "\nOPcache:\n";
            echo "  Enabled: " . ($opcache['opcache_enabled'] ? 'Yes' : 'No') . "\n";
            echo "  Cached scripts: " . ($opcache['opcache_statistics']['num_cached_scripts'] ?? 0) . "\n";
            echo "  Memory used: " . round(($opcache['memory_usage']['used_memory'] ?? 0) / 1024 / 1024, 2) . " MB\n";
            echo "  Hit rate: " . round($opcache['opcache_statistics']['opcache_hit_rate'] ?? 0, 2) . "%\n";

            // JIT status
            if (isset($opcache['jit'])) {
                echo "\nJIT:\n";
                echo "  Enabled: " . ($opcache['jit']['enabled'] ? 'Yes' : 'No') . "\n";
                echo "  Buffer size: " . round(($opcache['jit']['buffer_size'] ?? 0) / 1024 / 1024, 2) . " MB\n";
            }

            // Preload status
            if (isset($opcache['preload_statistics'])) {
                echo "\nPreload:\n";
                echo "  Scripts: " . ($opcache['preload_statistics']['scripts'] ?? 0) . "\n";
                echo "  Memory: " . round(($opcache['preload_statistics']['memory_consumption'] ?? 0) / 1024 / 1024, 2) . " MB\n";
            }
        }
    }

    // Cache status
    if (class_exists('Cache')) {
        $cache = Cache::getInstance();
        $cacheStats = $cache->stats();
        echo "\nCache:\n";
        echo "  Driver: " . $cacheStats['driver'] . "\n";
        if (isset($cacheStats['hits'])) {
            echo "  Hits: " . $cacheStats['hits'] . "\n";
            echo "  Misses: " . $cacheStats['misses'] . "\n";
        }
        if (isset($cacheStats['entries'])) {
            echo "  Entries: " . $cacheStats['entries'] . "\n";
        }
    }

    // APCu status
    if (function_exists('apcu_cache_info')) {
        $apcu = @apcu_cache_info();
        if ($apcu) {
            echo "\nAPCu:\n";
            echo "  Entries: " . ($apcu['num_entries'] ?? 0) . "\n";
            echo "  Memory: " . round(($apcu['mem_size'] ?? 0) / 1024 / 1024, 2) . " MB\n";
            echo "  Hits: " . ($apcu['num_hits'] ?? 0) . "\n";
        }
    }

    // Image cache status
    if (class_exists('ImageOptimizer')) {
        $imgStats = ImageOptimizer::getInstance()->stats();
        echo "\nImage Cache:\n";
        echo "  WebP enabled: " . ($imgStats['webp_enabled'] ? 'Yes' : 'No') . "\n";
        echo "  Cached images: " . $imgStats['cached_images'] . "\n";
        echo "  Cache size: " . $imgStats['cache_size_human'] . "\n";
    }
}

function runAllOptimizations(): void {
    echo "Running all optimizations...\n\n";

    // Generate classmap
    generateClassMap();
    echo "\n";

    // Show final status
    showStatus();

    echo "\n✓ All optimizations complete!\n";
    echo "\nNote: Restart PHP-FPM to apply preloading changes:\n";
    echo "  docker exec meshsilo service php8.1-fpm restart\n";
}

function showHelp(): void {
    echo "MeshSilo Optimization CLI\n\n";
    echo "Usage: php cli/optimize.php <command>\n\n";
    echo "Commands:\n";
    echo "  classmap    Generate optimized class map for faster autoloading\n";
    echo "  clear       Clear all optimization caches\n";
    echo "  status      Show current optimization status\n";
    echo "  all         Run all optimizations\n";
    echo "  help        Show this help message\n";
}
