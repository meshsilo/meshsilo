# MeshSilo

A self-hosted 3D model asset manager. Organize, preview, and share your 3D printing files.

Supports STL, 3MF, OBJ, PLY, GLTF/GLB, FBX, STEP, IGES, and more.

## Requirements

- **PHP** 8.1+
- **Database**: SQLite (default) or MySQL 8.0+
- **Web Server**: Nginx or Apache

## Docker Installation (Recommended)

Multi-architecture images available for `amd64` and `arm64`.

### Quick Start

Create a `docker-compose.yml`:

```yaml
services:
  meshsilo:
    image: ghcr.io/meshsilo/meshsilo:latest
    container_name: meshsilo
    ports:
      - "8080:80"
    volumes:
      - meshsilo_assets:/var/www/meshsilo/storage/assets
      - meshsilo_db:/var/www/meshsilo/storage/db
      - meshsilo_logs:/var/www/meshsilo/storage/logs
      - meshsilo_cache:/var/www/meshsilo/storage/cache
      - meshsilo_plugins:/var/www/meshsilo/plugins
    environment:
      - MESHSILO_SITE_NAME=MeshSilo
      - MESHSILO_SITE_DESCRIPTION=3D Model Asset Manager
      # - MESHSILO_SITE_URL=https://models.example.com
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/health"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 10s

volumes:
  meshsilo_assets:
  meshsilo_db:
  meshsilo_logs:
  meshsilo_cache:
  meshsilo_plugins:
```

```bash
docker compose up -d
```

Open `http://localhost:8080` and follow the installation wizard.

### With MySQL

```yaml
services:
  meshsilo:
    image: ghcr.io/meshsilo/meshsilo:latest
    container_name: meshsilo
    ports:
      - "8080:80"
    volumes:
      - meshsilo_assets:/var/www/meshsilo/storage/assets
      - meshsilo_db:/var/www/meshsilo/storage/db
      - meshsilo_logs:/var/www/meshsilo/storage/logs
      - meshsilo_cache:/var/www/meshsilo/storage/cache
      - meshsilo_plugins:/var/www/meshsilo/plugins
    environment:
      - MESHSILO_DB_TYPE=mysql
      - MESHSILO_DB_HOST=db
      - MESHSILO_DB_PORT=3306
      - MESHSILO_DB_NAME=meshsilo
      - MESHSILO_DB_USER=meshsilo
      - MESHSILO_DB_PASS=meshsilo_password
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy

  db:
    image: mysql:8.0
    container_name: meshsilo-db
    restart: unless-stopped
    environment:
      - MYSQL_ROOT_PASSWORD=root_password_change_me
      - MYSQL_DATABASE=meshsilo
      - MYSQL_USER=meshsilo
      - MYSQL_PASSWORD=meshsilo_password
    volumes:
      - meshsilo_mysql:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5

volumes:
  meshsilo_assets:
  meshsilo_db:
  meshsilo_logs:
  meshsilo_cache:
  meshsilo_plugins:
  meshsilo_mysql:
```

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `MESHSILO_DB_TYPE` | `sqlite` or `mysql` | `sqlite` |
| `MESHSILO_DB_HOST` | MySQL host | `localhost` |
| `MESHSILO_DB_PORT` | MySQL port | `3306` |
| `MESHSILO_DB_NAME` | MySQL database name | `meshsilo` |
| `MESHSILO_DB_USER` | MySQL username | `meshsilo` |
| `MESHSILO_DB_PASS` | MySQL password | — |
| `MESHSILO_SITE_NAME` | Site name | `MeshSilo` |
| `MESHSILO_SITE_DESCRIPTION` | Site description | `3D Model Asset Manager` |
| `MESHSILO_SITE_URL` | External URL (for reverse proxy) | — |
| `MESHSILO_MAX_UPLOAD_SIZE` | Max upload size in bytes | `104857600` (100MB) |
| `MESHSILO_ALLOWED_EXTENSIONS` | Comma-separated file extensions | All supported formats |
| `MESHSILO_DEDUP_ENABLED` | File deduplication | `true` |

### Docker Volumes

| Volume | Contents | Backup Priority |
|--------|----------|-----------------|
| `storage/assets` | Uploaded 3D models and attachments | Critical |
| `storage/db` | SQLite database and config | Critical |
| `plugins` | Installed plugins | Important |
| `storage/logs` | Application logs | Low |
| `storage/cache` | Route and asset cache | Not needed |

### Reverse Proxy

Set `MESHSILO_SITE_URL` to your external URL when behind a reverse proxy.

**Nginx:**

```nginx
server {
    listen 443 ssl http2;
    server_name models.example.com;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    client_max_body_size 100M;

    location / {
        proxy_pass http://localhost:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

## Manual Installation

1. Clone and set permissions:
   ```bash
   git clone https://github.com/meshsilo/meshsilo.git
   cd meshsilo
   composer install --no-dev
   chmod 755 storage/assets storage/logs storage/db storage/cache
   chown -R www-data:www-data storage/
   ```

2. Configure your web server:
   - **Apache**: `mod_rewrite` required. `.htaccess` is included.
   - **Nginx**: Copy `nginx.conf.example` and adjust paths.

3. Visit `http://your-server/install.php` to run the installation wizard.

4. Remove `install.php` after setup:
   ```bash
   rm install.php
   ```

## Upgrading

**Docker:**

```bash
docker compose pull
docker compose up -d
```

Migrations run automatically on startup.

**Manual:**

```bash
git pull origin main
composer install --no-dev
php cli/migrate.php
```

## Backup & Restore

**Backup (Docker):**

```bash
docker run --rm \
  -v meshsilo_assets:/data/assets \
  -v meshsilo_db:/data/db \
  -v $(pwd):/backup \
  alpine tar czf /backup/meshsilo-$(date +%Y%m%d).tar.gz -C /data .
```

**Backup (Manual):**

```bash
tar czf meshsilo-backup.tar.gz storage/assets storage/db
```

**Restore:** Extract the archive back into the same volume or directory.

## CLI Tools

```bash
# Docker
docker exec meshsilo php cli/migrate.php            # Run migrations
docker exec meshsilo php cli/migrate.php --status    # Check status
docker exec meshsilo php cli/dedup.php --scan        # Find duplicates
docker exec meshsilo php cli/scheduler.php --list    # List scheduled tasks

# Manual
php cli/migrate.php
php cli/dedup.php --scan
php cli/scheduler.php --list
```

## API

Interactive documentation available at `/api/docs`. Authenticate with API keys from the admin panel:

```bash
curl -H "Authorization: Bearer YOUR_API_KEY" http://localhost:8080/api/models
```

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Upload size limit | Set `MESHSILO_MAX_UPLOAD_SIZE` (bytes). Also set `client_max_body_size` in your reverse proxy. |
| Permission errors | `chown -R www-data:www-data storage/` |
| Database errors | `docker exec meshsilo php cli/migrate.php` |
| Missing columns/tables | Run migrations (see above) |

**Logs:**

```bash
# Docker
docker compose logs -f meshsilo

# Manual
tail -f storage/logs/app.log
```

## Security

- Change the default admin password after installation
- Use HTTPS via reverse proxy in production
- Enable two-factor authentication for admin accounts
- Back up `storage/assets` and `storage/db` regularly

## License

[GNU Affero General Public License v3.0](https://www.gnu.org/licenses/agpl-3.0.html) (AGPL-3.0)
