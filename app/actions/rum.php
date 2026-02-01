<?php
/**
 * Real User Monitoring (RUM) Data Collector
 *
 * Receives performance metrics from the browser and stores them for analysis.
 */

require_once __DIR__ . '/../../includes/config.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Get JSON payload
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || empty($data['metrics'])) {
    http_response_code(400);
    exit;
}

$metrics = $data['metrics'];
$errors = $data['errors'] ?? [];
$slowResources = $data['slowResources'] ?? [];

// Store metrics
try {
    $db = getDB();

    // Ensure table exists
    ensureRumTable($db);

    // Insert metrics
    $stmt = $db->prepare('
        INSERT INTO rum_metrics (
            url, referrer, user_agent, connection_type,
            lcp, fid, cls, fcp, fp, ttfb,
            dom_content_loaded, page_load, dom_interactive,
            resource_count, js_errors,
            created_at
        ) VALUES (
            :url, :referrer, :user_agent, :connection_type,
            :lcp, :fid, :cls, :fcp, :fp, :ttfb,
            :dom_content_loaded, :page_load, :dom_interactive,
            :resource_count, :js_errors,
            :created_at
        )
    ');

    $stmt->execute([
        ':url' => substr($metrics['url'] ?? '', 0, 255),
        ':referrer' => substr($metrics['referrer'] ?? '', 0, 255),
        ':user_agent' => substr($metrics['userAgent'] ?? '', 0, 255),
        ':connection_type' => $metrics['connection']['effectiveType'] ?? null,
        ':lcp' => $metrics['lcp'] ?? null,
        ':fid' => $metrics['fid'] ?? null,
        ':cls' => $metrics['cls'] ?? null,
        ':fcp' => $metrics['fcp'] ?? null,
        ':fp' => $metrics['fp'] ?? null,
        ':ttfb' => $metrics['timing']['ttfb'] ?? null,
        ':dom_content_loaded' => $metrics['timing']['domContentLoaded'] ?? null,
        ':page_load' => $metrics['timing']['load'] ?? null,
        ':dom_interactive' => $metrics['timing']['domInteractive'] ?? null,
        ':resource_count' => $metrics['resourceCount'] ?? 0,
        ':js_errors' => count($errors),
        ':created_at' => date('Y-m-d H:i:s'),
    ]);

    // Store errors if any
    if (!empty($errors)) {
        $errorStmt = $db->prepare('
            INSERT INTO rum_errors (url, message, source, line_number, created_at)
            VALUES (:url, :message, :source, :line, :created_at)
        ');

        foreach ($errors as $error) {
            $errorStmt->execute([
                ':url' => substr($metrics['url'] ?? '', 0, 255),
                ':message' => substr($error['message'] ?? '', 0, 500),
                ':source' => substr($error['source'] ?? '', 0, 255),
                ':line' => $error['line'] ?? null,
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    http_response_code(204); // No content
} catch (Exception $e) {
    logError('RUM data collection failed', ['error' => $e->getMessage()]);
    http_response_code(500);
}

/**
 * Ensure RUM tables exist
 */
function ensureRumTable(PDO $db): void {
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $db->exec('
            CREATE TABLE IF NOT EXISTS rum_metrics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                url TEXT,
                referrer TEXT,
                user_agent TEXT,
                connection_type TEXT,
                lcp INTEGER,
                fid INTEGER,
                cls REAL,
                fcp INTEGER,
                fp INTEGER,
                ttfb INTEGER,
                dom_content_loaded INTEGER,
                page_load INTEGER,
                dom_interactive INTEGER,
                resource_count INTEGER,
                js_errors INTEGER,
                created_at TEXT
            )
        ');

        $db->exec('
            CREATE TABLE IF NOT EXISTS rum_errors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                url TEXT,
                message TEXT,
                source TEXT,
                line_number INTEGER,
                created_at TEXT
            )
        ');

        $db->exec('CREATE INDEX IF NOT EXISTS idx_rum_url ON rum_metrics(url)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_rum_created ON rum_metrics(created_at)');
    } else {
        $db->exec('
            CREATE TABLE IF NOT EXISTS rum_metrics (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                url VARCHAR(255),
                referrer VARCHAR(255),
                user_agent VARCHAR(255),
                connection_type VARCHAR(20),
                lcp INT,
                fid INT,
                cls DECIMAL(5,3),
                fcp INT,
                fp INT,
                ttfb INT,
                dom_content_loaded INT,
                page_load INT,
                dom_interactive INT,
                resource_count INT,
                js_errors INT,
                created_at DATETIME,
                INDEX idx_url (url),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        $db->exec('
            CREATE TABLE IF NOT EXISTS rum_errors (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                url VARCHAR(255),
                message VARCHAR(500),
                source VARCHAR(255),
                line_number INT,
                created_at DATETIME,
                INDEX idx_url (url),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }
}
