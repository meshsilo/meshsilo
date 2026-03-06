# Changelog

All notable changes to MeshSilo will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Plugin System**: Extensible plugin architecture with plugin manager
- **GraphQL API**: Flexible query language at `/api/graphql`
- **Model Annotations**: Add notes and markers directly on 3D models
- **Model Ratings**: Community-driven quality ratings
- **Approval Workflow**: Model approval system for moderated environments
- **CLI Optimize Tool**: Performance optimization utility (`cli/optimize.php`)
- **404 Page**: Custom 404 error page

### Security
- **CSRF Protection**: Double-submit cookie pattern with `X-CSRF-Token` header validation on all state-changing requests
- **XSS Prevention**: Context-aware output escaping via `Sanitizer` class; Content Security Policy headers; sanitized SVG/HTML upload paths
- **Encryption**: AES-256-GCM encryption for sensitive data at rest; key rotation support; encrypted file storage backend
- **CORS Hardening**: Strict origin allowlist, preflight caching, credentials restricted to allowlisted origins
- **IDOR Prevention**: Resource ownership validation on all model/file access endpoints
- **GraphQL Security**: Query depth and complexity limits; introspection disabled in production; field-level authorization
- **SQL Injection**: Parameterized queries enforced across QueryBuilder; raw query surface area reduced
- **Rate Limiting**: Configurable per-IP and per-user limits on login, upload, API, and password-reset endpoints
- **SSRF Prevention**: URL allowlist validation on remote import and webhook endpoints
- **Query Caching**: Tag-based cache invalidation prevents stale privilege escalation paths
- **Database Indexes**: Added missing indexes on high-traffic foreign key columns to prevent denial-of-service via slow queries
- **Audit Logging**: Security events (login, failed auth, permission changes) written to tamper-evident audit log

### Fixed
- **QueryBuilder**: `update()` no longer discards SET bindings when building WHERE clauses
- **QueryBuilder**: `whereRaw()` preserves named placeholder keys instead of renaming them to positional aliases
- **QueryBuilder**: `increment()`/`decrement()` binds string column values with `PARAM_STR` instead of `PARAM_INT`
- **Encryption**: `encryptAllFiles()` and `decryptAllFiles()` nullable parameter declarations compatible with PHP 8.5

### Removed
- **Authentication Methods**: Removed OIDC/SSO, SAML 2.0, LDAP/Active Directory, OAuth2 Provider
- **Printing System**: Removed print queue, printer management, print analytics, print history
- **Analytics**: Removed RUM (Real User Monitoring) and usage analytics dashboard
- **Backup System**: Removed automated backup manager and cloud backup features
- **Retention Policies**: Removed data retention and auto-cleanup features
- **Webhooks**: Removed webhook system and integrations
- **Demo Mode**: Removed demo account and sample model system
- **SCIM Provisioning**: Removed SCIM user provisioning
- **G-code Support**: Removed G-code viewer and slicer integrations
- **Cost Calculator**: Removed print cost estimation
- **Mesh Analysis**: Removed mesh repair and volume calculation tools

### Changed
- Simplified admin interface with focus on core features
- Streamlined authentication to local accounts and 2FA only
- Reduced CSS bundle size significantly
- Updated documentation to reflect current feature set

### Technical Changes
- Added CI workflow (`.github/workflows/ci.yml`)
- Added `.gitattributes` and `.htaccess`
- Added comprehensive test suite (migration, permissions, router, upload validation, mail)
- Added database schema file (`storage/db/schema.sql`)
- Updated Composer dependencies
- Applied PSR-12 code style across all `includes/` files via PHPCBF (1695 violations fixed)
- All implicit nullable parameters updated for PHP 8.5 compatibility
- PHPStan level-5 static analysis passes with zero errors
- `storage/.encryption_key` added to `.gitignore` to prevent accidental key exposure

## [1.0.0] - 2026-02-02

### Added

#### Core Features
- Multi-format 3D model support (STL, 3MF, OBJ, PLY, AMF, GCODE, GLB, GLTF, FBX, DAE, STEP, IGES, and more)
- Interactive 3D preview with Three.js viewer
- Multi-part model organization with virtual folders
- Version control for model revisions
- Bulk upload via ZIP archives
- File deduplication with content-based hashing

#### Organization & Discovery
- Categories and Collections for model organization
- Smart tagging system with colored labels and autocomplete
- Full-text search across names, descriptions, and metadata
- Advanced filtering by category, tag, license, print status
- Favorites/bookmarks and recently viewed history
- Duplicate detection before upload

#### User Interface
- Responsive design for desktop, tablet, and mobile
- Grid and list view options
- Dark/light theme with preference persistence
- Collapsible sections for clean interface
- Lazy-loaded thumbnails for fast browsing
- Progressive Web App (PWA) support

#### Authentication & Security
- Local username/password authentication
- Two-factor authentication (TOTP)
- Role-based access control with permission groups
- API key management
- CSRF protection
- Rate limiting
- Audit logging
- Session management

#### Administration
- Installation wizard with guided setup
- SQLite (default) and MySQL database support
- User and group management
- Category and collection management
- Storage statistics and deduplication metrics
- Activity and audit logging
- Database optimization tools
- Scheduled task management
- Feature toggles for optional functionality
- Plugin management system
- Health monitoring dashboard

#### API & Integration
- REST API with OpenAPI documentation
- GraphQL API for flexible queries
- CLI tools for automation and maintenance
- Plugin system for extensibility
- Import/export functionality

#### Upgrade & Migration
- Comprehensive database migration system with 50+ migrations
- Pre-upgrade backup support (`php cli/migrate.php --backup`)
- Dry-run mode to preview changes (`php cli/migrate.php --dry-run`)
- Interactive upgrade script (`php cli/upgrade.php`)
- Post-upgrade validation checks
- Detailed upgrade documentation (`docs/UPGRADE.md`)
- Rollback procedures documented

#### Performance
- Route caching for fast page loads
- Asset versioning for browser cache optimization
- Database indexing with full-text search
- Query batching to prevent N+1 queries
- Lazy loading of resources
- Level of Detail (LOD) for 3D viewer

### Security
- Input validation and sanitization
- Prepared statements for database queries
- Secure session handling
- HTTP security headers configuration
- File upload validation

---

## Version History Format

Each release documents:
- **Added** - New features
- **Changed** - Changes to existing functionality
- **Deprecated** - Features to be removed in future releases
- **Removed** - Features removed in this release
- **Fixed** - Bug fixes
- **Security** - Security improvements or vulnerability fixes
