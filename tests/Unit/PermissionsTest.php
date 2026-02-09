<?php

require_once dirname(__DIR__, 2) . '/includes/permissions.php';

class PermissionsTest extends SiloTestCase {
    protected function setUp(): void {
        parent::setUp();
    }

    public function testPermissionConstantsAreDefined(): void {
        $this->assertTrue(defined('PERM_UPLOAD'));
        $this->assertTrue(defined('PERM_DELETE'));
        $this->assertTrue(defined('PERM_EDIT'));
        $this->assertTrue(defined('PERM_VIEW_STATS'));
        $this->assertTrue(defined('PERM_ADMIN'));
    }

    public function testPermissionConstantValues(): void {
        $this->assertEquals('upload', PERM_UPLOAD);
        $this->assertEquals('delete', PERM_DELETE);
        $this->assertEquals('edit', PERM_EDIT);
        $this->assertEquals('view_stats', PERM_VIEW_STATS);
        $this->assertEquals('admin', PERM_ADMIN);
    }

    public function testDefaultUserPermissionsContainsUpload(): void {
        $this->assertContains(PERM_UPLOAD, DEFAULT_USER_PERMISSIONS);
    }

    public function testDefaultUserPermissionsContainsViewStats(): void {
        $this->assertContains(PERM_VIEW_STATS, DEFAULT_USER_PERMISSIONS);
    }

    public function testDefaultUserPermissionsDoesNotContainDelete(): void {
        $this->assertNotContains(PERM_DELETE, DEFAULT_USER_PERMISSIONS);
    }

    public function testDefaultUserPermissionsDoesNotContainAdmin(): void {
        $this->assertNotContains(PERM_ADMIN, DEFAULT_USER_PERMISSIONS);
    }

    public function testAdminPermissionsContainsAllBasicPermissions(): void {
        $this->assertContains(PERM_UPLOAD, ADMIN_PERMISSIONS);
        $this->assertContains(PERM_DELETE, ADMIN_PERMISSIONS);
        $this->assertContains(PERM_EDIT, ADMIN_PERMISSIONS);
        $this->assertContains(PERM_VIEW_STATS, ADMIN_PERMISSIONS);
        $this->assertContains(PERM_ADMIN, ADMIN_PERMISSIONS);
    }

    public function testAdminPermissionsContainsManagementPermissions(): void {
        $this->assertContains(PERM_MANAGE_USERS, ADMIN_PERMISSIONS);
        $this->assertContains(PERM_MANAGE_GROUPS, ADMIN_PERMISSIONS);
        $this->assertContains(PERM_MANAGE_SETTINGS, ADMIN_PERMISSIONS);
        $this->assertContains(PERM_MANAGE_BACKUPS, ADMIN_PERMISSIONS);
    }

    public function testAdminPermissionsContainsSecurityPermissions(): void {
        $this->assertContains(PERM_MANAGE_SESSIONS, ADMIN_PERMISSIONS);
        $this->assertContains(PERM_MANAGE_SECURITY, ADMIN_PERMISSIONS);
        $this->assertContains(PERM_VIEW_AUDIT_LOG, ADMIN_PERMISSIONS);
        $this->assertContains(PERM_MANAGE_RETENTION, ADMIN_PERMISSIONS);
    }

    public function testAdminPermissionsContainsIntegrationPermissions(): void {
        $this->assertContains(PERM_MANAGE_API_KEYS, ADMIN_PERMISSIONS);
        $this->assertContains(PERM_MANAGE_WEBHOOKS, ADMIN_PERMISSIONS);
        $this->assertContains(PERM_MANAGE_OAUTH, ADMIN_PERMISSIONS);
        $this->assertContains(PERM_MANAGE_LDAP, ADMIN_PERMISSIONS);
        $this->assertContains(PERM_MANAGE_SCIM, ADMIN_PERMISSIONS);
    }

    public function testGetAllPermissionsReturnsArray(): void {
        $permissions = getAllPermissions();
        $this->assertIsArray($permissions);
        $this->assertNotEmpty($permissions);
    }

    public function testGetAllPermissionsContainsDescriptions(): void {
        $permissions = getAllPermissions();
        $this->assertArrayHasKey(PERM_UPLOAD, $permissions);
        $this->assertIsString($permissions[PERM_UPLOAD]);
        $this->assertNotEmpty($permissions[PERM_UPLOAD]);
    }

    public function testGetPermissionsByCategoryReturnsGroupedArray(): void {
        $categories = getPermissionsByCategory();
        $this->assertIsArray($categories);
        $this->assertArrayHasKey('Basic', $categories);
        $this->assertArrayHasKey('Super Admin', $categories);
    }

    public function testGetPermissionsByCategoryBasicContainsUpload(): void {
        $categories = getPermissionsByCategory();
        $this->assertArrayHasKey(PERM_UPLOAD, $categories['Basic']);
    }

    public function testHasPermissionReturnsFalseWhenNotLoggedIn(): void {
        // getCurrentUser() should return null/false when no session
        // Mock getCurrentUser to return null
        if (!function_exists('getCurrentUser')) {
            function getCurrentUser() { return null; }
        }

        $result = hasPermission(PERM_UPLOAD);
        $this->assertFalse($result);
    }
}
