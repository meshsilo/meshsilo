<?php
/**
 * Route Definitions for Silo
 *
 * This file defines all URL routes for the application.
 * Routes are matched in order, first match wins.
 */

$router = Router::getInstance();

// ============================================================================
// GLOBAL MIDDLEWARE (applied to all routes via front controller)
// ============================================================================
// Note: Maintenance mode is checked in the front controller (index.php)
// Rate limiting and SEO redirects are applied per-route below

// ============================================================================
// PUBLIC PAGES
// ============================================================================

// Homepage
$router->get('/', ['file' => 'index.php'], 'home');

// Health check endpoint (for monitoring)
$router->get('/health', ['file' => 'health.php'], 'health');

// Browse & Search
$router->get('/browse', ['file' => 'app/pages/browse.php'], 'browse');
$router->get('/search', ['file' => 'app/pages/search.php'], 'search');

// Categories
$router->get('/categories', ['file' => 'app/pages/categories.php'], 'categories');
$router->get('/category/{id:\d+}', ['file' => 'app/pages/category.php', 'map' => ['id' => 'id']], 'category.show');

// Collections
$router->get('/collections', ['file' => 'app/pages/collections.php'], 'collections');
$router->get('/collection/{name}', ['file' => 'app/pages/collection.php', 'map' => ['name' => 'name']], 'collection.show');

// Tags
$router->get('/tags', ['file' => 'app/pages/tags.php'], 'tags');

// Models
$router->get('/model/{id:\d+}', ['file' => 'app/pages/model.php', 'map' => ['id' => 'id']], 'model.show');
$router->get('/models/{id:\d+}', ['file' => 'app/pages/model.php', 'map' => ['id' => 'id']], 'model.show.alt');

// Model Version Control
$router->get('/model/{id:\d+}/versions', ['file' => 'app/pages/model-versions.php', 'map' => ['id' => 'id']], 'model.versions');
$router->get('/model/{id:\d+}/compare', ['file' => 'app/pages/model-compare.php', 'map' => ['id' => 'id']], 'model.compare');

// Remix/Fork Tracking
$router->get('/model/{id:\d+}/remixes', ['file' => 'app/pages/remix-tree.php', 'map' => ['id' => 'id']], 'model.remixes');

// Shared links (public, no auth required)
$router->get('/share/{token}', ['file' => 'app/pages/share.php', 'map' => ['token' => 'token']], 'share.view');
$router->get('/s/{token}', ['file' => 'app/pages/share.php', 'map' => ['token' => 'token']], 'share.short');

// ============================================================================
// AUTHENTICATION (rate limited for security)
// ============================================================================

$router->get('/login', ['file' => 'app/pages/login.php'], 'login');
$router->post('/login', ['file' => 'app/pages/login.php'], 'login.post')
    ->middleware('ratelimit:5,60,auth'); // 5 attempts per minute
$router->get('/logout', ['file' => 'app/pages/logout.php'], 'logout');
$router->get('/oidc-callback', ['file' => 'app/pages/oidc-callback.php'], 'oidc.callback');

// Password Reset
$router->get('/forgot-password', ['file' => 'app/pages/forgot-password.php'], 'password.forgot');
$router->post('/forgot-password', ['file' => 'app/pages/forgot-password.php'], 'password.forgot.post')
    ->middleware('ratelimit:3,60,password_reset'); // 3 attempts per minute
$router->get('/reset-password', ['file' => 'app/pages/reset-password.php'], 'password.reset');
$router->post('/reset-password', ['file' => 'app/pages/reset-password.php'], 'password.reset.post');

// SAML SSO
$router->post('/saml-acs', ['file' => 'app/pages/saml-acs.php'], 'saml.acs');
$router->get('/saml-metadata', ['file' => 'app/pages/saml-metadata.php'], 'saml.metadata');

$router->get('/install', ['file' => 'install.php'], 'install');
$router->post('/install', ['file' => 'install.php'], 'install.post');

// ============================================================================
// AUTHENTICATED USER PAGES
// ============================================================================

$router->group(['middleware' => ['auth']], function($router) {
    // User Settings
    $router->get('/settings', ['file' => 'app/pages/settings.php'], 'settings');
    $router->post('/settings', ['file' => 'app/pages/settings.php'], 'settings.post');
    $router->get('/profile', ['file' => 'app/pages/settings.php'], 'profile'); // Alias

    // Favorites
    $router->get('/favorites', ['file' => 'app/pages/favorites.php'], 'favorites');

    // Print Queue & Analytics
    $router->get('/print-queue', ['file' => 'app/pages/print-queue.php'], 'print-queue');
    $router->get('/print-analytics', ['file' => 'app/pages/print-analytics.php'], 'print-analytics');
    $router->get('/printers', ['file' => 'app/pages/printers.php'], 'printers');

    // Upload (requires upload permission)
    $router->get('/upload', ['file' => 'app/pages/upload.php'], 'upload')
        ->middleware('permission:upload');
    $router->post('/upload', ['file' => 'app/pages/upload.php'], 'upload.post')
        ->middleware('permission:upload');

    // Edit Model (requires edit permission)
    $router->get('/model/{id:\d+}/edit', ['file' => 'app/pages/edit-model.php', 'map' => ['id' => 'id']], 'model.edit')
        ->middleware('permission:edit');
    $router->post('/model/{id:\d+}/edit', ['file' => 'app/pages/edit-model.php', 'map' => ['id' => 'id']], 'model.edit.post')
        ->middleware('permission:edit');
});

// ============================================================================
// DOWNLOAD ROUTES (shortcuts)
// ============================================================================

$router->get('/download/{id:\d+}', ['file' => 'app/actions/download.php', 'map' => ['id' => 'id']], 'download');
$router->get('/download-all/{id:\d+}', ['file' => 'app/actions/download-all.php', 'map' => ['id' => 'id']], 'download.all');

// ============================================================================
// ACTION HANDLERS
// ============================================================================

$router->group(['prefix' => '/actions'], function($router) {
    // File downloads
    $router->get('/download', ['file' => 'app/actions/download.php'], 'actions.download');
    $router->get('/download-all', ['file' => 'app/actions/download-all.php'], 'actions.download.all');

    // Favorites (AJAX)
    $router->post('/favorite', ['file' => 'app/actions/favorite.php'], 'actions.favorite');

    // Tags (AJAX)
    $router->post('/tag', ['file' => 'app/actions/tag.php'], 'actions.tag');

    // Model/Part updates (require edit permission)
    $router->post('/update-model', ['file' => 'app/actions/update-model.php'], 'actions.update.model')
        ->middleware('permission:edit');
    $router->post('/update-part', ['file' => 'app/actions/update-part.php'], 'actions.update.part')
        ->middleware('permission:edit');

    // Delete (requires delete permission)
    $router->get('/delete', ['file' => 'app/actions/delete.php'], 'actions.delete')
        ->middleware('permission:delete');
    $router->post('/delete', ['file' => 'app/actions/delete.php'], 'actions.delete.post')
        ->middleware('permission:delete');

    // Add parts (requires upload permission)
    $router->post('/add-part', ['file' => 'app/actions/add-part.php'], 'actions.add.part')
        ->middleware('permission:upload');

    // Mass actions
    $router->post('/mass-action', ['file' => 'app/actions/mass-action.php'], 'actions.mass');

    // Convert part (STL to 3MF)
    $router->post('/convert-part', ['file' => 'app/actions/convert-part.php'], 'actions.convert');

    // Duplicate checking
    $router->post('/check-duplicates', ['file' => 'app/actions/check-duplicates.php'], 'actions.check.duplicates');

    // Dimensions calculation
    $router->post('/calculate-dimensions', ['file' => 'app/actions/calculate-dimensions.php'], 'actions.dimensions');

    // Reorder parts
    $router->post('/reorder-parts', ['file' => 'app/actions/reorder-parts.php'], 'actions.reorder');

    // Related models
    $router->get('/related-models', ['file' => 'app/actions/related-models.php'], 'actions.related');
    $router->post('/related-models', ['file' => 'app/actions/related-models.php'], 'actions.related.post');

    // Scaling
    $router->post('/scaling', ['file' => 'app/actions/scaling.php'], 'actions.scaling');

    // Print queue
    $router->post('/print-queue', ['file' => 'app/actions/print-queue.php'], 'actions.print.queue');

    // Printer management
    $router->post('/printer', ['file' => 'app/actions/printer.php'], 'actions.printer');

    // Print history
    $router->get('/print-history', ['file' => 'app/actions/print-history.php'], 'actions.print.history');
    $router->post('/print-history', ['file' => 'app/actions/print-history.php'], 'actions.print.history.post');

    // Print photos
    $router->post('/print-photo', ['file' => 'app/actions/print-photo.php'], 'actions.print.photo');

    // Rating
    $router->post('/rating', ['file' => 'app/actions/rating.php'], 'actions.rating');

    // Thumbnails
    $router->post('/thumbnail', ['file' => 'app/actions/thumbnail.php'], 'actions.thumbnail');
    $router->post('/webp-thumbnail', ['file' => 'app/actions/webp-thumbnail.php'], 'actions.thumbnail.webp');

    // QR codes
    $router->get('/qrcode', ['file' => 'app/actions/qrcode.php'], 'actions.qrcode');

    // Share links
    $router->post('/share-link', ['file' => 'app/actions/share-link.php'], 'actions.share');

    // Notifications
    $router->get('/notification', ['file' => 'app/actions/notification.php'], 'actions.notification');
    $router->post('/notification', ['file' => 'app/actions/notification.php'], 'actions.notification.post');

    // Cost calculator
    $router->post('/cost-calculator', ['file' => 'app/actions/cost-calculator.php'], 'actions.cost');

    // Batch operations
    $router->post('/batch-apply', ['file' => 'app/actions/batch-apply.php'], 'actions.batch.apply');
    $router->post('/batch-download', ['file' => 'app/actions/batch-download.php'], 'actions.batch.download');

    // Folders
    $router->post('/folder', ['file' => 'app/actions/folder.php'], 'actions.folder');

    // Smart collections
    $router->post('/smart-collection', ['file' => 'app/actions/smart-collection.php'], 'actions.smart.collection');

    // File types
    $router->post('/file-types', ['file' => 'app/actions/file-types.php'], 'actions.file.types');

    // Upload versions
    $router->post('/upload-version', ['file' => 'app/actions/upload-version.php'], 'actions.upload.version');
    $router->post('/revert-version', ['file' => 'app/actions/revert-version.php'], 'actions.revert.version');

    // Teams
    $router->post('/teams', ['file' => 'app/actions/teams.php'], 'actions.teams');

    // Approval workflow
    $router->post('/approval', ['file' => 'app/actions/approval.php'], 'actions.approval');

    // Import/Export
    $router->post('/import', ['file' => 'app/actions/import.php'], 'actions.import');
    $router->post('/export-import', ['file' => 'app/actions/export-import.php'], 'actions.export');

    // Bulk upload
    $router->post('/bulk-upload', ['file' => 'app/actions/bulk-upload.php'], 'actions.bulk.upload');

    // Backup
    $router->post('/backup', ['file' => 'app/actions/backup.php'], 'actions.backup');

    // Usage dashboard
    $router->get('/usage-dashboard', ['file' => 'app/actions/usage-dashboard.php'], 'actions.usage');

    // Homepage config
    $router->post('/homepage-config', ['file' => 'app/actions/homepage-config.php'], 'actions.homepage');

    // Branding
    $router->post('/branding', ['file' => 'app/actions/branding.php'], 'actions.branding');
});

// ============================================================================
// ADMIN ROUTES
// ============================================================================

$router->group(['prefix' => '/admin', 'middleware' => ['admin']], function($router) {
    // Settings
    $router->get('/settings', ['file' => 'app/admin/settings.php'], 'admin.settings');
    $router->post('/settings', ['file' => 'app/admin/settings.php'], 'admin.settings.save');

    // User management
    $router->get('/users', ['file' => 'app/admin/users.php'], 'admin.users');
    $router->get('/user/{id:\d+}', ['file' => 'app/admin/user.php', 'map' => ['id' => 'id']], 'admin.user');
    $router->post('/user/{id:\d+}', ['file' => 'app/admin/user.php', 'map' => ['id' => 'id']], 'admin.user.save');

    // Groups
    $router->get('/groups', ['file' => 'app/admin/groups.php'], 'admin.groups');
    $router->post('/groups', ['file' => 'app/admin/groups.php'], 'admin.groups.save');

    // Categories
    $router->get('/categories', ['file' => 'app/admin/categories.php'], 'admin.categories');
    $router->post('/categories', ['file' => 'app/admin/categories.php'], 'admin.categories.save');

    // Collections
    $router->get('/collections', ['file' => 'app/admin/collections.php'], 'admin.collections');
    $router->post('/collections', ['file' => 'app/admin/collections.php'], 'admin.collections.save');

    // Models management
    $router->get('/models', ['file' => 'app/admin/models.php'], 'admin.models');
    $router->post('/models', ['file' => 'app/admin/models.php'], 'admin.models.save');

    // Statistics
    $router->get('/stats', ['file' => 'app/admin/stats.php'], 'admin.stats');

    // Activity log
    $router->get('/activity', ['file' => 'app/admin/activity.php'], 'admin.activity');

    // Storage
    $router->get('/storage', ['file' => 'app/admin/storage.php'], 'admin.storage');
    $router->post('/storage', ['file' => 'app/admin/storage.php'], 'admin.storage.save');

    // Database management
    $router->get('/database', ['file' => 'app/admin/database.php'], 'admin.database');
    $router->post('/database', ['file' => 'app/admin/database.php'], 'admin.database.action');

    // API Keys
    $router->get('/api-keys', ['file' => 'app/admin/api-keys.php'], 'admin.api-keys');
    $router->post('/api-keys', ['file' => 'app/admin/api-keys.php'], 'admin.api-keys.save');

    // Webhooks
    $router->get('/webhooks', ['file' => 'app/admin/webhooks.php'], 'admin.webhooks');
    $router->post('/webhooks', ['file' => 'app/admin/webhooks.php'], 'admin.webhooks.save');

    // License
    $router->get('/license', ['file' => 'app/admin/license.php'], 'admin.license');
    $router->post('/license', ['file' => 'app/admin/license.php'], 'admin.license.save');

    // Audit Log
    $router->get('/audit-log', ['file' => 'app/admin/audit-log.php'], 'admin.audit-log');

    // Data Retention
    $router->get('/retention', ['file' => 'app/admin/retention-policies.php'], 'admin.retention');
    $router->post('/retention', ['file' => 'app/admin/retention-policies.php'], 'admin.retention.save');

    // Analytics
    $router->get('/analytics', ['file' => 'app/admin/analytics.php'], 'admin.analytics');
    $router->post('/analytics', ['file' => 'app/admin/analytics.php'], 'admin.analytics.action');

    // Routes (debugging)
    $router->get('/routes', ['file' => 'app/admin/routes.php'], 'admin.routes');
});

// ============================================================================
// API ROUTES
// Note: The API has its own routing in api/index.php
// These routes redirect to the API for clean URL access
// ============================================================================

$router->group(['prefix' => '/api'], function($router) {
    // Redirect to API handler
    $router->any('/models', ['file' => 'app/api/index.php'], 'api.models');
    $router->any('/models/{id:\d+}', ['file' => 'app/api/index.php', 'map' => ['id' => 'id']], 'api.model');
    $router->any('/categories', ['file' => 'app/api/index.php'], 'api.categories');
    $router->any('/categories/{id:\d+}', ['file' => 'app/api/index.php', 'map' => ['id' => 'id']], 'api.category');
    $router->any('/tags', ['file' => 'app/api/index.php'], 'api.tags');
    $router->any('/collections', ['file' => 'app/api/index.php'], 'api.collections');
    $router->any('/stats', ['file' => 'app/api/index.php'], 'api.stats');
    $router->any('/webhooks', ['file' => 'app/api/index.php'], 'api.webhooks');
});

// ============================================================================
// CLI ROUTES (for web-based CLI access if enabled)
// ============================================================================

// These would only be accessible via the web if explicitly enabled
// $router->group(['prefix' => '/cli', 'middleware' => ['admin']], function($router) {
//     $router->post('/migrate', ['file' => 'cli/migrate.php'], 'cli.migrate');
//     $router->post('/dedup', ['file' => 'cli/dedup.php'], 'cli.dedup');
// });

return $router;
