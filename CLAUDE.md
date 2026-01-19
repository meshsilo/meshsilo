# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Silo** is a web-based Digital Asset Manager (DAM) for 3D print files (.stl, .3mf).

## Architecture

- **Frontend**: PHP with HTML/CSS/JavaScript
- **Database**: SQLite or MySQL (configurable during installation)
- **File Storage**: Uploaded models stored in `assets/`
- **Authentication**: Local accounts and OIDC/SSO support

## Project Structure

```
Silo/
├── index.php              # Homepage/browse models
├── model.php              # Single model view
├── categories.php         # Categories listing
├── category.php           # Single category view
├── collections.php        # Collections listing
├── collection.php         # Single collection view
├── search.php             # Search page
├── upload.php             # Upload form (requires upload permission)
├── login.php              # Login page
├── logout.php             # Logout handler
├── install.php            # Installation wizard
├── oidc-callback.php      # OIDC authentication callback
├── actions/               # Action handlers
│   ├── delete.php         # Delete model/parts handler
│   ├── download.php       # Download single file
│   ├── download-all.php   # Download all parts as zip
│   ├── convert-part.php   # Convert STL to 3MF
│   ├── update-part.php    # Update part metadata
│   ├── mass-action.php    # Bulk actions on parts
│   └── add-part.php       # Add parts to existing model
├── admin/                 # Admin pages (require admin permission)
│   ├── settings.php       # Site settings (including OIDC, php.ini)
│   ├── categories.php     # Manage categories
│   ├── collections.php    # Manage collections
│   ├── groups.php         # Manage permission groups
│   ├── users.php          # User management
│   ├── stats.php          # Storage statistics
│   └── storage.php        # Storage management
├── includes/
│   ├── config.php         # Site configuration loader
│   ├── db.php             # Database abstraction (SQLite/MySQL)
│   ├── auth.php           # Session and authentication
│   ├── permissions.php    # Permission system
│   ├── oidc.php           # OIDC/SSO authentication
│   ├── logger.php         # Error logging utilities
│   ├── dedup.php          # File deduplication
│   ├── converter.php      # STL to 3MF conversion
│   ├── header.php         # Shared header/nav
│   ├── footer.php         # Shared footer
│   └── admin-sidebar.php  # Admin sidebar navigation
├── cli/
│   └── dedup.php          # CLI deduplication script
├── css/style.css          # Styles
├── js/
│   ├── main.js            # Main JavaScript
│   └── viewer.js          # 3D model viewer (Three.js)
├── logs/                  # Error logs (gitignored)
├── assets/                # Uploaded 3D model files
├── db/                    # Database files
│   ├── schema.sql         # Database schema reference
│   └── silo.db            # SQLite database (gitignored)
├── images/                # UI images/icons
├── config.local.php       # Local configuration (gitignored)
├── .htaccess              # Apache configuration
└── nginx.conf.example     # Nginx configuration example
```

## Installation

Run the installation wizard by visiting `install.php` in your browser. The wizard will:
1. Check system requirements
2. Configure database (SQLite or MySQL)
3. Create admin account
4. Configure site URL and OIDC (optional)

## Development

Requires a PHP server with PDO extension. Run locally with:
```bash
php -S localhost:8000
```

## Database

Supports both SQLite and MySQL. The database type is configured during installation and stored in `config.local.php`. The `includes/db.php` provides a unified abstraction layer.

## Logging

Error logging is configured in `includes/logger.php`. Logs are written to `logs/error.log`.

Available functions:
- `logError($message, $context)` - Log errors
- `logWarning($message, $context)` - Log warnings
- `logInfo($message, $context)` - Log info messages
- `logDebug($message, $context)` - Log debug messages
- `logException($e, $context)` - Log exceptions with stack trace

Logs auto-rotate at 5MB.

## Permissions

Permission system is in `includes/permissions.php`. Available permissions:
- `PERM_UPLOAD` - Upload new models
- `PERM_DELETE` - Delete models
- `PERM_EDIT` - Edit model details
- `PERM_VIEW_STATS` - View statistics page
- `PERM_ADMIN` - Full admin access

Permissions are assigned through groups (managed in admin/groups.php).

Helper functions:
- `hasPermission($perm)` - Check if user has permission
- `requirePermission($perm)` - Require permission or redirect
- `canUpload()`, `canDelete()`, `canEdit()`, `canViewStats()`, `isAdmin()` - Convenience checks

## Authentication

Supports two authentication methods:
1. **Local accounts** - Username/password stored in database
2. **OIDC/SSO** - OpenID Connect with external identity providers

OIDC configuration is managed in admin settings.

## File Deduplication

The system supports automatic file deduplication:
- Content-based hashing for 3MF files
- Scheduled deduplication via CLI (`cli/dedup.php`)
- Configure in admin settings
