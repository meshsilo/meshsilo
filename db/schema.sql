-- Silo Database Schema
-- 3D model files are stored in /assets/

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    is_admin INTEGER DEFAULT 0,
    permissions TEXT,  -- JSON array of permissions
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Default admin user (password: admin) - CHANGE THIS IN PRODUCTION
INSERT INTO users (username, email, password, is_admin) VALUES
    ('admin', 'admin@localhost', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1);

CREATE TABLE IF NOT EXISTS models (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    filename TEXT,            -- NULL for parent models (ZIP containers)
    file_path TEXT,           -- Relative path within assets/, NULL for parents
    file_size INTEGER,
    file_type TEXT,           -- 'stl', '3mf', or 'zip' for parent models
    description TEXT,
    creator TEXT,             -- Original creator of the model
    collection TEXT,          -- Collection name (e.g., Gridfinity, Voron)
    source_url TEXT,          -- Link to original source
    parent_id INTEGER,        -- References parent model for multi-part uploads
    original_path TEXT,       -- Original path within ZIP for sorting
    part_count INTEGER DEFAULT 0,  -- Number of parts (for parent models)
    print_type TEXT,          -- 'fdm', 'sla', or NULL
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
INSERT INTO categories (name) VALUES
    ('Functional'),
    ('Decorative'),
    ('Tools'),
    ('Gaming'),
    ('Art'),
    ('Mechanical');

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
INSERT INTO groups (name, description, permissions, is_system) VALUES
    ('Admin', 'Full system access', '["upload","delete","edit","admin","view_stats"]', 1),
    ('Users', 'Default user permissions', '["upload","view_stats"]', 1);

-- Assign admin user to Admin group
INSERT INTO user_groups (user_id, group_id) VALUES (1, 1);
