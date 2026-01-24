-- Silo Database Schema Reference
-- This file documents the database structure. Actual schema is managed by includes/db.php.
-- 3D model files are stored in /assets/

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    is_admin INTEGER DEFAULT 0,
    permissions TEXT,           -- JSON array of permissions (legacy)
    oidc_id TEXT,               -- OIDC provider user ID for SSO
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS models (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    filename TEXT,              -- NULL for parent models (ZIP containers)
    file_path TEXT,             -- Relative path within assets/, NULL for parents
    file_size INTEGER,
    file_type TEXT,             -- 'stl', '3mf', or 'zip' for parent models
    description TEXT,
    creator TEXT,               -- Original creator of the model
    collection TEXT,            -- Collection name (e.g., Gridfinity, Voron)
    source_url TEXT,            -- Link to original source
    parent_id INTEGER,          -- References parent model for multi-part uploads
    original_path TEXT,         -- Original path within ZIP for sorting
    part_count INTEGER DEFAULT 0,  -- Number of parts (for parent models)
    print_type TEXT,            -- 'fdm', 'sla', or NULL
    original_size INTEGER,      -- Original file size before conversion
    file_hash TEXT,             -- SHA256 hash for deduplication
    dedup_path TEXT,            -- Path to deduplicated file (if deduplicated)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES models(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS model_categories (
    model_id INTEGER,
    category_id INTEGER,
    PRIMARY KEY (model_id, category_id),
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

-- Default categories
INSERT OR IGNORE INTO categories (name) VALUES ('Functional');
INSERT OR IGNORE INTO categories (name) VALUES ('Decorative');
INSERT OR IGNORE INTO categories (name) VALUES ('Tools');
INSERT OR IGNORE INTO categories (name) VALUES ('Gaming');
INSERT OR IGNORE INTO categories (name) VALUES ('Art');
INSERT OR IGNORE INTO categories (name) VALUES ('Mechanical');

CREATE TABLE IF NOT EXISTS collections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Groups for permission management
CREATE TABLE IF NOT EXISTS groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    permissions TEXT,           -- JSON array of permissions
    is_system INTEGER DEFAULT 0, -- System groups cannot be deleted
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- User-Group junction table
CREATE TABLE IF NOT EXISTS user_groups (
    user_id INTEGER,
    group_id INTEGER,
    PRIMARY KEY (user_id, group_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
);

-- Default groups
INSERT OR IGNORE INTO groups (name, description, permissions, is_system) VALUES
    ('Admin', 'Full system access', '["upload","delete","edit","admin","view_stats"]', 1);
INSERT OR IGNORE INTO groups (name, description, permissions, is_system) VALUES
    ('Users', 'Default user permissions', '["upload","view_stats"]', 1);

-- Settings table for configurable options
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Default settings
INSERT OR IGNORE INTO settings (key, value) VALUES ('auto_convert_stl', '0');
INSERT OR IGNORE INTO settings (key, value) VALUES ('site_name', 'Silo');
INSERT OR IGNORE INTO settings (key, value) VALUES ('site_description', 'Your 3D Model Library');
INSERT OR IGNORE INTO settings (key, value) VALUES ('models_per_page', '20');
INSERT OR IGNORE INTO settings (key, value) VALUES ('allow_registration', '1');
INSERT OR IGNORE INTO settings (key, value) VALUES ('require_approval', '0');
INSERT OR IGNORE INTO settings (key, value) VALUES ('enable_categories', '1');
INSERT OR IGNORE INTO settings (key, value) VALUES ('enable_collections', '1');
INSERT OR IGNORE INTO settings (key, value) VALUES ('allowed_extensions', 'stl,3mf,gcode,zip');
INSERT OR IGNORE INTO settings (key, value) VALUES ('auto_deduplication', '0');
INSERT OR IGNORE INTO settings (key, value) VALUES ('last_deduplication', '');
INSERT OR IGNORE INTO settings (key, value) VALUES ('oidc_enabled', '0');
INSERT OR IGNORE INTO settings (key, value) VALUES ('oidc_provider_url', '');
INSERT OR IGNORE INTO settings (key, value) VALUES ('oidc_client_id', '');
INSERT OR IGNORE INTO settings (key, value) VALUES ('oidc_client_secret', '');
INSERT OR IGNORE INTO settings (key, value) VALUES ('oidc_button_text', 'Sign in with SSO');
INSERT OR IGNORE INTO settings (key, value) VALUES ('site_url', '');
INSERT OR IGNORE INTO settings (key, value) VALUES ('force_site_url', '0');
