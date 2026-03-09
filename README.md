# MeshSilo

A self-hosted web-based Digital Asset Manager (DAM) for 3D model files. Manage, organize, and preview your 3D printing files with an intuitive interface.

## Overview

MeshSilo is designed for makers, 3D printing enthusiasts, and teams who need to organize large collections of 3D models. It supports common formats including STL, 3MF, OBJ, PLY, AMF, GLTF/GLB, FBX, STEP, and more.

## Features

### 🎨 Model Management
- **Multi-Format Support**: STL, 3MF, OBJ, PLY, AMF, GLB, GLTF, FBX, DAE, BLEND, STEP, IGES, 3DS, DXF, OFF, X3D
- **Interactive 3D Preview**: View models in your browser with Three.js
- **Multi-Part Models**: Organize models with multiple components
- **Lazy-Loaded Thumbnails**: Fast browsing with automatically generated previews
- **Bulk Upload**: Upload ZIP archives containing multiple parts
- **Add Parts**: Add components to existing models
- **Version Control**: Track model versions with upload history
- **Download Tracking**: Monitor download counts per model
- **Model Annotations**: Add notes and markers directly on 3D models

### 📁 Organization & Discovery
- **Categories**: Organize by type (Functional, Decorative, Tools, etc.)
- **Collections**: Group related models (e.g., Gridfinity, Voron Mods)
- **Smart Tags**: Tag models with custom colored labels
- **Tag Autocomplete**: Quick tagging with suggestion system
- **Full-Text Search**: Search across names, descriptions, and metadata
- **Advanced Filtering**: Filter by category, tag, license, status
- **Favorites/Bookmarks**: Save frequently accessed models
- **Recently Viewed**: Quick access to your browsing history
- **Duplicate Detection**: Find similar models before upload
- **Saved Searches**: Save and share search queries

### 👁️ Browsing & Views
- **Grid/List View**: Toggle between compact and detailed layouts
- **Sort Options**: By date, name, size, parts count, downloads
- **Pagination**: Configurable items per page
- **Responsive Design**: Works on desktop, tablet, and mobile
- **Dark/Light Theme**: Toggle with preference persistence
- **Collapsible Sections**: Clean, organized interface

### 📊 Metadata & Details
- **License Management**: Track Creative Commons and custom licenses
- **Creator Attribution**: Record original creator and source URLs
- **Archive/Hide**: Non-destructive model hiding
- **Notes per Part**: Add notes to individual components
- **Dimensions**: Automatic calculation of model dimensions
- **File Statistics**: Size, part count, upload date
- **Model Attachments**: Attach images and PDFs to models
- **External Links**: Track source URLs and related resources
- **Ratings**: Community-driven quality ratings

### 🔐 User Management & Security
- **Local Authentication**: Built-in username/password system
- **Two-Factor Authentication**: TOTP-based 2FA
- **Role-Based Access Control**: Granular permission groups
- **API Keys**: Secure API access with key management
- **Session Management**: Secure session handling
- **CSRF Protection**: Cross-site request forgery prevention
- **Rate Limiting**: Configurable rate limits for API and login attempts
- **Audit Trail**: Comprehensive logging of security-relevant actions

### 🛠️ Administration
- **Installation Wizard**: Guided setup process
- **Database Support**: SQLite (default) or MySQL
- **Storage Statistics**: Monitor disk usage and deduplication savings
- **Activity Logging**: Track user actions, uploads, downloads
- **Audit Trail**: Comprehensive action logging
- **User Management**: Create, edit, deactivate users
- **Group Management**: Configure permission groups
- **Category Management**: Create and organize categories
- **Collection Management**: Manage model collections
- **Settings Panel**: Configure site name, description, limits
- **Database Tools**: Optimize and manage database
- **Health Dashboard**: System health monitoring
- **Scheduler**: Background task management
- **Plugin System**: Extend functionality with plugins

### 🚀 Performance & Optimization
- **File Deduplication**: Content-based dedup to save storage
- **Route Caching**: Fast page loads with cached routes
- **Asset Versioning**: Browser cache optimization
- **Lazy Loading**: On-demand resource loading
- **Database Indexing**: Optimized queries with full-text search
- **Memory Optimization**: 2GB PHP memory limit
- **Persistent Connections**: Database connection pooling
- **Query Profiling**: Performance monitoring

### 🔌 Integration & API
- **REST API**: Full-featured API for automation
- **GraphQL API**: Flexible query language at `/api/graphql`
- **OpenAPI Documentation**: Interactive API docs at `/api/docs`
- **Batch Operations**: Mass actions via API
- **Import/Export**: Backup and migrate your data
- **CLI Tools**: Command-line management utilities
- **Plugin System**: Extend with custom plugins

### 📦 File Operations
- **STL to 3MF Conversion**: Compress STL files
- **Bulk Actions**: Mass operations on selected models
- **ZIP Downloads**: Download all parts together
- **Individual Downloads**: Single file downloads
- **Upload Validation**: Size and type checking
- **Duplicate Prevention**: Hash-based duplicate detection

## Requirements

- **PHP**: 8.1 or higher
- **Extensions**: PDO, JSON, ZIP, GD (for thumbnails)
- **Database**: SQLite (bundled) or MySQL 5.7+
- **Web Server**: Apache 2.4+ or Nginx 1.18+
- **Disk Space**: Depends on your 3D model collection
- **Memory**: 2GB PHP memory limit recommended

## Installation

### Docker (Recommended)

Multi-architecture images are available for `amd64` and `arm64`.

#### Quick Start with Docker Compose

1. Create a `docker-compose.yml` file:

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
    environment:
      # Site Configuration
      MESHSILO_SITE_NAME: MeshSilo
      MESHSILO_SITE_DESCRIPTION: 3D Model Library

      # Upload Limits (in bytes, default 100MB)
      MESHSILO_MAX_UPLOAD_SIZE: 104857600

      # Optional: External URL for reverse proxy
      # MESHSILO_SITE_URL: https://models.example.com
    restart: unless-stopped

volumes:
  meshsilo_assets:
  meshsilo_db:
  meshsilo_logs:
```

2. Start the container:
   ```bash
   docker-compose up -d
   ```

3. Access MeshSilo at `http://localhost:8080` and complete the installation wizard.

#### Docker Run Command

```bash
docker run -d \
  --name meshsilo \
  -p 8080:80 \
  -v meshsilo_assets:/var/www/meshsilo/storage/assets \
  -v meshsilo_db:/var/www/meshsilo/storage/db \
  -v meshsilo_logs:/var/www/meshsilo/storage/logs \
  --restart unless-stopped \
  ghcr.io/meshsilo/meshsilo:latest
```

#### Using MySQL Instead of SQLite

```yaml
environment:
  MESHSILO_DB_TYPE: mysql
  MESHSILO_DB_HOST: mysql
  MESHSILO_DB_NAME: meshsilo
  MESHSILO_DB_USER: meshsilo
  MESHSILO_DB_PASS: your-secure-password
```

Add a MySQL service:

```yaml
services:
  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root-password
      MYSQL_DATABASE: meshsilo
      MYSQL_USER: meshsilo
      MYSQL_PASSWORD: your-secure-password
    volumes:
      - mysql_data:/var/lib/mysql
    restart: unless-stopped

volumes:
  mysql_data:
```

#### Environment Variables

##### Core Configuration

| Variable | Description | Default |
|----------|-------------|---------|
| `MESHSILO_DB_TYPE` | Database type: `sqlite` or `mysql` | `sqlite` |
| `MESHSILO_DB_HOST` | MySQL host | `localhost` |
| `MESHSILO_DB_NAME` | MySQL database name | `meshsilo` |
| `MESHSILO_DB_USER` | MySQL username | `meshsilo` |
| `MESHSILO_DB_PASS` | MySQL password | - |
| `MESHSILO_SITE_NAME` | Site name displayed in UI | `MeshSilo` |
| `MESHSILO_SITE_DESCRIPTION` | Site description | `3D Print File Manager` |
| `MESHSILO_SITE_URL` | External URL (for reverse proxy) | - |

##### Upload & Storage

| Variable | Description | Default |
|----------|-------------|---------|
| `MESHSILO_MAX_UPLOAD_SIZE` | Max upload size in bytes | `104857600` (100MB) |
| `MESHSILO_ALLOWED_EXTENSIONS` | Comma-separated file extensions | `stl,3mf,obj,ply,amf,gcode,glb,gltf,fbx,dae,blend,step,stp,iges,igs,3ds,dxf,off,x3d,zip` |
| `MESHSILO_DEDUP_ENABLED` | Enable file deduplication | `true` |

##### Development & Debugging

| Variable | Description | Default |
|----------|-------------|---------|
| `MESHSILO_ENABLE_QUERY_STATS` | Show query performance stats in footer | `false` |

#### Docker Volumes

| Volume Path | Description | Backup Priority |
|-------------|-------------|-----------------|
| `/var/www/meshsilo/storage/assets` | Uploaded 3D model files | **Critical** |
| `/var/www/meshsilo/storage/db` | SQLite database and config | **Critical** |
| `/var/www/meshsilo/storage/logs` | Application and error logs | Low |

**Important**: Always backup the `assets` and `db` volumes before upgrading!

#### Reverse Proxy Configuration

##### Nginx

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

Set `MESHSILO_SITE_URL=https://models.example.com` in your environment.

##### Apache

```apache
<VirtualHost *:443>
    ServerName models.example.com

    SSLEngine on
    SSLCertificateFile /path/to/cert.pem
    SSLCertificateKeyFile /path/to/key.pem

    ProxyPreserveHost On
    ProxyPass / http://localhost:8080/
    ProxyPassReverse / http://localhost:8080/

    <Proxy *>
        Order allow,deny
        Allow from all
    </Proxy>
</VirtualHost>
```

### Manual Installation

1. **Clone the repository:**
   ```bash
   git clone https://github.com/meshsilo/meshsilo.git
   cd MeshSilo
   ```

2. **Set permissions:**
   ```bash
   chmod 755 storage/assets storage/logs storage/db storage/cache
   chown -R www-data:www-data storage/
   ```

3. **Configure web server:**

   **Apache**: Ensure `mod_rewrite` is enabled. The `.htaccess` file is included.

   **Nginx**: Copy and configure `nginx.conf.example`:
   ```bash
   cp nginx.conf.example /etc/nginx/sites-available/meshsilo
   ln -s /etc/nginx/sites-available/meshsilo /etc/nginx/sites-enabled/
   nginx -t && systemctl reload nginx
   ```

4. **Run the installer:**

   Visit `http://your-server/install.php` in your browser and follow the wizard:
   - System requirements check
   - Database configuration (SQLite or MySQL)
   - Admin account creation
   - Site URL configuration

5. **Post-installation:**

   Delete or rename `install.php` for security:
   ```bash
   rm install.php
   ```

## Permissions System

MeshSilo uses a role-based permission system with groups:

| Permission | Description |
|------------|-------------|
| `upload` | Upload new models and add parts |
| `delete` | Delete models and parts |
| `edit` | Edit model metadata, tags, categories |
| `convert` | Convert STL files to 3MF |
| `view_stats` | View storage statistics |
| `manage_users` | Create, edit, deactivate users |
| `manage_groups` | Manage permission groups |
| `manage_categories` | Create and manage categories |
| `manage_collections` | Create and manage collections |
| `manage_settings` | Configure site settings |
| `view_logs` | View activity and audit logs |
| `admin` | Full administrative access (all permissions) |

Create custom groups in the admin panel and assign specific permissions.

## CLI Tools

MeshSilo includes command-line tools for automation and maintenance:

```bash
# Database migrations
./bin/meshsilo migrate              # Run pending migrations
./bin/meshsilo migrate --status     # Check migration status
./bin/meshsilo migrate --backup     # Backup before migrating

# File deduplication
./bin/meshsilo dedup --scan         # Find duplicate files
./bin/meshsilo dedup --run          # Run deduplication

# Scheduled tasks
./bin/meshsilo scheduler            # Run due tasks
./bin/meshsilo scheduler --list     # List all scheduled tasks
```

Or use the PHP scripts directly:

```bash
php cli/migrate.php
php cli/dedup.php --scan
php cli/scheduler.php --list
```

## API

MeshSilo provides a REST API for automation and integration.

### API Documentation

Access interactive API documentation at `/api/docs` when running.

### Authentication

Use API keys generated in the admin panel:

```bash
curl -H "Authorization: Bearer YOUR_API_KEY" \
  http://localhost:8080/api/models
```

### Example Endpoints

- `GET /api/models` - List all models
- `GET /api/models/{id}` - Get model details
- `POST /api/models` - Upload new model
- `GET /api/categories` - List categories
- `GET /api/tags` - List tags
- `GET /api/collections` - List collections
- `GET /api/stats` - Get storage statistics

## Directory Structure

```
MeshSilo/
├── index.php              # Front controller
├── install.php            # Installation wizard
├── health.php             # Health check endpoint
├── app/                   # Application code
│   ├── pages/             # Page controllers
│   ├── actions/           # Form/AJAX handlers
│   ├── admin/             # Admin pages
│   └── api/               # REST API
├── public/                # Public assets
│   ├── css/               # Stylesheets
│   ├── js/                # JavaScript
│   └── images/            # UI images
├── storage/               # Persistent data (Docker volumes)
│   ├── assets/            # Uploaded 3D models
│   ├── db/                # Database files
│   ├── logs/              # Application logs
│   └── cache/             # Route/asset cache
├── includes/              # Core libraries
│   ├── config.php         # Configuration
│   ├── Router.php         # URL routing
│   ├── db.php             # Database layer
│   ├── auth.php           # Authentication
│   └── middleware/        # Route middleware
├── cli/                   # CLI scripts
├── bin/                   # CLI entry points
├── migrations/            # Database migrations
├── docker/                # Docker configuration
└── docs/                  # Documentation
```

## Development

### Local Development Server

```bash
php -S localhost:8000
```

Access at `http://localhost:8000`

### Development Mode

Enable query statistics and debugging:

```bash
export MESHSILO_ENABLE_QUERY_STATS=true
php -S localhost:8000
```

## Upgrading

### Docker

```bash
docker-compose pull
docker-compose up -d
```

The container automatically runs migrations on startup.

### Manual

```bash
git pull origin main
php cli/migrate.php
```

## Backup & Restore

### Backup

**Docker:**
```bash
# Backup volumes
docker run --rm \
  -v meshsilo_assets:/data/assets \
  -v meshsilo_db:/data/db \
  -v $(pwd):/backup \
  alpine tar czf /backup/meshsilo-backup-$(date +%Y%m%d).tar.gz -C /data .
```

**Manual:**
```bash
tar czf meshsilo-backup.tar.gz storage/assets storage/db
```

### Restore

**Docker:**
```bash
docker run --rm \
  -v meshsilo_assets:/data/assets \
  -v meshsilo_db:/data/db \
  -v $(pwd):/backup \
  alpine tar xzf /backup/meshsilo-backup.tar.gz -C /data
```

**Manual:**
```bash
tar xzf meshsilo-backup.tar.gz
```

## Troubleshooting

### Upload Size Limit

Increase PHP limits in Docker:
```yaml
environment:
  MESHSILO_MAX_UPLOAD_SIZE: 524288000  # 500MB
```

### Permission Errors

Check storage directory permissions:
```bash
chmod -R 775 storage/
chown -R www-data:www-data storage/
```

### Database Issues

Run migrations:
```bash
docker-compose exec meshsilo php cli/migrate.php
```

Or manually:
```bash
php cli/migrate.php --backup
```

### View Logs

**Docker:**
```bash
docker-compose logs -f meshsilo
```

**Manual:**
```bash
tail -f storage/logs/app.log
tail -f storage/logs/php-error.log
```

## Security

- Change default admin password after installation
- Use HTTPS in production (reverse proxy)
- Keep PHP and dependencies updated
- Enable two-factor authentication for admin accounts
- Restrict file upload extensions
- Regular backups of `storage/assets` and `storage/db`
- Use strong database passwords
- Enable CSRF protection (enabled by default)

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

## License

This project is licensed under the [GNU Affero General Public License v3.0](https://www.gnu.org/licenses/agpl-3.0.html) (AGPL-3.0).

## Support

- **Issues**: [GitHub Issues](https://github.com/meshsilo/meshsilo/issues)
- **Discussions**: [GitHub Discussions](https://github.com/meshsilo/meshsilo/discussions)

## Acknowledgments

- Built with [Three.js](https://threejs.org/) for 3D preview
- Inspired by Thingiverse and Printables
- Community contributions and feedback
