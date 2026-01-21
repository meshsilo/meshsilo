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
├── browse.php             # Advanced browsing with filters, sort, pagination
├── tags.php               # Tags listing page
├── favorites.php          # User favorites page
├── edit-model.php         # Edit model details form
├── actions/               # Action handlers
│   ├── delete.php         # Delete model/parts handler
│   ├── download.php       # Download single file (tracks download count)
│   ├── download-all.php   # Download all parts as zip
│   ├── convert-part.php   # Convert STL to 3MF
│   ├── update-part.php    # Update part metadata (print type, notes, printed status)
│   ├── update-model.php   # Update model properties (archive, license, etc.)
│   ├── mass-action.php    # Bulk actions on parts
│   ├── add-part.php       # Add parts to existing model
│   ├── favorite.php       # Toggle favorite status
│   ├── tag.php            # Add/remove tags from models
│   └── check-duplicates.php # Check for duplicate files before upload
├── admin/                 # Admin pages (require admin permission)
│   ├── settings.php       # Site settings (including OIDC, php.ini)
│   ├── categories.php     # Manage categories
│   ├── collections.php    # Manage collections
│   ├── groups.php         # Manage permission groups
│   ├── users.php          # User management
│   ├── stats.php          # Storage statistics
│   ├── storage.php        # Storage management
│   └── activity.php       # Activity log viewer
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
├── nginx.conf.example     # Nginx configuration example
├── Dockerfile             # Docker container build file
├── docker-compose.yml     # Docker Compose configuration
└── docker/                # Docker configuration files
    ├── nginx.conf         # Nginx config for container
    ├── php-fpm.conf       # PHP-FPM pool configuration
    ├── supervisord.conf   # Process supervisor config
    └── entrypoint.sh      # Container startup script
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

## Features (Priority 1 & 2 - Implemented 2026-01-21)

### Tags System
- Tags with customizable colors stored in `tags` table
- Many-to-many relationship via `model_tags` junction table
- Auto-complete tag input with suggestions
- Filter models by tag on browse page

### Favorites/Bookmarks
- Logged-in users can favorite models
- `favorites` table tracks user-model relationships
- Favorites page at `/favorites.php`

### Themes
- Light/dark theme toggle in header
- User preference saved in cookie (`silo_theme`)
- CSS variables for easy theming in `css/style.css`

### Browse & Sort
- Advanced browse page with filtering by category/tag
- Sort by: newest, oldest, name, size, parts, downloads
- Grid/list view toggle with cookie persistence
- Pagination with configurable items per page

### Download Tracking
- `download_count` column on models table
- Incremented on file download
- Displayed on model page and browse page

### Activity Log
- `activity_log` table tracks user actions
- Admin panel at `/admin/activity.php`
- Configurable retention period
- Tracks: uploads, downloads, edits, deletes, favorites, tags

### Model Metadata
- License field with Creative Commons options
- Archive/hide models (non-destructive)
- Notes field per part
- Printed status tracking with timestamp

### Recently Viewed
- `recently_viewed` table tracks views
- Works for both logged-in users and sessions
- Displayed on homepage

### Duplicate Detection
- Hash-based duplicate checking before upload
- Similar name matching
- API endpoint at `/actions/check-duplicates.php`

### Lazy Loading
- 3D previews load via IntersectionObserver
- Shimmer placeholder effect during load
- Theme-aware background colors

## Database Tables (New)

```sql
-- Tags
tags (id, name, color, created_at)
model_tags (model_id, tag_id)

-- Favorites
favorites (id, user_id, model_id, created_at)

-- Activity Log
activity_log (id, user_id, action, entity_type, entity_id, entity_name, details, ip_address, created_at)

-- Recently Viewed
recently_viewed (id, user_id, session_id, model_id, viewed_at)

-- Models table additions:
-- download_count, license, is_archived, notes, is_printed, printed_at
```
