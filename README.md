# MeshSilo

A self-hosted 3D model asset manager. Organize, preview, and share your 3D printing files.

Supports STL, 3MF, OBJ, PLY, GLTF/GLB, FBX, STEP, IGES, and more.

## Docker Installation

Multi-architecture images available for `amd64` and `arm64`.

Create a `docker-compose.yml`:

```yaml
services:
  meshsilo:
    image: ghcr.io/meshsilo/meshsilo:latest
    container_name: meshsilo
    restart: unless-stopped
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
      # - MESHSILO_SITE_URL=https://meshsilo.example.com
      - MESHSILO_DB_TYPE=mysql
      - MESHSILO_DB_HOST=db
      - MESHSILO_DB_PORT=3306
      - MESHSILO_DB_NAME=meshsilo
      - MESHSILO_DB_USER=meshsilo
      - MESHSILO_DB_PASS=meshsilo_password
    mem_limit: 4g
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/health"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 10s
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

```bash
docker compose up -d
```

Open `http://localhost:8080` and follow the installation wizard.

To use SQLite instead of MySQL, remove the `db` service, the `depends_on` section, and all `MESHSILO_DB_*` environment variables. The install wizard will default to SQLite.

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `MESHSILO_DB_TYPE` | `sqlite` or `mysql` | `sqlite` |
| `MESHSILO_DB_HOST` | MySQL host | `localhost` |
| `MESHSILO_DB_PORT` | MySQL port | `3306` |
| `MESHSILO_DB_NAME` | MySQL database name | `meshsilo` |
| `MESHSILO_DB_USER` | MySQL username | `meshsilo` |
| `MESHSILO_DB_PASS` | MySQL password | — |
| `MESHSILO_SITE_NAME` | Site name | `MeshSilo` |
| `MESHSILO_SITE_URL` | External URL (for reverse proxy) | — |
| `MESHSILO_MAX_UPLOAD_SIZE` | Max upload size in bytes | `104857600` (100MB) |
| `MESHSILO_DEDUP_ENABLED` | File deduplication | `true` |

## Upgrading

```bash
docker compose pull
docker compose up -d
```

Migrations run automatically on startup.

## Backup

```bash
docker run --rm \
  -v meshsilo_assets:/data/assets \
  -v meshsilo_db:/data/db \
  -v $(pwd):/backup \
  alpine tar czf /backup/meshsilo-$(date +%Y%m%d).tar.gz -C /data .
```

Critical volumes to back up: `storage/assets` (uploaded files) and `storage/db` (database and config).

## API

Interactive documentation available at `/api/docs`. Authenticate with API keys from the admin panel.

## License

[GNU Affero General Public License v3.0](https://www.gnu.org/licenses/agpl-3.0.html) (AGPL-3.0)
