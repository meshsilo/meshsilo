# MeshSilo Upgrade Guide

This guide covers upgrading MeshSilo from any pre-v1 development version to v1.0.0.

## Table of Contents

- [Prerequisites](#prerequisites)
- [Quick Upgrade (Docker)](#quick-upgrade-docker)
- [Quick Upgrade (Manual Installation)](#quick-upgrade-manual-installation)
- [Detailed Upgrade Steps](#detailed-upgrade-steps)
- [Pre-v1 to v1.0.0 Migration Details](#pre-v1-to-v100-migration-details)
- [Troubleshooting](#troubleshooting)
- [Rollback Procedure](#rollback-procedure)

---

## Prerequisites

Before upgrading, ensure you have:

1. **Admin access** to the server/container
2. **Database backup** (the upgrade process can create one automatically)
3. **Downtime window** of 5-15 minutes depending on database size
4. **PHP 8.1+** installed (verify with `php -v`)

### Check Current Version

```bash
# In the MeshSilo directory
grep "MESHSILO_VERSION" includes/config.php
```

Or check the footer of the admin panel which displays the version.

---

## Quick Upgrade (Docker)

For Docker installations, the upgrade process is straightforward:

```bash
# 1. Pull the latest image
docker pull ghcr.io/OWNER/silo:1.0.0

# 2. Create a backup before upgrading
docker exec meshsilo php cli/migrate.php --backup

# 3. Stop the container
docker compose down

# 4. Update docker-compose.yml to use new version (if not using :latest)
# image: ghcr.io/OWNER/silo:1.0.0

# 5. Start with new version
docker compose up -d

# 6. Run migrations
docker exec meshsilo php cli/migrate.php

# 7. Verify the upgrade
docker exec meshsilo php cli/migrate.php --status
```

---

## Quick Upgrade (Manual Installation)

For non-Docker installations:

```bash
# 1. Navigate to MeshSilo directory
cd /path/to/meshsilo

# 2. Create backup
php cli/migrate.php --backup

# 3. Back up your configuration
cp storage/db/config.local.php /tmp/config.local.php.bak

# 4. Download and extract new version
# (Replace with actual download URL)
wget https://github.com/OWNER/silo/releases/download/v1.0.0/meshsilo-1.0.0.tar.gz
tar -xzf meshsilo-1.0.0.tar.gz --strip-components=1

# 5. Restore configuration
cp /tmp/config.local.php.bak storage/db/config.local.php

# 6. Run migrations
php cli/migrate.php

# 7. Clear cache (if using PHP-FPM/opcache)
# Restart PHP-FPM or clear opcache as needed
```

---

## Detailed Upgrade Steps

### Step 1: Pre-Upgrade Verification

Check the current migration status to understand what changes will be applied:

```bash
php cli/migrate.php --status
```

Example output:
```
Database type: SQLITE

Migration Status
============================================================

  ✓ Tags table
  ✓ Model-Tags junction table
  ✓ Favorites table
  ○ Audit log table (pending)
  ○ Retention policies table (pending)

------------------------------------------------------------
Applied: 45, Pending: 12

Schema version: 2025-01-15 10:30:00
Last migration: 2025-01-15 10:30:00
```

### Step 2: Create Database Backup

Always create a backup before upgrading:

```bash
# Automatic backup via migrate tool
php cli/migrate.php --backup

# Or manual backup for SQLite
cp storage/db/meshsilo.db storage/db/backup_$(date +%Y%m%d_%H%M%S).db

# Or manual backup for MySQL
mysqldump -u user -p meshsilo > backup_$(date +%Y%m%d_%H%M%S).sql
```

The `--backup` flag creates a timestamped backup in:
- SQLite: `storage/db/backup_pre_migration_YYYY-MM-DD_HH-MM-SS.db`
- MySQL: Uses mysqldump (requires mysqldump binary available)

### Step 3: Preview Changes (Dry Run)

Preview what migrations will be applied without making changes:

```bash
php cli/migrate.php --dry-run
```

Example output:
```
Database type: SQLITE

12 pending migration(s) found:
  - Audit log table
  - Retention policies table
  - Legal holds table
  - Model attachments table
  ...

[DRY RUN] No changes will be made.
```

### Step 4: Apply Migrations

Run the migration with backup:

```bash
php cli/migrate.php --backup --verbose
```

The `--verbose` flag shows descriptions of each migration as it runs.

### Step 5: Verify Upgrade

After migration, verify the upgrade was successful:

```bash
# Check migration status
php cli/migrate.php --status

# Check database schema
php cli/check-schema.php --all

# Verify version
grep "MESHSILO_VERSION" includes/config.php
```

### Step 6: Test Core Functionality

After upgrading, test these key features:

1. **Login** - Verify authentication works
2. **Browse models** - Check model listing loads
3. **Upload** - Test uploading a small model
4. **3D Viewer** - Verify model preview works
5. **Admin panel** - Access admin settings

---

## Pre-v1 to v1.0.0 Migration Details

### Database Schema Changes

The v1.0.0 release includes these schema additions/changes:

#### New Tables

| Table | Purpose |
|-------|---------|
| `audit_log` | Enhanced security audit logging |
| `model_attachments` | Document/image attachments for models |
| `model_links` | External links attached to models |
| `password_resets` | Password reset token storage |
| `saved_searches` | Saved search queries |
| `api_keys` | API key management |
| `rate_limits` | Rate limiting tracking |
| `sessions` | User session management |
| `two_factor` | Two-factor authentication data |
| `scheduled_tasks` | Background task definitions |
| `task_log` | Task execution history |
| `plugins` | Installed plugins |

#### New Columns

| Table | Column | Purpose |
|-------|--------|---------|
| `users` | `totp_secret`, `totp_enabled` | Two-factor authentication |
| `users` | `last_login_at`, `last_login_ip` | Login tracking |
| `models` | `rating`, `rating_count` | Model ratings |
| `models` | `approval_status`, `approved_by`, `approved_at` | Approval workflow |

#### Performance Indexes

New indexes added for common query patterns:
- `idx_models_parent_id` - Multi-part model queries
- `idx_models_created_at` - Chronological sorting
- `idx_model_tags_model_id` - Tag filtering
- `idx_model_categories_model_id` - Category filtering
- `idx_favorites_user_id` - User favorites lookup

### Configuration Changes

#### Removed Settings

The following settings have been removed and will be automatically cleaned up:
- `license_email`
- `license_key`
- `license_cache`
- `license_last_sync`

#### New Admin Features

v1.0.0 adds these admin panel pages:
- Audit Log (`/admin/audit-log`)
- Health Dashboard (`/admin/health`)
- Security Headers (`/admin/security-headers`)
- Scheduler Management (`/admin/scheduler`)
- Plugin Management (`/admin/plugins`)

### File Structure Changes

No changes to the file structure that would affect custom themes or configurations.

### API Changes

The v1.0.0 API is backward compatible with pre-v1 versions. New endpoints added:
- `/api/graphql` - GraphQL API for flexible queries
- `/api/docs` - OpenAPI documentation
- `/api/plugins` - Plugin management

---

## Troubleshooting

### Migration Fails

If a migration fails, the process stops to prevent partial updates:

```bash
# Check the error message, then fix the issue and retry
php cli/migrate.php --verbose

# If a migration is partially applied, use --force to continue
php cli/migrate.php --force
```

**Warning**: Using `--force` skips failed migrations. Only use this if you understand the failure reason.

### "Column already exists" Error

This indicates a migration was partially applied. The migration system checks for this, but if it occurs:

```bash
# Check current schema
php cli/check-schema.php --all

# Migrations are idempotent - running again should skip existing columns
php cli/migrate.php
```

### "Table does not exist" Error

If core tables are missing, the installation may be incomplete:

```bash
# For fresh installs, use the install wizard
# Access: http://your-server/install.php

# Or run migrations which will create missing tables
php cli/migrate.php
```

### Web Interface Shows "Database Setup Required"

This occurs when web requests detect missing core tables. The web interface does NOT run migrations automatically for safety. Run migrations via CLI:

```bash
php cli/migrate.php
```

Or in Docker:
```bash
docker exec meshsilo php cli/migrate.php
```

### Permission Errors

If you see permission errors during migration:

```bash
# Fix storage directory permissions
chown -R www-data:www-data storage/
chmod -R 755 storage/
```

### MySQL Connection Issues

For MySQL databases, ensure:
1. Database credentials in `config.local.php` are correct
2. MySQL user has ALTER, CREATE, DROP, INDEX privileges
3. Database exists and is accessible

```bash
# Test connection
php -r "require 'includes/config.php'; echo 'Connected: ' . getDB()->getType();"
```

---

## Rollback Procedure

If you need to rollback after an upgrade:

### SQLite Rollback

```bash
# Stop the application/container
docker compose down  # or stop your web server

# Restore backup
cp storage/db/backup_pre_migration_YYYY-MM-DD_HH-MM-SS.db storage/db/meshsilo.db

# Restore application files (if changed)
# Use your backup or git checkout to previous version

# Restart
docker compose up -d
```

### MySQL Rollback

```bash
# Stop the application
docker compose down

# Restore database from backup
mysql -u user -p meshsilo < backup_YYYYMMDD_HHMMSS.sql

# Restore application files
# Use your backup or git checkout to previous version

# Restart
docker compose up -d
```

### Important Notes

1. **Data created after upgrade will be lost** when rolling back
2. **Test the rollback procedure** in a staging environment first
3. **Keep multiple backups** before major upgrades
4. After rollback, the migration status will show pending migrations again

---

## Version-Specific Notes

### Upgrading from Development Versions (pre-0.9)

Very early development versions may have incompatible database schemas. If upgrading from a development version prior to 0.9:

1. **Export your data** using the admin panel's export feature
2. **Fresh install** v1.0.0
3. **Import your data** using the import feature

### Upgrading from 0.9.x to 1.0.0

This is a straightforward migration:
```bash
php cli/migrate.php --backup
```

All migrations are backward compatible and additive.

---

## Post-Upgrade Tasks

After a successful upgrade:

1. **Clear browser cache** - CSS/JS changes may require cache clear
2. **Test notifications** - If using email, verify SMTP still works
3. **Check scheduled tasks** - Verify scheduler is running
4. **Review new features** - Explore new admin panel options
5. **Update documentation** - Note the upgrade date for your records

---

## Getting Help

If you encounter issues during upgrade:

1. **Check the logs**: `storage/logs/error.log`
2. **Run diagnostics**: `php cli/check-schema.php --all`
3. **Review migration status**: `php cli/migrate.php --status`
4. **GitHub Issues**: Open an issue with error details

Include in your issue:
- MeshSilo version (before and after)
- Database type (SQLite/MySQL)
- Full error message
- Output of `php cli/migrate.php --status`
