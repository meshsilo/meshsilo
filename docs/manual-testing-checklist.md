# MeshSilo Manual Testing Checklist

Post-audit verification checklist. This document was generated from the comprehensive code audit
and includes specific items to verify after bug fixes and code changes.

---

## Audit Findings Summary

The following bugs and issues were found and fixed during the audit. Each section below
includes verification steps.

### Critical Bugs Fixed

1. **API Authentication Bypass** (`includes/auth.php`)
   - API endpoints returned 302 redirects to login instead of processing API key auth
   - Root cause: `enforceAuthentication()` ran before `API_REQUEST` constant was defined
   - Fix: Added route-based check (`str_starts_with($currentRoute, '/api/')`)

2. **RateLimiter MySQL Incompatibility** (`includes/RateLimiter.php`)
   - `ensureTable()` used SQLite-specific syntax (`INTEGER PRIMARY KEY AUTOINCREMENT`)
   - `timestamp` column name is a MySQL reserved word (needs backtick quoting)
   - `getTierForUser()` queried nonexistent `rate_limit_tier` column
   - Fix: DB-type-aware table creation, backtick-quoted column names, try-catch for missing columns

### CSS/UI Fixes

3. **Missing CSS Variables** (`public/css/style.css`)
   - `--color-success`, `--color-warning`, `--color-danger` were used but never defined in `:root`

4. **Admin Layout Pattern** (`app/admin/activity.php`, `app/admin/models.php`)
   - Missing `admin-layout` wrapper, sidebar, and `admin-content` structure

5. **Admin Sidebar Navigation** (`includes/admin-sidebar.php`)
   - Missing links for Models and Activity Log pages

6. **Hardcoded Colors** (multiple admin pages)
   - Replaced hex color literals with CSS variables across 10+ admin files:
     health.php, sessions.php, audit-log.php, api-keys.php, cli-tools.php,
     security-headers.php, scheduler.php, routes.php, user.php, features.php

### Dead Code Removed

7. **Unused Variables** (11 files)
   - Removed `$baseDir` assignments that were never used:
     delete.php, api-keys.php, categories.php, collections.php, database.php,
     groups.php, settings.php, storage.php, user.php, users.php, stats.php

8. **Unused Functions** (`includes/HttpCache.php`)
   - Removed `no_cache()`, `cache_static()`, `check_etag()` -- defined but never called

---

## 1. API Endpoint Verification

Test with API key: Use a valid API key in the `X-API-Key` header.

### Authentication Bypass Fix
- [ ] `GET /api/models` with valid API key returns 200 with JSON model list
- [ ] `GET /api/models/{id}` with valid API key returns 200 with model details
- [ ] `GET /api/categories` with valid API key returns 200
- [ ] `GET /api/tags` with valid API key returns 200
- [ ] `GET /api/collections` with valid API key returns 200
- [ ] `GET /api/stats` with valid API key returns 200
- [ ] `GET /api/models` without API key returns 401 (not 302 redirect)
- [ ] `GET /api/models` with invalid API key returns 401

### Rate Limiting
- [ ] API responses include `X-RateLimit-Limit` header
- [ ] API responses include `X-RateLimit-Remaining` header
- [ ] API responses include `X-RateLimit-Tier` header
- [ ] Exceeding rate limit returns 429

### Curl Test Commands
```bash
# Valid API key test
curl -s -H "X-API-Key: YOUR_API_KEY" http://localhost/api/models

# No API key test (should return 401, NOT 302)
curl -s -o /dev/null -w "%{http_code}" http://localhost/api/models

# Single model
curl -s -H "X-API-Key: YOUR_API_KEY" http://localhost/api/models/1

# Stats
curl -s -H "X-API-Key: YOUR_API_KEY" http://localhost/api/stats
```

---

## 2. Admin Page Layout Verification

All admin pages should follow the `admin-layout > admin-sidebar + admin-content` pattern.

### Fixed Pages
- [ ] Admin > Activity Log -- has sidebar, content area, and active nav highlight
- [ ] Admin > Models -- has sidebar, content area, and active nav highlight

### Sidebar Navigation
- [ ] "Models" link appears in sidebar Content section
- [ ] "Activity Log" link appears in sidebar Security section
- [ ] Both links highlight when on their respective pages

### All Admin Pages Load Without Error
- [ ] Admin > Health (`/admin/health`)
- [ ] Admin > Settings (`/admin/settings`)
- [ ] Admin > Features (`/admin/features`)
- [ ] Admin > Users (`/admin/users`)
- [ ] Admin > User Edit (`/admin/user/{id}`)
- [ ] Admin > Groups (`/admin/groups`)
- [ ] Admin > Sessions (`/admin/sessions`)
- [ ] Admin > Categories (`/admin/categories`)
- [ ] Admin > Collections (`/admin/collections`)
- [ ] Admin > Models (`/admin/models`)
- [ ] Admin > Stats (`/admin/stats`)
- [ ] Admin > CLI Tools (`/admin/cli-tools`)
- [ ] Admin > Activity (`/admin/activity`)
- [ ] Admin > Storage (`/admin/storage`)
- [ ] Admin > Database (`/admin/database`)
- [ ] Admin > API Keys (`/admin/api-keys`)
- [ ] Admin > Audit Log (`/admin/audit-log`)
- [ ] Admin > Security Headers (`/admin/security-headers`)
- [ ] Admin > Scheduler (`/admin/scheduler`)
- [ ] Admin > Routes (`/admin/routes`)
- [ ] Admin > Plugins (`/admin/plugins`)

---

## 3. CSS Variable Verification

### Theme Consistency (Dark Mode)
- [ ] Health page: status badges use theme-consistent colors
- [ ] Health page: metric bar warning/critical states use CSS variables
- [ ] Sessions page: badge colors match theme
- [ ] Audit Log page: severity badges use CSS variables
- [ ] API Keys page: permission and status badges use CSS variables
- [ ] CLI Tools page: warning badges and output panel borders use CSS variables
- [ ] Security Headers page: finding severity borders use CSS variables
- [ ] Scheduler page: status badges use CSS variables
- [ ] Routes page: HTTP method badges use CSS variables
- [ ] User Edit page: permission status and danger zone use CSS variables
- [ ] Features page: toggle slider, enabled states, warnings use CSS variables

### Theme Consistency (Light Mode)
- [ ] Switch to light theme (click theme toggle)
- [ ] All of the above badges and states still look correct in light mode
- [ ] No invisible text (white text on white background)
- [ ] No unreadable contrast issues

---

## 4. Public Page Verification

- [ ] Homepage (`/`) loads correctly
- [ ] Browse page (`/browse`) loads with model grid
- [ ] Categories page (`/categories`) loads
- [ ] Tags page (`/tags`) loads
- [ ] Login page (`/login`) loads with form
- [ ] Forgot Password page (`/forgot-password`) loads
- [ ] Model detail page (`/model/{id}`) loads with 3D viewer
- [ ] Upload page (`/upload`) loads with dropzone
- [ ] Search page (`/search`) loads

---

## 5. Authentication Flow Verification

- [ ] Unauthenticated access to `/browse` redirects to `/login`
- [ ] Unauthenticated access to `/admin/health` redirects to `/login`
- [ ] Unauthenticated access to `/api/models` returns 401 JSON (NOT redirect)
- [ ] Login with valid credentials redirects to homepage
- [ ] Login with invalid credentials shows error message
- [ ] Session idle timeout (30 min default) triggers re-login
- [ ] Rate limiting kicks in after 5 failed login attempts

---

## 6. Route Validation Results

### All Route Files Exist
All 87 route definitions in `includes/routes.php` point to files that exist. Verified:
- 22 page files in `app/pages/`
- 42 action files in `app/actions/`
- 21 admin files in `app/admin/`
- 2 API files in `app/api/`

### Documentation Status
All routes documented in CLAUDE.md now match the actual codebase. Previously documented pages that were removed during the refactor have been cleaned up from documentation.

---

## 7. Database Compatibility

### MySQL/MariaDB
- [ ] Rate limiter `rate_limits` table creates correctly on MySQL
- [ ] Rate limiter queries execute without "reserved word" errors
- [ ] All migrations apply cleanly: `php cli/migrate.php`
- [ ] Migration status shows all applied: `php cli/migrate.php --status`

### SQLite
- [ ] Rate limiter table creates correctly on SQLite
- [ ] All queries execute without error

---

## 8. Dead Code Audit Results

### Unused Functions (Not Removed -- Low Risk, May Be Used by Plugins)

The following functions are defined but never called in the codebase. They are utility/API
functions that may be intended for plugin use or future development:

**ErrorHandler.php** (convenience wrappers -- never called):
- `abort_if()`, `abort_unless()`
- `json_feature_disabled()`, `json_not_found()`, `json_validation_error()`
- `require_feature_json()`, `require_admin_json()`

**db.php** (batch operations -- never called):
- `batchInsert()`, `batchInsertIgnore()`, `batchUpdate()`

**CursorPagination.php** (entire file -- never called from outside):
- `cursorPaginate()`, `paginateModels()`, `cursorPaginationLinks()`

**ApiVersion.php** (helper functions -- only called internally):
- `api_version()`, `api_requires_version()`

**Cache.php** (convenience wrapper -- never called):
- `cache_remember()`

---

## 9. Performance Checks

- [ ] Browse page with 20+ models loads in under 3 seconds
- [ ] Admin health page loads without noticeable delay
- [ ] API `/api/models` responds in under 500ms
- [ ] No N+1 query warnings in debug mode

---

## 10. Regression Checks After Audit Changes

### Files Modified
Verify no regressions in the following modified files:

| File | Change | Verify |
|------|--------|--------|
| `includes/auth.php` | Added API route bypass | Login still works, admin pages still require auth |
| `includes/RateLimiter.php` | MySQL compat, backtick quoting | Rate limiting still works on both DB types |
| `public/css/style.css` | Added missing CSS variables | No visual regressions in dark/light themes |
| `includes/admin-sidebar.php` | Added Models and Activity links | Sidebar renders correctly on all admin pages |
| `app/admin/activity.php` | Added admin layout wrapper | Page renders with sidebar |
| `app/admin/models.php` | Added admin layout wrapper | Page renders with sidebar |
| `app/admin/health.php` | CSS variable replacements | Status colors still visible |
| `app/admin/sessions.php` | CSS variable replacements | Badge colors still visible |
| `app/admin/audit-log.php` | CSS variable replacements | Severity badges still visible |
| `app/admin/api-keys.php` | CSS variable replacements | Permission badges still visible |
| `app/admin/cli-tools.php` | CSS variable replacements | Output panel styling intact |
| `app/admin/security-headers.php` | CSS variable replacements | Finding severity indicators intact |
| `app/admin/scheduler.php` | CSS variable replacements | Task status badges visible |
| `app/admin/routes.php` | CSS variable replacements | HTTP method badges visible |
| `app/admin/user.php` | CSS variable replacements | Permission and danger zone styles intact |
| `app/admin/features.php` | CSS variable replacements | Toggle and badge styles intact |
| `app/pages/upload.php` | JS color to CSS variable | Upload success color shows correctly |

---

## Sign-Off

| Area | Tester | Date | Pass/Fail | Notes |
|------|--------|------|-----------|-------|
| API Endpoints | | | | |
| Admin Page Layouts | | | | |
| CSS/Theme Consistency | | | | |
| Authentication Flow | | | | |
| Route Validation | | | | |
| Database Compatibility | | | | |
| Regression Testing | | | | |
