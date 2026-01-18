-- Silo Database Schema
-- 3D model files are stored in /assets/

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    is_admin INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Default admin user (password: admin) - CHANGE THIS IN PRODUCTION
INSERT INTO users (username, email, password, is_admin) VALUES
    ('admin', 'admin@localhost', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1);

CREATE TABLE IF NOT EXISTS models (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    filename TEXT NOT NULL,
    file_path TEXT NOT NULL,  -- Relative path within assets/
    file_size INTEGER,
    file_type TEXT,           -- 'stl' or '3mf'
    description TEXT,
    author TEXT,              -- Original creator of the model
    collection TEXT,          -- Collection name (e.g., Gridfinity, Voron)
    source_url TEXT,          -- Link to original source
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
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
