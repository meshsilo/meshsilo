# MeshSilo Installation Guide

## Requirements

- **PHP 8.1** or higher
- **Required PHP extensions:** pdo, pdo_sqlite (or pdo_mysql), json, mbstring, openssl
- **Optional PHP extensions:** gd or imagick (thumbnails), apcu (caching), pcntl (queue workers)
- **Database:** SQLite 3 (default, zero-config) or MySQL 5.7+ / MariaDB 10.3+
- **Web server:** nginx (recommended) or Apache with mod_rewrite

## Docker Installation (Recommended)

The fastest way to get running:

```bash
git clone https://github.com/your-org/meshsilo.git
cd meshsilo
docker compose up -d --build
```

MeshSilo will be available at `http://localhost:8080`. The first visit will launch the installation wizard.

### Docker Commands

```bash
# View logs
docker compose logs -f

# Run database migrations
docker exec meshsilo php cli/migrate.php

# Check migration status
docker exec meshsilo php cli/migrate.php --status

# Restart
docker compose restart

# Stop
docker compose down
```

### Docker Volumes

Data is persisted in the `storage/` directory:
- `storage/db/` - SQLite database and local configuration
- `storage/assets/` - Uploaded model files
- `storage/logs/` - Application logs
- `storage/cache/` - Cached data

## Manual Installation

### 1. Download and extract

```bash
git clone https://github.com/your-org/meshsilo.git /var/www/meshsilo
cd /var/www/meshsilo
composer install --no-dev --optimize-autoloader
```

### 2. Set directory permissions

```bash
chmod -R 775 storage/
chown -R www-data:www-data storage/
```

### 3. Configure your web server

#### nginx

```nginx
server {
    listen 80;
    server_name meshsilo.example.com;
    root /var/www/meshsilo;
    index index.php;

    client_max_body_size 100M;

    # Route all requests through the front controller
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Serve static files directly
    location /public/ {
        try_files $uri =404;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # PHP processing
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Block access to sensitive files
    location ~ /\. { deny all; }
    location ~ /(storage|cli|includes|app|vendor)/ { deny all; }
}
```

#### Apache

Ensure `mod_rewrite` is enabled. The included `.htaccess` handles routing:

```bash
a2enmod rewrite
systemctl restart apache2
```

Apache virtual host example:

```apache
<VirtualHost *:80>
    ServerName meshsilo.example.com
    DocumentRoot /var/www/meshsilo

    <Directory /var/www/meshsilo>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 4. Database configuration

**SQLite (default, no configuration needed):**

MeshSilo uses SQLite by default. The database file is created automatically at `storage/db/meshsilo.db`.

**MySQL / MariaDB:**

Create a `storage/db/config.local.php` file:

```php
<?php
define('DB_TYPE', 'mysql');
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'meshsilo');
define('DB_USER', 'meshsilo');
define('DB_PASS', 'your_secure_password');
```

Then create the database:

```sql
CREATE DATABASE meshsilo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'meshsilo'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON meshsilo.* TO 'meshsilo'@'localhost';
FLUSH PRIVILEGES;
```

### 5. Run the installation wizard

Visit `http://your-server/install` in your browser. The wizard will:
- Verify system requirements
- Set up the database schema
- Create the admin account
- Configure basic site settings

### 6. Run migrations

After installation, ensure the database schema is up to date:

```bash
php cli/migrate.php
```

## Post-Install Steps

1. **Create admin account** - Done via the installation wizard, or manually:
   ```bash
   # The install wizard handles this automatically
   ```

2. **Configure email (optional)** - Go to Admin > Settings > Email to set up SMTP for password resets and notifications.

3. **Set upload limits** - Adjust `upload_max_filesize` and `post_max_size` in your `php.ini`:
   ```ini
   upload_max_filesize = 100M
   post_max_size = 105M
   ```

4. **Set up scheduled tasks (optional):**
   ```bash
   # Add to crontab for background processing
   * * * * * cd /var/www/meshsilo && php cli/scheduler.php >> /dev/null 2>&1
   ```

5. **Enable features** - Go to Admin > Features to enable/disable optional functionality (tags, collections, etc.).

## WAMP / Local Windows Development

For local development on Windows using WAMP:

1. Install WAMP with PHP 8.1+
2. Clone the repo to your working directory (e.g., `S:\git\Silo`)
3. Copy files to WAMP's web directory (e.g., `D:\wamp64\www\meshsilo`)
4. Access at `http://127.0.0.1/meshsilo` (or configure a virtual host)

A sync script is available at `C:\temp\sync-meshsilo.ps1` to copy changes:
```powershell
powershell -ExecutionPolicy Bypass -File C:\temp\sync-meshsilo.ps1
```

## Upgrading

```bash
# Pull latest changes
git pull origin main

# Update dependencies
composer install --no-dev --optimize-autoloader

# Run migrations
php cli/migrate.php

# Clear cache
php -r "array_map('unlink', glob('storage/cache/*/*.php'));"
```

For Docker:
```bash
docker compose pull
docker compose up -d --build
docker exec meshsilo php cli/migrate.php
```

## Troubleshooting

| Issue | Solution |
|-------|----------|
| "column index out of range" | Run migrations: `php cli/migrate.php` |
| 404 on all pages | Check web server rewrite rules |
| File upload fails | Check `upload_max_filesize` in php.ini |
| Permission denied | Ensure `storage/` is writable by the web server |
| Blank page | Check `storage/logs/error.log` for PHP errors |
