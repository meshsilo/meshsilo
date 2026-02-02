# Changelog

All notable changes to MeshSilo will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- OpenID Connect (OIDC) SSO integration
- SAML 2.0 authentication support
- LDAP/Active Directory integration
- Two-factor authentication (TOTP)
- Role-based access control with permission groups
- API key management
- CSRF protection
- Rate limiting
- Audit logging

#### Administration
- Installation wizard with guided setup
- SQLite (default) and MySQL database support
- User and group management
- Category and collection management
- Storage statistics and deduplication metrics
- Activity and audit logging
- Database backup, restore, and optimization tools
- Webhook support for event-driven integrations
- Scheduled task management
- Feature toggles for optional functionality
- Data retention policies

#### API & Integration
- REST API with OpenAPI documentation
- Webhook notifications for model events
- CLI tools for automation and maintenance
- OAuth2 provider capability
- SCIM provisioning support
- Import from Thingiverse/Printables

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
