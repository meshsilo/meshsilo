# Silo

A self-hosted web-based Digital Asset Manager (DAM) for 3D print files (.stl, .3mf).

## Features

### Model Management
- **Upload Support**: Upload individual STL/3MF files or ZIP archives containing multiple parts
- **Multi-part Models**: Organize models with multiple parts from ZIP uploads
- **Add Parts**: Add additional parts to existing models
- **3D Preview**: Interactive 3D preview of models using Three.js
- **Automatic Thumbnails**: Lazy-loaded 3D model thumbnails on browse pages
- **Print Type Tagging**: Tag parts as FDM or SLA for easy filtering

### File Management
- **STL to 3MF Conversion**: Convert STL files to 3MF format for better compression
- **File Deduplication**: Content-based deduplication to save storage space
- **Bulk Actions**: Mass select parts for bulk print type changes or deletion
- **Download Options**: Download individual parts or all parts as ZIP

### Organization
- **Categories**: Organize models into categories (Functional, Decorative, Tools, etc.)
- **Collections**: Group models by collection (e.g., Gridfinity, Voron)
- **Search**: Full-text search across model names and metadata
- **Creator Attribution**: Track original creators and source URLs

### User Management
- **Local Accounts**: Built-in username/password authentication
- **OIDC/SSO**: Single Sign-On support via OpenID Connect
- **Groups & Permissions**: Role-based access control with customizable groups
- **Granular Permissions**: Control upload, delete, edit, convert, and admin access

### Administration
- **Installation Wizard**: Easy setup with guided installation
- **Database Support**: SQLite (default) or MySQL
- **Storage Statistics**: Monitor disk usage, file counts, and deduplication savings
- **Log Viewer**: View authentication, upload, and admin action logs
- **Collapsible UI**: Clean admin interface with collapsible sections

## Requirements

- PHP 7.4 or higher
- PDO extension
- JSON extension
- ZIP extension (for multi-part uploads)
- Write access to `assets/`, `logs/`, and `db/` directories

## Installation

1. Clone the repository to your web server:
   ```bash
   git clone https://github.com/Azurith93/Silo.git
   cd Silo
   ```

2. Ensure the web server has write permissions:
   ```bash
   chmod 755 assets logs db
   ```

3. Visit `install.php` in your browser and follow the installation wizard:
   - Check system requirements
   - Configure database (SQLite or MySQL)
   - Create admin account
   - Configure site URL (optional, for reverse proxy)
   - Set up OIDC/SSO (optional)

4. Delete `install.php` after installation (or use the self-delete option in the wizard)

## Web Server Configuration

### Apache
The included `.htaccess` file handles URL configuration. Ensure `mod_rewrite` is enabled.

### Nginx
See `nginx.conf.example` for sample configuration.

## Permissions System

| Permission | Description |
|------------|-------------|
| upload | Upload new models |
| delete | Delete models and parts |
| edit | Edit model metadata |
| convert | Convert STL to 3MF |
| view_stats | View storage statistics |
| manage_users | Manage user accounts |
| manage_groups | Manage permission groups |
| manage_categories | Manage categories |
| manage_collections | Manage collections |
| manage_settings | Manage site settings |
| view_logs | View system logs |
| admin | Full admin access (all permissions) |

## Directory Structure

```
Silo/
├── admin/              # Admin pages
├── assets/             # Uploaded model files
├── cli/                # Command-line scripts
├── css/                # Stylesheets
├── db/                 # Database files (SQLite)
├── images/             # UI images
├── includes/           # PHP includes
├── js/                 # JavaScript files
├── logs/               # Log files
├── install.php         # Installation wizard
└── config.local.php    # Local configuration (created during install)
```

## Development

Run locally with PHP's built-in server:
```bash
php -S localhost:8000
```

## License

MIT License
