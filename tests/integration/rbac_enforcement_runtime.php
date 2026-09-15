<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/app/services/PermissionService.php';
require_once $root . '/app/services/UserAvatarService.php';
require_once $root . '/app/services/AdminUserService.php';
require_once $root . '/app/services/StorageQuotaService.php';

use App\Services\AdminUserService;
use App\Services\PermissionService;
use App\Services\StorageQuotaService;
use Core\DatabaseManager;
use DomainException;

function failEnforcement(string $message): never
{
    fwrite(STDERR, "RBAC enforcement contract failed: {$message}\n");
    exit(1);
}

function expectForbidden(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (DomainException $e) {
        if ((int) $e->getCode() === 403) {
            return;
        }
        throw $e;
    }
    failEnforcement($message);
}

function fixtureId(DatabaseManager $db, string $username): int
{
    $id = $db->fetchValue(
        'SELECT id FROM users WHERE username = :username LIMIT 1',
        [':username' => $username]
    );
    if ($id === null) {
        failEnforcement("missing fixture {$username}");
    }
    return (int) $id;
}

$db = DatabaseManager::getInstance();
$permissions = new PermissionService($db);
$adminUsers = new AdminUserService($db, $permissions);
$quotas = new StorageQuotaService($db, $permissions);

$rbacAdmin = fixtureId($db, 'runtime-rbac-admin');
$legacyAdmin = fixtureId($db, 'runtime-legacy-admin');
$target = fixtureId($db, 'runtime-target');
$privilegedTarget = fixtureId($db, 'runtime-privileged-target');

if (!$permissions->hasPermission($rbacAdmin, 'admin.access')) {
    failEnforcement('RBAC admin with legacy role=888 must receive admin.access');
}
if (!$permissions->hasPermission($rbacAdmin, 'admin.users.manage')) {
    failEnforcement('RBAC admin with legacy role=888 must receive admin.users.manage');
}
if (!$permissions->hasPermission($rbacAdmin, 'admin.settings.manage')) {
    failEnforcement('RBAC admin with legacy role=888 must receive admin.settings.manage');
}
if ($permissions->hasPermission($legacyAdmin, 'admin.access')) {
    failEnforcement('legacy numeric role=111 must not grant admin access without RBAC assignment');
}

$users = $adminUsers->listUsers($rbacAdmin);
if (count($users) < 4) {
    failEnforcement('RBAC admin could not list users');
}
expectForbidden(
    static fn () => $adminUsers->listUsers($legacyAdmin),
    'legacy numeric admin bypassed service-layer RBAC'
);

$initialRole = (int) $db->fetchValue('SELECT role FROM users WHERE id = :id', [':id' => $target]);
$initialRoles = $permissions->roleCodesForUser($target);
if ($initialRole !== 888 || $initialRoles !== ['user']) {
    failEnforcement('target fixture must begin as legacy role=888 with RBAC user role');
}

$adminUsers->setStatus($rbacAdmin, $target, 'blocked');
$blocked = $db->fetchOne(
    'SELECT role,is_active,account_status FROM users WHERE id = :id',
    [':id' => $target]
);
if (
    !$blocked
    || (int) $blocked['role'] !== $initialRole
    || (int) $blocked['is_active'] !== 1
    || (string) $blocked['account_status'] !== 'blocked'
) {
    failEnforcement('blocking must change account_status without rewriting authorization identity');
}
if ($permissions->roleCodesForUser($target) !== $initialRoles) {
    failEnforcement('blocking changed RBAC role assignments');
}
if ($permissions->hasPermission($target, 'notes.use')) {
    failEnforcement('blocked account retained an effective module permission');
}

$adminUsers->setStatus($rbacAdmin, $target, 'active');
$reactivated = $db->fetchOne(
    'SELECT role,is_active,account_status FROM users WHERE id = :id',
    [':id' => $target]
);
if (
    !$reactivated
    || (int) $reactivated['role'] !== $initialRole
    || (int) $reactivated['is_active'] !== 1
    || (string) $reactivated['account_status'] !== 'active'
    || !$permissions->hasPermission($target, 'notes.use')
) {
    failEnforcement('reactivation did not restore account availability while preserving RBAC identity');
}

$adminUsers->deactivate($rbacAdmin, $target);
$inactive = $db->fetchOne(
    'SELECT role,is_active,account_status FROM users WHERE id = :id',
    [':id' => $target]
);
if (
    !$inactive
    || (int) $inactive['role'] !== $initialRole
    || (int) $inactive['is_active'] !== 0
    || (string) $inactive['account_status'] !== 'inactive'
) {
    failEnforcement('deactivation must preserve legacy/RBAC role identity and mark account inactive');
}
if ($permissions->roleCodesForUser($target) !== $initialRoles) {
    failEnforcement('deactivation changed RBAC role assignments');
}

$adminUsers->setStatus($rbacAdmin, $target, 'active');
if (!$permissions->hasPermission($target, 'notes.use')) {
    failEnforcement('reactivation after deactivation did not restore effective user permission');
}

expectForbidden(
    static fn () => $adminUsers->setStatus($rbacAdmin, $privilegedTarget, 'blocked'),
    'privileged target was manageable despite an assigned admin capability'
);
expectForbidden(
    static fn () => $adminUsers->setStatus($legacyAdmin, $target, 'blocked'),
    'legacy role=111 bypassed admin.users.manage service boundary'
);

$quotas->setDefaultQuota($rbacAdmin, 20 * 1024 * 1024);
if ($quotas->defaultQuotaBytes() !== 20 * 1024 * 1024) {
    failEnforcement('RBAC settings administrator could not update default quota');
}
$quotas->setUserQuota($rbacAdmin, $target, 12 * 1024 * 1024);
if ($quotas->effectiveQuotaBytes($target) !== 12 * 1024 * 1024) {
    failEnforcement('RBAC settings administrator could not update user quota');
}
expectForbidden(
    static fn () => $quotas->setDefaultQuota($legacyAdmin, 30 * 1024 * 1024),
    'legacy role=111 bypassed admin.settings.manage service boundary'
);

$db->execute(
    "UPDATE users SET account_status = 'blocked' WHERE id = :id",
    [':id' => $rbacAdmin]
);
if ($permissions->hasPermission($rbacAdmin, 'admin.access')) {
    failEnforcement('blocked RBAC administrator retained effective admin permission');
}
expectForbidden(
    static fn () => $quotas->setDefaultQuota($rbacAdmin, 25 * 1024 * 1024),
    'blocked RBAC administrator changed system settings'
);

echo "RBAC runtime enforcement contract OK\n";
