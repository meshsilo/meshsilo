# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Silo** is a web-based Digital Asset Manager (DAM) for 3D print files (.stl, .3mf).

## Architecture

- **Frontend**: PHP with HTML/CSS
- **Database**: SQLite (schema in `db/schema.sql`)
- **File Storage**: Uploaded models stored in `assets/`

## Project Structure

```
Silo/
├── index.php              # Homepage
├── categories.php         # Categories listing
├── upload.php             # Upload form
├── login.php              # Login page
├── admin/
│   └── settings.php       # Admin settings
├── includes/
│   ├── config.php         # Site configuration
│   ├── logger.php         # Error logging utilities
│   ├── db.php             # Database connection
│   ├── auth.php           # Authentication middleware
│   ├── header.php         # Shared header/nav
│   └── footer.php         # Shared footer
├── logs/                  # Error logs (gitignored)
├── css/style.css          # Styles
├── assets/                # Uploaded 3D model files
├── db/schema.sql          # Database schema
└── images/                # UI images/icons
```

## Development

Requires a PHP server. Run locally with:
```bash
php -S localhost:8000
```

## Database

Models table stores metadata; actual files go in `assets/`. See `db/schema.sql` for full schema.

## Logging

Error logging is configured in `includes/logger.php`. Logs are written to `logs/error.log`.

Available functions:
- `logError($message, $context)` - Log errors
- `logWarning($message, $context)` - Log warnings
- `logInfo($message, $context)` - Log info messages
- `logDebug($message, $context)` - Log debug messages
- `logException($e, $context)` - Log exceptions with stack trace

Logs auto-rotate at 5MB.
