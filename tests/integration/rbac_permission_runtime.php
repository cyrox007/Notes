<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/app/services/PermissionService.php';

use App\Services\PermissionService;
use Core\DatabaseManager;

function failRbac(string $message): never
{
    fwrite(STDERR, "RBAC runtime contract failed: {$message}\n");
    exit(1);
}

$db = DatabaseManager::getInstance();
$permissions = new PermissionService($db);

/** @return int */
function userId(DatabaseManager $db, string $username): int
{
    $value = $db->fetchValue('SELECT id FROM users WHERE username = :username LIMIT 1', [':username' => $username]);
    if ($value === null) {
        failRbac("missing fixture user {$username}");
    }
    return (int) $value;
}

$regular = userId($db, 'rbac_user');
$admin = userId($db, 'rbac_admin');
$superadmin = userId($db, 'rbac_superadmin');
$blocked = userId($db, 'rbac_blocked');
$inactive = userId($db, 'rbac_inactive');

if (!$permissions->hasPermission($regular, 'notes.use')) {
    failRbac('regular user must have notes.use');
}
if ($permissions->hasPermission($regular, 'admin.access')) {
    failRbac('regular user must not have admin.access');
}
if (!$permissions->hasPermission($admin, 'admin.access')) {
    failRbac('admin must have admin.access');
}
if (!$permissions->hasPermission($admin, 'admin.users.manage')) {
    failRbac('admin must have admin.users.manage');
}
if ($permissions->hasPermission($admin, 'admin.roles.manage')) {
    failRbac('admin.roles.manage must remain superadmin-only');
}
if (!$permissions->hasPermission($superadmin, 'admin.roles.manage')) {
    failRbac('superadmin must have admin.roles.manage');
}
if ($permissions->hasPermission($superadmin, 'admin.typo-does-not-exist')) {
    failRbac('unknown permission must fail closed even for superadmin');
}
if ($permissions->hasPermission($blocked, 'notes.use')) {
    failRbac('blocked account must have no effective permission');
}
if ($permissions->hasPermission($inactive, 'notes.use')) {
    failRbac('inactive account must have no effective permission');
}

$regularRoles = $permissions->roleCodesForUser($regular);
if ($regularRoles !== ['user']) {
    failRbac('regular fixture must resolve exactly the user role');
}

$adminPermissions = $permissions->permissionsForUser($admin);
foreach (['admin.access', 'admin.settings.manage', 'admin.users.manage', 'files.use', 'messenger.use', 'notes.use', 'profile.use', 'tasks.use'] as $required) {
    if (!in_array($required, $adminPermissions, true)) {
        failRbac("admin permission set is missing {$required}");
    }
}
if (in_array('admin.roles.manage', $adminPermissions, true)) {
    failRbac('admin permission list must not expose superadmin-only role management');
}

echo "RBAC permission runtime contract OK\n";
