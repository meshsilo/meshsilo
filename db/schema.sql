-- Silo Database Schema
-- 3D model files are stored in /assets/

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
