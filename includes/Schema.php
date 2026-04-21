<?php
// Database schema definitions and migration helpers

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
    storage_limit_mb $int DEFAULT 0,
    model_limit $int DEFAULT 0,
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
    approved_by $int,
    approved_at $ts NULL,
    rating_avg $double DEFAULT 0,
    rating_count $int DEFAULT 0,
    view_count $int DEFAULT 0,
    integrity_hash {$varchar(64)},
    integrity_checked_at $ts NULL,
    remix_of $int,
    external_source_url {$varchar(500)},
    external_source_id {$varchar(100)},
    user_id $int,
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

CREATE TABLE IF NOT EXISTS print_queue (
    id $autoId,
    user_id $int NOT NULL,
    model_id $int NOT NULL,
    priority $int DEFAULT 0,
    notes TEXT,
    added_at $ts DEFAULT CURRENT_TIMESTAMP,
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

CREATE TABLE IF NOT EXISTS print_photos (
    id $autoId,
    model_id $int NOT NULL,
    user_id $int,
    filename {$varchar(255)} NOT NULL,
    file_path {$varchar(500)} NOT NULL,
    caption TEXT,
    is_primary $tinyint DEFAULT 0,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
)$engine;

CREATE TABLE IF NOT EXISTS printers (
    id $autoId,
    user_id $int,
    name {$varchar(255)} NOT NULL,
    manufacturer {$varchar(255)},
    model {$varchar(255)},
    bed_x {$decimal(10,2)},
    bed_y {$decimal(10,2)},
    bed_z {$decimal(10,2)},
    print_type {$varchar(50)} DEFAULT 'fdm',
    is_default $tinyint DEFAULT 0,
    notes TEXT,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS print_history (
    id $autoId,
    model_id $int NOT NULL,
    user_id $int,
    printer_id $int,
    print_date $ts DEFAULT CURRENT_TIMESTAMP,
    duration_minutes $int,
    filament_used_g {$decimal(10,2)},
    filament_type {$varchar(100)},
    filament_color {$varchar(100)},
    success $tinyint DEFAULT 1,
    quality_rating $int,
    notes TEXT,
    settings TEXT,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE SET NULL
)$engine;

CREATE TABLE IF NOT EXISTS model_ratings (
    id $autoId,
    model_id $int NOT NULL,
    user_id $int NOT NULL,
    printability $int,
    quality $int,
    difficulty $int,
    review TEXT,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    updated_at $ts DEFAULT CURRENT_TIMESTAMP$onUpdate,
    {$uniqueKey('unique_rating', 'model_id, user_id')},
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS teams (
    id $autoId,
    name {$varchar(255)} NOT NULL,
    description TEXT,
    owner_id $int NOT NULL,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS team_members (
    id $autoId,
    team_id $int NOT NULL,
    user_id $int NOT NULL,
    role {$varchar(50)} DEFAULT 'member',
    joined_at $ts DEFAULT CURRENT_TIMESTAMP,
    {$uniqueKey('unique_membership', 'team_id, user_id')},
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS team_models (
    id $autoId,
    team_id $int NOT NULL,
    model_id $int NOT NULL,
    shared_by $int NOT NULL,
    permissions {$varchar(50)} DEFAULT 'read',
    shared_at $ts DEFAULT CURRENT_TIMESTAMP,
    {$uniqueKey('unique_share', 'team_id, model_id')},
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_by) REFERENCES users(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS team_invites (
    id $autoId,
    team_id $int NOT NULL,
    email {$varchar(255)} NOT NULL,
    role {$varchar(50)} DEFAULT 'member',
    token {$varchar(64)} NOT NULL UNIQUE,
    invited_by $int NOT NULL,
    status {$varchar(20)} DEFAULT 'pending',
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS model_analysis (
    id $autoId,
    model_id $int NOT NULL UNIQUE,
    overhang_percentage {$decimal(5,2)},
    support_required $tinyint DEFAULT 0,
    optimal_orientation TEXT,
    thin_wall_warnings TEXT,
    printability_score $int,
    analysis_warnings TEXT,
    estimated_print_time $int,
    estimated_filament_grams {$decimal(10,2)},
    analyzed_at $ts NULL,
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS import_jobs (
    id $autoId,
    source_url {$varchar(500)} NOT NULL,
    source_type {$varchar(50)} NOT NULL,
    status {$varchar(20)} DEFAULT 'pending',
    total_items $int DEFAULT 0,
    imported_items $int DEFAULT 0,
    failed_items $int DEFAULT 0,
    settings TEXT,
    error_log TEXT,
    created_by $int NOT NULL,
    started_at $ts NULL,
    completed_at $ts NULL,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS import_job_items (
    id $autoId,
    job_id $int NOT NULL,
    external_id {$varchar(100)},
    external_url {$varchar(500)},
    name {$varchar(255)},
    status {$varchar(20)} DEFAULT 'pending',
    model_id $int,
    error_message TEXT,
    metadata TEXT,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES import_jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE SET NULL
)$engine;

CREATE TABLE IF NOT EXISTS conversion_queue (
    id $autoId,
    model_id $int NOT NULL,
    source_format {$varchar(10)} NOT NULL,
    target_format {$varchar(10)} NOT NULL,
    status {$varchar(20)} DEFAULT 'pending',
    priority $int DEFAULT 0,
    error_message TEXT,
    queued_by $int,
    started_at $ts NULL,
    completed_at $ts NULL,
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
    FOREIGN KEY (queued_by) REFERENCES users(id) ON DELETE SET NULL
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
// Schema Helper Functions
// =====================

function columnExists($db, $table, $column)
{
    $type = $db->getType();

    if ($type === 'mysql') {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column");
        $stmt->execute([':table' => $table, ':column' => $column]);
        return $stmt->fetchColumn() > 0;
    } else {
        $result = $db->query("PRAGMA table_info($table)");
        while ($col = $result->fetch()) {
            if ($col['name'] === $column) {
                return true;
            }
        }
        return false;
    }
}

function tableExists($db, $table)
{
    $type = $db->getType();

    if ($type === 'mysql') {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table");
        $stmt->execute([':table' => $table]);
        return $stmt->fetchColumn() > 0;
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = :table");
        $stmt->execute([':table' => $table]);
        return $stmt->fetchColumn() > 0;
    }
}

function indexExists($db, $table, $indexName)
{
    $type = $db->getType();

    if ($type === 'mysql') {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index");
        $stmt->execute([':table' => $table, ':index' => $indexName]);
        return $stmt->fetchColumn() > 0;
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND tbl_name = :table AND name = :index");
        $stmt->execute([':table' => $table, ':index' => $indexName]);
        return $stmt->fetchColumn() > 0;
    }
}

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

// =====================
// Column Additions (ALTER TABLE)
// =====================

/**
 * Ensure all expected columns exist on core tables.
 * Idempotent — checks before adding. Safe to re-run.
 */
function ensureColumns($db)
{
    $type = $db->getType();

    // Models table columns (added after initial schema)
    $modelColumns = [
        'download_count' => 'INTEGER DEFAULT 0',
        'license' => $type === 'mysql' ? 'VARCHAR(100)' : 'TEXT',
        'is_archived' => 'INTEGER DEFAULT 0',
        'notes' => 'TEXT',
        'is_printed' => 'INTEGER DEFAULT 0',
        'printed_at' => $type === 'mysql' ? 'DATETIME' : 'DATETIME',
        'dim_x' => 'REAL',
        'dim_y' => 'REAL',
        'dim_z' => 'REAL',
        'dim_unit' => 'TEXT DEFAULT "mm"',
        'sort_order' => 'INTEGER DEFAULT 0',
        'current_version' => 'INTEGER DEFAULT 1',
        'thumbnail_path' => $type === 'mysql' ? 'VARCHAR(500)' : 'TEXT',
        'folder_id' => $type === 'mysql' ? 'INT' : 'INTEGER',
        'approval_status' => ($type === 'mysql' ? "VARCHAR(20)" : "TEXT") . " DEFAULT 'approved'",
        'approved_by' => $type === 'mysql' ? 'INT' : 'INTEGER',
        'approved_at' => $type === 'mysql' ? 'TIMESTAMP NULL' : 'DATETIME',
        'parent_id' => $type === 'mysql' ? 'INT' : 'INTEGER',
        'original_path' => $type === 'mysql' ? 'VARCHAR(500)' : 'TEXT',
        'part_count' => ($type === 'mysql' ? 'INT' : 'INTEGER') . ' DEFAULT 0',
        'print_type' => $type === 'mysql' ? 'VARCHAR(50)' : 'TEXT',
        'original_size' => $type === 'mysql' ? 'BIGINT' : 'INTEGER',
        'file_hash' => $type === 'mysql' ? 'VARCHAR(64)' : 'TEXT',
        'dedup_path' => $type === 'mysql' ? 'VARCHAR(500)' : 'TEXT',
        'rating_avg' => 'REAL DEFAULT 0',
        'rating_count' => 'INTEGER DEFAULT 0',
        'view_count' => 'INTEGER DEFAULT 0',
        'integrity_hash' => $type === 'mysql' ? 'VARCHAR(64)' : 'TEXT',
        'integrity_checked_at' => $type === 'mysql' ? 'DATETIME' : 'DATETIME',
        'remix_of' => $type === 'mysql' ? 'INT' : 'INTEGER',
        'external_source_url' => $type === 'mysql' ? 'VARCHAR(500)' : 'TEXT',
        'external_source_id' => $type === 'mysql' ? 'VARCHAR(100)' : 'TEXT',
        'user_id' => $type === 'mysql' ? 'INT' : 'INTEGER',
        'upload_status' => $type === 'mysql' ? 'VARCHAR(20) DEFAULT NULL' : 'TEXT DEFAULT NULL',
    ];

    foreach ($modelColumns as $column => $dataType) {
        if (tableExists($db, 'models') && !columnExists($db, 'models', $column)) {
            $db->exec("ALTER TABLE models ADD COLUMN $column $dataType");
        }
    }

    // Users table columns
    $userColumns = [
        'permissions' => 'TEXT',
        'two_factor_enabled' => 'INTEGER DEFAULT 0',
        'two_factor_secret' => $type === 'mysql' ? 'VARCHAR(255)' : 'TEXT',
        'two_factor_backup_codes' => 'TEXT',
        'two_factor_enabled_at' => $type === 'mysql' ? 'DATETIME' : 'DATETIME',
        'storage_limit_mb' => 'INTEGER DEFAULT 0',
        'model_limit' => 'INTEGER DEFAULT 0',
    ];

    foreach ($userColumns as $column => $dataType) {
        if (tableExists($db, 'users') && !columnExists($db, 'users', $column)) {
            $db->exec("ALTER TABLE users ADD COLUMN $column $dataType");
        }
    }

    // Model attachments table columns (added later for PDF compression tracking)
    $attachmentColumns = [
        'pdf_compressed' => 'INTEGER DEFAULT 0',
    ];

    foreach ($attachmentColumns as $column => $dataType) {
        if (tableExists($db, 'model_attachments') && !columnExists($db, 'model_attachments', $column)) {
            $db->exec("ALTER TABLE model_attachments ADD COLUMN $column $dataType");
        }
    }
}

// =====================
// Index Additions
// =====================

/**
 * Ensure critical indexes exist for performance.
 * Idempotent — checks before creating.
 */
function ensureIndexes($db)
{
    $type = $db->getType();

    $indexes = [
        'idx_activity_created' => ['activity_log', 'created_at'],
        'idx_activity_user' => ['activity_log', 'user_id'],
        'idx_recent_user' => ['recently_viewed', 'user_id, viewed_at'],
        'idx_recent_session' => ['recently_viewed', 'session_id, viewed_at'],
        'idx_api_log_created' => ['api_request_log', 'created_at'],
        'idx_api_log_key' => ['api_request_log', 'api_key_id'],
        'idx_models_filename' => ['models', 'filename'],
        'idx_models_parent_id' => ['models', 'parent_id'],
        'idx_models_created_at' => ['models', 'created_at'],
        'idx_model_tags_model' => ['model_tags', 'model_id'],
        'idx_model_tags_tag' => ['model_tags', 'tag_id'],
        'idx_favorites_user' => ['favorites', 'user_id'],
        'idx_favorites_model' => ['favorites', 'model_id'],
        'idx_models_file_hash' => ['models', 'file_hash'],
        'idx_models_dedup_path' => ['models', 'dedup_path'],
        'idx_models_collection' => ['models', 'collection'],
        'idx_models_download_count' => ['models', 'download_count'],
        'idx_models_name' => ['models', 'name'],
        'idx_integrity_log_model' => ['integrity_log', 'model_id'],
        'idx_integrity_log_created' => ['integrity_log', 'created_at'],
        'idx_scheduler_log_task' => ['scheduler_log', 'task_name'],
        'idx_scheduler_log_created' => ['scheduler_log', 'created_at'],
        'idx_event_log_name' => ['event_log', 'event_name'],
        'idx_event_log_user' => ['event_log', 'user_id'],
        'idx_event_log_created' => ['event_log', 'created_at'],
        'idx_rate_limits_expires' => ['rate_limits', 'expires_at'],
        'idx_sessions_user' => ['sessions', 'user_id'],
        'idx_sessions_activity' => ['sessions', 'last_activity'],
        'idx_sessions_expires' => ['sessions', 'expires_at'],
        'idx_audit_event' => ['audit_log', 'event_type, event_name'],
        'idx_audit_user' => ['audit_log', 'user_id'],
        'idx_audit_resource' => ['audit_log', 'resource_type, resource_id'],
        'idx_audit_created' => ['audit_log', 'created_at'],
        'idx_audit_severity' => ['audit_log', 'severity'],
        'idx_model_links_model' => ['model_links', 'model_id'],
        'idx_model_attachments_model' => ['model_attachments', 'model_id'],
        'idx_model_attachments_type' => ['model_attachments', 'file_type'],
        'idx_password_resets_email' => ['password_resets', 'email'],
        'idx_password_resets_token' => ['password_resets', 'token'],
        'idx_password_resets_expires' => ['password_resets', 'expires_at'],
        'idx_import_jobs_status' => ['import_jobs', 'status'],
        'idx_import_jobs_user' => ['import_jobs', 'created_by'],
        'idx_import_items_job' => ['import_job_items', 'job_id'],
        'idx_import_items_status' => ['import_job_items', 'status'],
        'idx_conversion_status' => ['conversion_queue', 'status, priority'],
        'idx_rate_limit_hits_key' => ['rate_limit_hits', 'key_hash'],
    ];

    foreach ($indexes as $indexName => [$table, $columns]) {
        try {
            if (tableExists($db, $table) && !indexExists($db, $table, $indexName)) {
                $db->exec("CREATE INDEX $indexName ON $table($columns)");
            }
        } catch (Exception $e) {
            // Index might already exist under different name, safe to skip
        }
    }

    // MySQL-only composite indexes
    if ($type === 'mysql') {
        $compositeIndexes = [
            'idx_models_parent_created' => ['models', 'parent_id, created_at'],
            'idx_models_parent_original' => ['models', 'parent_id, original_path'],
            'idx_recently_viewed_user_time' => ['recently_viewed', 'user_id, viewed_at'],
            'idx_activity_user_time' => ['activity_log', 'user_id, created_at'],
            'idx_model_categories_composite' => ['model_categories', 'category_id, model_id'],
        ];

        foreach ($compositeIndexes as $indexName => [$table, $columns]) {
            try {
                if (tableExists($db, $table) && !indexExists($db, $table, $indexName)) {
                    $db->exec("CREATE INDEX $indexName ON $table($columns)");
                }
            } catch (Exception $e) {
                // Safe to skip
            }
        }
    }
}

// =====================
// Full-Text Search Setup
// =====================

/**
 * Ensure full-text search indexes and triggers are set up.
 * Handles MySQL FULLTEXT and SQLite FTS5.
 */
function ensureFTS($db)
{
    $type = $db->getType();

    if (!tableExists($db, 'models') || !tableExists($db, 'settings')) {
        return;
    }

    // Check current FTS version
    $currentFtsVersion = 0;
    try {
        $keyCol = $type === 'mysql' ? '`key`' : 'key';
        $stmt = $db->prepare("SELECT value FROM settings WHERE $keyCol = 'fts_version'");
        $stmt->execute();
        $currentFtsVersion = (int)($stmt->fetchColumn() ?: '0');
    } catch (Exception $e) {
        // Settings table might not have fts_version yet
    }

    if ($type === 'mysql') {
        // MySQL: Create/upgrade FULLTEXT index
        try {
            $result = $db->query("SHOW INDEX FROM models WHERE Key_name = 'idx_models_fulltext'");
            $hasFulltext = ($result->fetch() !== false);

            if (!$hasFulltext) {
                $db->exec('CREATE FULLTEXT INDEX idx_models_fulltext ON models(name, description, creator, notes)');
            } elseif ($currentFtsVersion < 2) {
                // Upgrade to include notes
                $db->exec('ALTER TABLE models DROP INDEX idx_models_fulltext');
                $db->exec('CREATE FULLTEXT INDEX idx_models_fulltext ON models(name, description, creator, notes)');
            }

            if ($currentFtsVersion < 3) {
                $db->exec("INSERT INTO settings (`key`, `value`, updated_at) VALUES ('fts_version', '3', NOW()) ON DUPLICATE KEY UPDATE `value` = '3', updated_at = NOW()");
            }
        } catch (Exception $e) {
            if (function_exists('logDebug')) {
                logDebug('FTS MySQL setup skipped', ['error' => $e->getMessage()]);
            }
        }
    } elseif ($type === 'sqlite') {
        // SQLite: Create/upgrade FTS5 virtual table
        if ($currentFtsVersion < 3) {
            try {
                $db->exec('DROP TRIGGER IF EXISTS models_fts_insert');
                $db->exec('DROP TRIGGER IF EXISTS models_fts_delete');
                $db->exec('DROP TRIGGER IF EXISTS models_fts_update');
                $db->exec('DROP TABLE IF EXISTS models_fts');

                $db->exec("
                    CREATE VIRTUAL TABLE models_fts USING fts5(
                        name, description, creator, notes,
                        content='models',
                        content_rowid='id'
                    )
                ");

                $db->exec("
                    INSERT INTO models_fts(rowid, name, description, creator, notes)
                    SELECT id, name, COALESCE(description, ''), COALESCE(creator, ''), COALESCE(notes, '')
                    FROM models
                ");

                $db->exec("
                    CREATE TRIGGER models_fts_insert AFTER INSERT ON models
                    BEGIN
                        INSERT INTO models_fts(rowid, name, description, creator, notes)
                        VALUES (NEW.id, NEW.name, COALESCE(NEW.description, ''), COALESCE(NEW.creator, ''), COALESCE(NEW.notes, ''));
                    END
                ");

                $db->exec("
                    CREATE TRIGGER models_fts_delete AFTER DELETE ON models
                    BEGIN
                        DELETE FROM models_fts WHERE rowid = OLD.id;
                    END
                ");

                $db->exec("
                    CREATE TRIGGER models_fts_update AFTER UPDATE ON models
                    BEGIN
                        UPDATE models_fts
                        SET name = NEW.name,
                            description = COALESCE(NEW.description, ''),
                            creator = COALESCE(NEW.creator, ''),
                            notes = COALESCE(NEW.notes, '')
                        WHERE rowid = NEW.id;
                    END
                ");

                $db->exec("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('fts_version', '3', CURRENT_TIMESTAMP)");
            } catch (Exception $e) {
                if (function_exists('logWarning')) {
                    logWarning('FTS SQLite setup skipped', ['error' => $e->getMessage()]);
                }
            }
        }
    }
}

// =====================
// Migration Orchestrator
// =====================

/**
 * Run all migrations: table creation, column additions, indexes, FTS, default settings.
 * Replaces the old runMigrations() function.
 */
function runAllMigrations($db)
{
    $type = $db->getType();

    // Set busy timeout for SQLite
    if ($type === 'sqlite') {
        $db->exec('PRAGMA busy_timeout = 10000');
    }

    // If core tables don't exist, initialize full schema
    if (!tableExists($db, 'users')) {
        initializeDatabase($db);
        initializeDefaultSettings($db);
        return;
    }

    // 1. Run table creation migrations (derived from getSchema)
    require_once __DIR__ . '/migrations.php';
    $migrations = getMigrationList();
    foreach ($migrations as $migration) {
        if (!$migration['check']($db)) {
            try {
                $migration['apply']($db);
                if (function_exists('logInfo')) {
                    logInfo('Migration applied: ' . $migration['name']);
                }
            } catch (Exception $e) {
                if (function_exists('logWarning')) {
                    logWarning('Migration failed: ' . $migration['name'], ['error' => $e->getMessage()]);
                }
            }
        }
    }

    // 2. Ensure all columns exist on core tables
    ensureColumns($db);

    // 3. Ensure indexes exist
    ensureIndexes($db);

    // 4. Set up full-text search
    ensureFTS($db);

    // 5. Ensure default settings
    initializeDefaultSettings($db);
}
