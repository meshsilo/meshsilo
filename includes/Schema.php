<?php
// Database schema definitions and migration helpers

require_once __DIR__ . '/SchemaMigrations.php';

/**
 * Generate database schema for the given database type.
 * Single source of truth — ALL tables are defined here.
 * Dialect differences handled via PHP interpolation.
 */
function getSchema(string $type = 'sqlite'): string
{
    $mysql = $type === 'mysql';
    $autoId = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $int = $mysql ? 'INT' : 'INTEGER';
    $tinyint = $mysql ? 'TINYINT' : 'INTEGER';
    $bigint = $mysql ? 'BIGINT' : 'INTEGER';
    $varchar = fn(int $len) => $mysql ? "VARCHAR($len)" : 'TEXT';
    $decimal = fn(int $p, int $s) => $mysql ? "DECIMAL($p,$s)" : 'REAL';
    $double = $mysql ? 'DOUBLE' : 'REAL';
    $ts = $mysql ? 'TIMESTAMP' : 'DATETIME';
    $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
    $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $insertIgnore = $mysql ? 'INSERT IGNORE INTO' : 'INSERT OR IGNORE INTO';
    $groups = $mysql ? '`groups`' : 'groups';
    $key = $mysql ? '`key`' : 'key';
    $value = $mysql ? '`value`' : 'value';
    $uniqueKey = fn(string $name, string $cols) => $mysql ? "UNIQUE KEY $name ($cols)" : "UNIQUE ($cols)";

    return "
CREATE TABLE IF NOT EXISTS users (
    id $autoId,
    username {$varchar(255)} NOT NULL UNIQUE,
    email {$varchar(255)} NOT NULL UNIQUE,
    password {$varchar(255)} NOT NULL,
    is_admin $tinyint DEFAULT 0,
    permissions TEXT,
    two_factor_enabled $tinyint DEFAULT 0,
    two_factor_secret {$varchar(255)},
    two_factor_backup_codes TEXT,
    two_factor_enabled_at $ts NULL,
    two_factor_last_used $bigint,
    created_at $ts DEFAULT CURRENT_TIMESTAMP
)$engine;

CREATE TABLE IF NOT EXISTS models (
    id $autoId,
    name {$varchar(255)} NOT NULL,
    filename {$varchar(255)},
    file_path {$varchar(500)},
    file_size $bigint,
    file_type {$varchar(50)},
    description TEXT,
    creator {$varchar(255)},
    collection {$varchar(255)},
    source_url {$varchar(500)},
    parent_id $int,
    original_path {$varchar(500)},
    part_count $int DEFAULT 0,
    print_type {$varchar(50)},
    original_size $bigint,
    file_hash {$varchar(64)},
    dedup_path {$varchar(500)},
    download_count $int DEFAULT 0,
    license {$varchar(100)},
    is_archived $tinyint DEFAULT 0,
    notes TEXT,
    is_printed $tinyint DEFAULT 0,
    printed_at $ts NULL,
    dim_x $double,
    dim_y $double,
    dim_z $double,
    dim_unit {$varchar(10)} DEFAULT 'mm',
    sort_order $int DEFAULT 0,
    current_version $int DEFAULT 1,
    thumbnail_path {$varchar(500)},
    folder_id $int,
    approval_status {$varchar(20)} DEFAULT 'approved',
    view_count $int DEFAULT 0,
    integrity_hash {$varchar(64)},
    integrity_checked_at $ts NULL,
    remix_of $int,
    external_source_url {$varchar(500)},
    external_source_id {$varchar(100)},
    user_id $int,
    nest_folders $tinyint DEFAULT 0,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    updated_at $ts DEFAULT CURRENT_TIMESTAMP$onUpdate,
    FOREIGN KEY (parent_id) REFERENCES models(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS categories (
    id $autoId,
    name {$varchar(255)} NOT NULL UNIQUE
)$engine;

CREATE TABLE IF NOT EXISTS model_categories (
    model_id $int,
    category_id $int,
    PRIMARY KEY (model_id, category_id),
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
)$engine;

$insertIgnore categories (name) VALUES ('Functional');
$insertIgnore categories (name) VALUES ('Decorative');
$insertIgnore categories (name) VALUES ('Tools');
$insertIgnore categories (name) VALUES ('Gaming');
$insertIgnore categories (name) VALUES ('Art');
$insertIgnore categories (name) VALUES ('Mechanical');

CREATE TABLE IF NOT EXISTS collections (
    id $autoId,
    name {$varchar(255)} NOT NULL UNIQUE,
    description TEXT,
    created_at $ts DEFAULT CURRENT_TIMESTAMP
)$engine;

CREATE TABLE IF NOT EXISTS $groups (
    id $autoId,
    name {$varchar(255)} NOT NULL UNIQUE,
    description TEXT,
    permissions TEXT,
    is_system $tinyint DEFAULT 0,
    created_at $ts DEFAULT CURRENT_TIMESTAMP
)$engine;

CREATE TABLE IF NOT EXISTS user_groups (
    user_id $int,
    group_id $int,
    PRIMARY KEY (user_id, group_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES $groups(id) ON DELETE CASCADE
)$engine;

$insertIgnore $groups (name, description, permissions, is_system) VALUES ('Admin', 'Full system access', '[\"upload\",\"delete\",\"edit\",\"admin\",\"view_stats\"]', 1);
$insertIgnore $groups (name, description, permissions, is_system) VALUES ('Users', 'Default user permissions', '[\"upload\",\"view_stats\"]', 1);

CREATE TABLE IF NOT EXISTS settings (
    $key {$varchar(255)} PRIMARY KEY,
    $value TEXT,
    updated_at $ts DEFAULT CURRENT_TIMESTAMP$onUpdate
)$engine;

CREATE TABLE IF NOT EXISTS tags (
    id $autoId,
    name {$varchar(100)} NOT NULL UNIQUE,
    color {$varchar(7)} DEFAULT '#6366f1',
    created_at $ts DEFAULT CURRENT_TIMESTAMP
)$engine;

CREATE TABLE IF NOT EXISTS model_tags (
    model_id $int NOT NULL,
    tag_id $int NOT NULL,
    PRIMARY KEY (model_id, tag_id),
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS favorites (
    id $autoId,
    user_id $int NOT NULL,
    model_id $int NOT NULL,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    {$uniqueKey('unique_favorite', 'user_id, model_id')},
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS activity_log (
    id $autoId,
    user_id $int,
    action {$varchar(50)} NOT NULL,
    entity_type {$varchar(50)},
    entity_id $int,
    entity_name {$varchar(255)},
    details TEXT,
    ip_address {$varchar(45)},
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
)$engine;

CREATE TABLE IF NOT EXISTS recently_viewed (
    id $autoId,
    user_id $int,
    session_id {$varchar(64)},
    model_id $int NOT NULL,
    viewed_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS model_versions (
    id $autoId,
    model_id $int NOT NULL,
    version_number $int NOT NULL,
    file_path {$varchar(500)},
    file_size $bigint,
    file_hash {$varchar(64)},
    changelog TEXT,
    created_by $int,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    {$uniqueKey('unique_model_version', 'model_id, version_number')},
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
)$engine;

CREATE TABLE IF NOT EXISTS api_keys (
    id $autoId,
    user_id $int NOT NULL,
    name {$varchar(255)} NOT NULL,
    key_hash {$varchar(64)} NOT NULL UNIQUE,
    key_prefix {$varchar(12)} NOT NULL,
    permissions TEXT,
    is_active $tinyint DEFAULT 1,
    expires_at $ts NULL,
    last_used_at $ts NULL,
    request_count $int DEFAULT 0,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS api_request_log (
    id $autoId,
    api_key_id $int NOT NULL,
    method {$varchar(10)} NOT NULL,
    endpoint {$varchar(255)} NOT NULL,
    ip_address {$varchar(45)},
    user_agent {$varchar(500)},
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS folders (
    id $autoId,
    parent_id $int,
    user_id $int,
    name {$varchar(255)} NOT NULL,
    description TEXT,
    color {$varchar(7)},
    sort_order $int DEFAULT 0,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS related_models (
    id $autoId,
    model_id $int NOT NULL,
    related_model_id $int NOT NULL,
    relationship_type {$varchar(50)} DEFAULT 'related',
    is_remix $tinyint DEFAULT 0,
    remix_notes TEXT,
    created_by $int,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    {$uniqueKey('unique_relation', 'model_id, related_model_id')},
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
    FOREIGN KEY (related_model_id) REFERENCES models(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS integrity_log (
    id $autoId,
    model_id $int NOT NULL,
    status {$varchar(20)} NOT NULL,
    message TEXT,
    details TEXT,
    resolved $tinyint DEFAULT 0,
    resolution TEXT,
    resolved_at $ts NULL,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS scheduler_log (
    id $autoId,
    task_name {$varchar(100)} NOT NULL,
    status {$varchar(20)} NOT NULL,
    output TEXT,
    duration_ms $int,
    created_at $ts DEFAULT CURRENT_TIMESTAMP
)$engine;

CREATE TABLE IF NOT EXISTS event_log (
    id $autoId,
    event_name {$varchar(100)} NOT NULL,
    user_id $int,
    data TEXT,
    ip_address {$varchar(45)},
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
)$engine;

CREATE TABLE IF NOT EXISTS rate_limits (
    id $autoId,
    key_name {$varchar(255)} NOT NULL UNIQUE,
    data TEXT,
    expires_at $int NOT NULL
)$engine;

CREATE TABLE IF NOT EXISTS sessions (
    id {$varchar(128)} PRIMARY KEY,
    user_id $int,
    data TEXT,
    ip_address {$varchar(45)},
    user_agent {$varchar(500)},
    last_activity $ts DEFAULT CURRENT_TIMESTAMP$onUpdate,
    expires_at $int NOT NULL DEFAULT 0,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS audit_log (
    id $autoId,
    event_type {$varchar(50)} NOT NULL,
    event_name {$varchar(100)} NOT NULL,
    severity {$varchar(20)} DEFAULT 'info',
    user_id $int,
    ip_address {$varchar(45)},
    user_agent {$varchar(500)},
    resource_type {$varchar(50)},
    resource_id $int,
    resource_name {$varchar(255)},
    old_value TEXT,
    new_value TEXT,
    metadata TEXT,
    session_id {$varchar(128)},
    request_id {$varchar(36)},
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
)$engine;

CREATE TABLE IF NOT EXISTS password_resets (
    id $autoId,
    email {$varchar(255)} NOT NULL,
    token {$varchar(64)} NOT NULL UNIQUE,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    expires_at $ts NOT NULL,
    used_at $ts NULL
)$engine;

CREATE TABLE IF NOT EXISTS model_links (
    id $autoId,
    model_id $int NOT NULL,
    title {$varchar(255)} NOT NULL,
    url TEXT NOT NULL,
    link_type {$varchar(50)} DEFAULT 'other',
    sort_order $int DEFAULT 0,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS model_attachments (
    id $autoId,
    model_id $int NOT NULL,
    filename {$varchar(255)} NOT NULL,
    file_path TEXT NOT NULL,
    file_type {$varchar(20)} NOT NULL,
    mime_type {$varchar(100)},
    file_size $int,
    original_filename {$varchar(255)},
    display_order $int DEFAULT 0,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS annotations (
    id $autoId,
    model_id $int NOT NULL,
    user_id $int NOT NULL,
    position_x $double NOT NULL,
    position_y $double NOT NULL,
    position_z $double NOT NULL,
    normal_x $double DEFAULT 0,
    normal_y $double DEFAULT 0,
    normal_z $double DEFAULT 1,
    content TEXT NOT NULL,
    color {$varchar(7)} DEFAULT '#ff0000',
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS plugins (
    id {$varchar(100)} PRIMARY KEY,
    name {$varchar(200)} NOT NULL,
    version {$varchar(20)} NOT NULL DEFAULT '1.0.0',
    description TEXT,
    author {$varchar(200)},
    is_active $tinyint NOT NULL DEFAULT 0,
    settings TEXT,
    installed_at $ts DEFAULT CURRENT_TIMESTAMP,
    updated_at $ts DEFAULT CURRENT_TIMESTAMP
)$engine;

CREATE TABLE IF NOT EXISTS plugin_repositories (
    id $autoId,
    name {$varchar(200)} NOT NULL,
    url {$varchar(500)} NOT NULL,
    is_official $tinyint NOT NULL DEFAULT 0,
    last_fetched $ts NULL,
    registry_cache TEXT,
    created_at $ts DEFAULT CURRENT_TIMESTAMP
)$engine;

CREATE TABLE IF NOT EXISTS jobs (
    id $autoId,
    queue {$varchar(255)} NOT NULL DEFAULT 'default',
    job_class {$varchar(255)} NOT NULL,
    payload TEXT NOT NULL,
    attempts $int DEFAULT 0,
    max_attempts $int DEFAULT 3,
    status {$varchar(50)} DEFAULT 'pending',
    available_at $ts DEFAULT CURRENT_TIMESTAMP,
    reserved_at $ts NULL,
    completed_at $ts NULL,
    error_message TEXT,
    created_at $ts DEFAULT CURRENT_TIMESTAMP
)$engine;

CREATE TABLE IF NOT EXISTS saved_searches (
    id $autoId,
    user_id $int NOT NULL,
    name {$varchar(255)} NOT NULL,
    description TEXT,
    query TEXT NOT NULL,
    filters TEXT,
    sort_by {$varchar(50)},
    sort_order {$varchar(10)} DEFAULT 'desc',
    is_public $tinyint DEFAULT 0,
    share_token {$varchar(64)} UNIQUE,
    use_count $int DEFAULT 0,
    last_used_at $ts NULL,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    updated_at $ts DEFAULT CURRENT_TIMESTAMP$onUpdate,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS rate_limit_hits (
    id $autoId,
    key_hash {$varchar(64)} NOT NULL,
    timestamp $int NOT NULL
)$engine";
}

// Backward-compatible wrappers
function getSQLiteSchema(): string { return getSchema('sqlite'); }
function getMySQLSchema(): string { return getSchema('mysql'); }

// =====================
// Schema Verification
// =====================

/**
 * Verify database schema is ready for web requests.
 *
 * Web requests do NOT run migrations - they only verify core tables exist.
 * Migrations must be run via CLI: php cli/migrate.php
 */
function verifySchemaReady($db)
{
    // CLI scripts run full migrations
    if (php_sapi_name() === 'cli') {
        runAllMigrations($db);
        return true;
    }

    // Web requests: Quick check for core tables only (single query)
    try {
        $type = $db->getType();

        if ($type === 'mysql') {
            $result = $db->query("SELECT COUNT(*) as cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('users', 'models', 'settings')");
        } else {
            $result = $db->query("SELECT COUNT(*) as cnt FROM sqlite_master WHERE type='table' AND name IN ('users', 'models', 'settings')");
        }

        $row = $result->fetch();
        $tableCount = $row ? (int)$row['cnt'] : 0;

        if ($tableCount < 3) {
            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: text/html; charset=utf-8');
            }
            echo '<!DOCTYPE html><html lang="en"><head><title>Database Setup Required</title></head><body style="font-family: system-ui, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px;">';
            echo '<h1>Database Setup Required</h1>';
            echo '<p>The database schema needs to be initialized or updated.</p>';
            echo '<p>Please run the following command:</p>';
            echo '<pre style="background: #f4f4f4; padding: 15px; border-radius: 5px;">php cli/migrate.php</pre>';
            echo '<p>Or in Docker:</p>';
            echo '<pre style="background: #f4f4f4; padding: 15px; border-radius: 5px;">docker exec meshsilo php cli/migrate.php</pre>';
            echo '</body></html>';
            exit(1);
        }

        return true;
    } catch (Exception $e) {
        if (function_exists('logException')) {
            logException($e, ['action' => 'verify_schema']);
        }
        return false;
    }
}
