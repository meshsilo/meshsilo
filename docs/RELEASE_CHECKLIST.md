# MeshSilo Release Checklist

This document defines the release process for MeshSilo, including pre-release checks, versioning policy, and release workflow.

## Versioning Policy

MeshSilo follows [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html):

```
MAJOR.MINOR.PATCH
```

| Component | When to Increment | Examples |
|-----------|-------------------|----------|
| **MAJOR** | Breaking changes to API, database schema requiring migration, or incompatible configuration changes | 1.0.0 -> 2.0.0 |
| **MINOR** | New features added in a backward-compatible manner | 1.0.0 -> 1.1.0 |
| **PATCH** | Backward-compatible bug fixes | 1.0.0 -> 1.0.1 |

### Pre-release Versions

- Alpha: `1.0.0-alpha.1` - Early testing, unstable
- Beta: `1.0.0-beta.1` - Feature complete, may have bugs
- Release Candidate: `1.0.0-rc.1` - Ready for release unless critical issues found

### Version File Locations

The version is defined in these locations:
- `composer.json` - Primary version source
- `CHANGELOG.md` - Release documentation
- Git tags - `v1.0.0` format

---

## Pre-Release Checklist

### 1. Code Quality

- [ ] All tests pass locally
  ```bash
  composer test
  ```

- [ ] Static analysis passes
  ```bash
  composer analyse
  ```

- [ ] Code style is compliant
  ```bash
  composer cs
  ```

- [ ] No critical PHPStan errors at level 5+

### 2. Testing

- [ ] Unit tests cover new features
- [ ] Integration tests pass
- [ ] Manual testing completed for:
  - [ ] User registration/login
  - [ ] Model upload (STL, 3MF, OBJ)
  - [ ] 3D viewer functionality
  - [ ] Search and filtering
  - [ ] Admin panel operations
  - [ ] API endpoints

### 3. Security Audit

- [ ] Dependencies updated and audited
  ```bash
  composer audit
  ```

- [ ] No known vulnerabilities in dependencies
- [ ] SQL injection review completed
- [ ] XSS prevention verified
- [ ] CSRF tokens functioning
- [ ] File upload validation tested
- [ ] Authentication/authorization tested
- [ ] API rate limiting verified

### 4. Database

- [ ] All migrations run successfully
  ```bash
  php cli/migrate.php --status
  php cli/migrate.php
  ```

- [ ] Migration rollback tested (if applicable)
- [ ] Database schema documented if changed
- [ ] Backup/restore tested

### 5. Documentation

- [ ] CHANGELOG.md updated with all changes
- [ ] README.md current
- [ ] API documentation updated (openapi.json)
- [ ] Any new configuration options documented
- [ ] Breaking changes clearly documented
- [ ] Upgrade guide written (for major versions)

### 6. Build Verification

- [ ] Docker image builds successfully
  ```bash
  docker compose build
  ```

- [ ] Docker container starts and passes health check
  ```bash
  docker compose up -d
  docker compose ps
  ```

- [ ] Fresh installation works via install wizard
- [ ] Upgrade from previous version works

---

## Release Branch Workflow

### Branch Structure

```
main                    # Production-ready code
  |
  +-- release/v1.0.0    # Release preparation branch
  |
  +-- develop           # Integration branch (optional)
  |
  +-- feature/*         # Feature branches
```

### Release Process

#### 1. Create Release Branch

```bash
# From main branch
git checkout main
git pull origin main
git checkout -b release/v1.0.0
```

#### 2. Version Bump

Update version in `composer.json`:
```json
{
  "version": "1.0.0"
}
```

#### 3. Update CHANGELOG.md

Replace `[Unreleased]` with version and date:
```markdown
## [1.0.0] - 2026-02-02

### Added
- ...

### Changed
- ...
```

Add new Unreleased section at top:
```markdown
## [Unreleased]

## [1.0.0] - 2026-02-02
```

#### 4. Final Testing

Run full test suite on release branch.

#### 5. Create Pull Request

- PR title: `Release v1.0.0`
- Description: Summary of changes from CHANGELOG
- Reviewers: At least one senior team member

#### 6. Merge and Tag

After PR approval:
```bash
# Merge to main
git checkout main
git merge --no-ff release/v1.0.0

# Create annotated tag
git tag -a v1.0.0 -m "Release v1.0.0

Summary of changes:
- Feature A
- Feature B
- Bug fix C"

# Push
git push origin main
git push origin v1.0.0

# Clean up release branch
git branch -d release/v1.0.0
git push origin --delete release/v1.0.0
```

---

## Docker Image Tagging

The GitHub Actions workflow (`.github/workflows/docker-build.yml`) automatically handles Docker image tagging.

### Automatic Tags

| Git Event | Docker Tags |
|-----------|-------------|
| Push to `main` | `latest`, `main` |
| Tag `v1.0.0` | `1.0.0`, `1.0`, `1`, `latest` |
| Tag `v1.0.0-beta.1` | `1.0.0-beta.1` |
| Pull request | `pr-123` (not pushed) |

### Manual Tagging (if needed)

```bash
# Build and tag
docker build -t ghcr.io/OWNER/silo:1.0.0 .

# Push to registry
docker push ghcr.io/OWNER/silo:1.0.0
```

### Image Naming Convention

```
ghcr.io/OWNER/silo:TAG
```

Where TAG follows:
- `latest` - Most recent stable release
- `1.0.0` - Specific version
- `1.0` - Latest patch in minor version
- `1` - Latest minor/patch in major version
- `main` - Latest commit on main branch
- `1.0.0-beta.1` - Pre-release version

---

## Changelog Update Process

### Guidelines

1. **Group by type**: Added, Changed, Deprecated, Removed, Fixed, Security
2. **User-focused**: Describe impact, not implementation
3. **Link issues**: Reference GitHub issues where applicable
4. **Be specific**: Include component or area affected

### Entry Format

```markdown
### Added
- Model version history with restore capability (#123)
- LDAP group mapping for automatic role assignment

### Changed
- Improved thumbnail generation performance by 40%
- Updated Three.js to version 0.160

### Fixed
- Upload fails silently when storage quota exceeded (#456)
- Dark mode colors inconsistent in admin panel

### Security
- Fixed XSS vulnerability in model description field (#789)
```

### Changelog Template

```markdown
## [X.Y.Z] - YYYY-MM-DD

### Added
- New feature description

### Changed
- Change description

### Deprecated
- Feature marked for removal

### Removed
- Removed feature description

### Fixed
- Bug fix description

### Security
- Security improvement
```

---

## Announcement Template

Use this template for release announcements:

```markdown
# MeshSilo v1.0.0 Released

We're excited to announce the release of MeshSilo v1.0.0!

## Highlights

- **Feature A**: Brief description of the most exciting new feature
- **Feature B**: Another key improvement
- **Performance**: Notable performance improvements

## What's New

[Link to full changelog or list key changes]

## Upgrade Instructions

### Docker

```bash
docker pull ghcr.io/OWNER/silo:1.0.0
docker compose down
docker compose up -d
docker exec meshsilo php cli/migrate.php
```

### Manual Installation

1. Back up your database and assets
2. Download the latest release
3. Replace application files (preserve config.local.php)
4. Run migrations: `php cli/migrate.php`

## Breaking Changes

[List any breaking changes and migration steps]

## Known Issues

[List any known issues with workarounds]

## Contributors

Thank you to everyone who contributed to this release!

## Links

- [Full Changelog](CHANGELOG.md)
- [Documentation](docs/)
- [Docker Image](https://ghcr.io/OWNER/silo)
- [Issue Tracker](https://github.com/OWNER/silo/issues)
```

---

## Hotfix Process

For critical bugs in production:

1. **Create hotfix branch from tag**
   ```bash
   git checkout -b hotfix/v1.0.1 v1.0.0
   ```

2. **Apply fix and test**

3. **Update version and changelog**

4. **Merge to main and tag**
   ```bash
   git checkout main
   git merge --no-ff hotfix/v1.0.1
   git tag -a v1.0.1 -m "Hotfix v1.0.1"
   git push origin main v1.0.1
   ```

5. **Clean up**
   ```bash
   git branch -d hotfix/v1.0.1
   ```

---

## Post-Release Tasks

- [ ] Verify Docker image published to registry
- [ ] Verify SBOM attestation completed
- [ ] Create GitHub Release with notes
- [ ] Update demo site (if applicable)
- [ ] Send announcement to mailing list/Discord
- [ ] Update project website
- [ ] Monitor error logs for first 24-48 hours
- [ ] Close related GitHub milestones
