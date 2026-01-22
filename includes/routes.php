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
$router->get('/browse', ['file' => 'pages/browse.php'], 'browse');
$router->get('/search', ['file' => 'pages/search.php'], 'search');

// Categories
$router->get('/categories', ['file' => 'pages/categories.php'], 'categories');
$router->get('/category/{id:\d+}', ['file' => 'pages/category.php', 'map' => ['id' => 'id']], 'category.show');

// Collections
$router->get('/collections', ['file' => 'pages/collections.php'], 'collections');
$router->get('/collection/{name}', ['file' => 'pages/collection.php', 'map' => ['name' => 'name']], 'collection.show');

// Tags
$router->get('/tags', ['file' => 'pages/tags.php'], 'tags');

// Models
$router->get('/model/{id:\d+}', ['file' => 'pages/model.php', 'map' => ['id' => 'id']], 'model.show');
$router->get('/models/{id:\d+}', ['file' => 'pages/model.php', 'map' => ['id' => 'id']], 'model.show.alt');

// Shared links (public, no auth required)
$router->get('/share/{token}', ['file' => 'pages/share.php', 'map' => ['token' => 'token']], 'share.view');
$router->get('/s/{token}', ['file' => 'pages/share.php', 'map' => ['token' => 'token']], 'share.short');

// ============================================================================
// AUTHENTICATION (rate limited for security)
// ============================================================================

$router->get('/login', ['file' => 'pages/login.php'], 'login');
$router->post('/login', ['file' => 'pages/login.php'], 'login.post')
    ->middleware('ratelimit:5,60,auth'); // 5 attempts per minute
$router->get('/logout', ['file' => 'pages/logout.php'], 'logout');
$router->get('/oidc-callback', ['file' => 'pages/oidc-callback.php'], 'oidc.callback');
$router->get('/install', ['file' => 'install.php'], 'install');
$router->post('/install', ['file' => 'install.php'], 'install.post');

// ============================================================================
// AUTHENTICATED USER PAGES
// ============================================================================

$router->group(['middleware' => ['auth']], function($router) {
    // Favorites
    $router->get('/favorites', ['file' => 'pages/favorites.php'], 'favorites');

    // Print Queue
    $router->get('/print-queue', ['file' => 'pages/print-queue.php'], 'print-queue');
    $router->get('/printers', ['file' => 'pages/printers.php'], 'printers');

    // Upload (requires upload permission)
    $router->get('/upload', ['file' => 'pages/upload.php'], 'upload')
        ->middleware('permission:upload');
    $router->post('/upload', ['file' => 'pages/upload.php'], 'upload.post')
        ->middleware('permission:upload');

    // Edit Model (requires edit permission)
    $router->get('/model/{id:\d+}/edit', ['file' => 'pages/edit-model.php', 'map' => ['id' => 'id']], 'model.edit')
        ->middleware('permission:edit');
    $router->post('/model/{id:\d+}/edit', ['file' => 'pages/edit-model.php', 'map' => ['id' => 'id']], 'model.edit.post')
        ->middleware('permission:edit');
});

// ============================================================================
// DOWNLOAD ROUTES (shortcuts)
// ============================================================================

$router->get('/download/{id:\d+}', ['file' => 'actions/download.php', 'map' => ['id' => 'id']], 'download');
$router->get('/download-all/{id:\d+}', ['file' => 'actions/download-all.php', 'map' => ['id' => 'id']], 'download.all');

// ============================================================================
// ACTION HANDLERS
// ============================================================================

$router->group(['prefix' => '/actions'], function($router) {
    // File downloads
    $router->get('/download', ['file' => 'actions/download.php'], 'actions.download');
    $router->get('/download-all', ['file' => 'actions/download-all.php'], 'actions.download.all');

    // Favorites (AJAX)
    $router->post('/favorite', ['file' => 'actions/favorite.php'], 'actions.favorite');

    // Tags (AJAX)
    $router->post('/tag', ['file' => 'actions/tag.php'], 'actions.tag');

    // Model/Part updates (require edit permission)
    $router->post('/update-model', ['file' => 'actions/update-model.php'], 'actions.update.model')
        ->middleware('permission:edit');
    $router->post('/update-part', ['file' => 'actions/update-part.php'], 'actions.update.part')
        ->middleware('permission:edit');

    // Delete (requires delete permission)
    $router->get('/delete', ['file' => 'actions/delete.php'], 'actions.delete')
        ->middleware('permission:delete');
    $router->post('/delete', ['file' => 'actions/delete.php'], 'actions.delete.post')
        ->middleware('permission:delete');

    // Add parts (requires upload permission)
    $router->post('/add-part', ['file' => 'actions/add-part.php'], 'actions.add.part')
        ->middleware('permission:upload');

    // Mass actions
    $router->post('/mass-action', ['file' => 'actions/mass-action.php'], 'actions.mass');

    // Convert part (STL to 3MF)
    $router->post('/convert-part', ['file' => 'actions/convert-part.php'], 'actions.convert');

    // Duplicate checking
    $router->post('/check-duplicates', ['file' => 'actions/check-duplicates.php'], 'actions.check.duplicates');

    // Dimensions calculation
    $router->post('/calculate-dimensions', ['file' => 'actions/calculate-dimensions.php'], 'actions.dimensions');

    // Reorder parts
    $router->post('/reorder-parts', ['file' => 'actions/reorder-parts.php'], 'actions.reorder');

    // Related models
    $router->get('/related-models', ['file' => 'actions/related-models.php'], 'actions.related');
    $router->post('/related-models', ['file' => 'actions/related-models.php'], 'actions.related.post');

    // Scaling
    $router->post('/scaling', ['file' => 'actions/scaling.php'], 'actions.scaling');

    // Print queue
    $router->post('/print-queue', ['file' => 'actions/print-queue.php'], 'actions.print.queue');

    // Printer management
    $router->post('/printer', ['file' => 'actions/printer.php'], 'actions.printer');

    // Print history
    $router->get('/print-history', ['file' => 'actions/print-history.php'], 'actions.print.history');
    $router->post('/print-history', ['file' => 'actions/print-history.php'], 'actions.print.history.post');

    // Print photos
    $router->post('/print-photo', ['file' => 'actions/print-photo.php'], 'actions.print.photo');

    // Rating
    $router->post('/rating', ['file' => 'actions/rating.php'], 'actions.rating');

    // Thumbnails
    $router->post('/thumbnail', ['file' => 'actions/thumbnail.php'], 'actions.thumbnail');
    $router->post('/webp-thumbnail', ['file' => 'actions/webp-thumbnail.php'], 'actions.thumbnail.webp');

    // QR codes
    $router->get('/qrcode', ['file' => 'actions/qrcode.php'], 'actions.qrcode');

    // Share links
    $router->post('/share-link', ['file' => 'actions/share-link.php'], 'actions.share');

    // Notifications
    $router->get('/notification', ['file' => 'actions/notification.php'], 'actions.notification');
    $router->post('/notification', ['file' => 'actions/notification.php'], 'actions.notification.post');

    // Cost calculator
    $router->post('/cost-calculator', ['file' => 'actions/cost-calculator.php'], 'actions.cost');

    // Batch operations
    $router->post('/batch-apply', ['file' => 'actions/batch-apply.php'], 'actions.batch.apply');
    $router->post('/batch-download', ['file' => 'actions/batch-download.php'], 'actions.batch.download');

    // Folders
    $router->post('/folder', ['file' => 'actions/folder.php'], 'actions.folder');

    // Smart collections
    $router->post('/smart-collection', ['file' => 'actions/smart-collection.php'], 'actions.smart.collection');

    // File types
    $router->post('/file-types', ['file' => 'actions/file-types.php'], 'actions.file.types');

    // Upload versions
    $router->post('/upload-version', ['file' => 'actions/upload-version.php'], 'actions.upload.version');

    // Teams
    $router->post('/teams', ['file' => 'actions/teams.php'], 'actions.teams');

    // Approval workflow
    $router->post('/approval', ['file' => 'actions/approval.php'], 'actions.approval');

    // Import/Export
    $router->post('/import', ['file' => 'actions/import.php'], 'actions.import');
    $router->post('/export-import', ['file' => 'actions/export-import.php'], 'actions.export');

    // Bulk upload
    $router->post('/bulk-upload', ['file' => 'actions/bulk-upload.php'], 'actions.bulk.upload');

    // Backup
    $router->post('/backup', ['file' => 'actions/backup.php'], 'actions.backup');

    // Usage dashboard
    $router->get('/usage-dashboard', ['file' => 'actions/usage-dashboard.php'], 'actions.usage');

    // Homepage config
    $router->post('/homepage-config', ['file' => 'actions/homepage-config.php'], 'actions.homepage');

    // Branding
    $router->post('/branding', ['file' => 'actions/branding.php'], 'actions.branding');
});

// ============================================================================
// ADMIN ROUTES
// ============================================================================

$router->group(['prefix' => '/admin', 'middleware' => ['admin']], function($router) {
    // Settings
    $router->get('/settings', ['file' => 'admin/settings.php'], 'admin.settings');
    $router->post('/settings', ['file' => 'admin/settings.php'], 'admin.settings.save');

    // User management
    $router->get('/users', ['file' => 'admin/users.php'], 'admin.users');
    $router->get('/user/{id:\d+}', ['file' => 'admin/user.php', 'map' => ['id' => 'id']], 'admin.user');
    $router->post('/user/{id:\d+}', ['file' => 'admin/user.php', 'map' => ['id' => 'id']], 'admin.user.save');

    // Groups
    $router->get('/groups', ['file' => 'admin/groups.php'], 'admin.groups');
    $router->post('/groups', ['file' => 'admin/groups.php'], 'admin.groups.save');

    // Categories
    $router->get('/categories', ['file' => 'admin/categories.php'], 'admin.categories');
    $router->post('/categories', ['file' => 'admin/categories.php'], 'admin.categories.save');

    // Collections
    $router->get('/collections', ['file' => 'admin/collections.php'], 'admin.collections');
    $router->post('/collections', ['file' => 'admin/collections.php'], 'admin.collections.save');

    // Models management
    $router->get('/models', ['file' => 'admin/models.php'], 'admin.models');
    $router->post('/models', ['file' => 'admin/models.php'], 'admin.models.save');

    // Statistics
    $router->get('/stats', ['file' => 'admin/stats.php'], 'admin.stats');

    // Activity log
    $router->get('/activity', ['file' => 'admin/activity.php'], 'admin.activity');

    // Storage
    $router->get('/storage', ['file' => 'admin/storage.php'], 'admin.storage');
    $router->post('/storage', ['file' => 'admin/storage.php'], 'admin.storage.save');

    // Database management
    $router->get('/database', ['file' => 'admin/database.php'], 'admin.database');
    $router->post('/database', ['file' => 'admin/database.php'], 'admin.database.action');

    // API Keys
    $router->get('/api-keys', ['file' => 'admin/api-keys.php'], 'admin.api-keys');
    $router->post('/api-keys', ['file' => 'admin/api-keys.php'], 'admin.api-keys.save');

    // Webhooks
    $router->get('/webhooks', ['file' => 'admin/webhooks.php'], 'admin.webhooks');
    $router->post('/webhooks', ['file' => 'admin/webhooks.php'], 'admin.webhooks.save');

    // License
    $router->get('/license', ['file' => 'admin/license.php'], 'admin.license');
    $router->post('/license', ['file' => 'admin/license.php'], 'admin.license.save');

    // Routes (debugging)
    $router->get('/routes', ['file' => 'admin/routes.php'], 'admin.routes');
});

// ============================================================================
// API ROUTES
// Note: The API has its own routing in api/index.php
// These routes redirect to the API for clean URL access
// ============================================================================

$router->group(['prefix' => '/api'], function($router) {
    // Redirect to API handler
    $router->any('/models', ['file' => 'api/index.php'], 'api.models');
    $router->any('/models/{id:\d+}', ['file' => 'api/index.php', 'map' => ['id' => 'id']], 'api.model');
    $router->any('/categories', ['file' => 'api/index.php'], 'api.categories');
    $router->any('/categories/{id:\d+}', ['file' => 'api/index.php', 'map' => ['id' => 'id']], 'api.category');
    $router->any('/tags', ['file' => 'api/index.php'], 'api.tags');
    $router->any('/collections', ['file' => 'api/index.php'], 'api.collections');
    $router->any('/stats', ['file' => 'api/index.php'], 'api.stats');
    $router->any('/webhooks', ['file' => 'api/index.php'], 'api.webhooks');
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
