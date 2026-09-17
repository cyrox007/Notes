<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/app/services/PermissionService.php';
require_once $root . '/app/services/RolePolicyService.php';
require_once $root . '/modules/admin/services/RoleManagementService.php';

use App\Services\PermissionService;
use App\Services\RoleManagementService;
use App\Services\RolePolicyService;
use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;

function failRolePolicy(string $message): never
{
    fwrite(STDERR, "Role policy runtime contract failed: {$message}\n");
    exit(1);
}

function fixtureUserId(DatabaseManager $db, string $username): int
{
    $id = $db->fetchValue('SELECT id FROM users WHERE username = :username LIMIT 1', [':username' => $username]);
    if ($id === null) {
        failRolePolicy("missing fixture user {$username}");
    }
    return (int) $id;
}

function assertSamePolicy(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        failRolePolicy($message . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual));
    }
}

$db = DatabaseManager::getInstance();
$permissions = new PermissionService($db);
$policies = new RolePolicyService($db, $permissions);
$roles = new RoleManagementService($db, $permissions, $policies);

$ownerId = fixtureUserId($db, 'policy_superadmin');
$userId = fixtureUserId($db, 'policy_user');

if (!$permissions->hasPermission($ownerId, 'admin.roles.manage')) {
    failRolePolicy('fixture owner must be able to manage roles');
}

$limitedRoleId = $roles->createRole($ownerId, 'limited_worker', 'Ограниченный сотрудник', 'Beta 4 policy fixture');
$roles->updateRole($ownerId, $limitedRoleId, 'Ограниченный сотрудник', 'Beta 4 policy fixture', ['files.use', 'notes.use']);
$roles->assignRoles($ownerId, $userId, [$limitedRoleId]);

if (!$permissions->hasPermission($userId, 'files.use')) {
    failRolePolicy('assigned custom role must grant files.use');
}
if ($permissions->hasPermission($userId, 'tasks.use')) {
    failRolePolicy('custom role must not inherit tasks.use from removed base role');
}

$policies->replaceRolePolicies($ownerId, $limitedRoleId, [
    'files' => [
        'max_file_bytes' => '1048576',
        'max_storage_bytes' => '5242880',
        'allowed_extensions' => 'pdf, jpg',
        'can_create_folders' => '0',
    ],
    'tasks' => [
        'can_create_shared_boards' => '0',
        'max_owned_boards' => '2',
    ],
]);

assertSamePolicy(1048576, $policies->effectiveValue($userId, 'files', 'max_file_bytes'), 'single-role file size cap');
assertSamePolicy(5242880, $policies->effectiveValue($userId, 'files', 'max_storage_bytes'), 'single-role storage cap');
assertSamePolicy(['pdf', 'jpg'], $policies->effectiveValue($userId, 'files', 'allowed_extensions'), 'single-role extension list');
assertSamePolicy(false, $policies->effectiveValue($userId, 'files', 'can_create_folders'), 'single-role folder flag');
assertSamePolicy(false, $policies->effectiveValue($userId, 'tasks', 'can_create_shared_boards'), 'single-role shared-board flag');

$broaderRoleId = $roles->createRole($ownerId, 'media_worker', 'Медиа сотрудник', 'Composition fixture');
$roles->updateRole($ownerId, $broaderRoleId, 'Медиа сотрудник', 'Composition fixture', ['files.use']);
$policies->replaceRolePolicies($ownerId, $broaderRoleId, [
    'files' => [
        'max_file_bytes' => '2097152',
        'allowed_extensions' => 'docx, jpg',
        'can_create_folders' => '1',
    ],
]);
$roles->assignRoles($ownerId, $userId, [$limitedRoleId, $broaderRoleId]);

assertSamePolicy(2097152, $policies->effectiveValue($userId, 'files', 'max_file_bytes'), 'multiple roles must use broader positive integer cap');
assertSamePolicy(true, $policies->effectiveValue($userId, 'files', 'can_create_folders'), 'multiple roles must OR boolean capabilities');
$extensions = $policies->effectiveValue($userId, 'files', 'allowed_extensions');
sort($extensions);
assertSamePolicy(['docx', 'jpg', 'pdf'], $extensions, 'multiple roles must union extension allow-lists');

$policies->replaceRolePolicies($ownerId, $broaderRoleId, [
    'files' => [
        'max_file_bytes' => '0',
        'allowed_extensions' => [],
        'can_create_folders' => '1',
    ],
]);
assertSamePolicy(0, $policies->effectiveValue($userId, 'files', 'max_file_bytes'), 'explicit zero in one role must remove the role-specific integer cap');
assertSamePolicy([], $policies->effectiveValue($userId, 'files', 'allowed_extensions'), 'empty explicit list must fall back to platform allow-list');

try {
    $policies->replaceRolePolicies($ownerId, $limitedRoleId, ['files' => ['max_file_bytes' => '-1']]);
    failRolePolicy('negative integer policy must be rejected');
} catch (InvalidArgumentException) {
    // expected
}

try {
    $roles->deleteRole($ownerId, $limitedRoleId);
    failRolePolicy('assigned role must not be deletable');
} catch (DomainException) {
    // expected
}

assertSamePolicy(0, $policies->effectiveValue($ownerId, 'files', 'max_file_bytes'), 'superadmin must bypass role-specific file cap');
assertSamePolicy(true, $policies->effectiveValue($ownerId, 'files', 'can_create_folders'), 'superadmin must keep default full feature access');

echo "Role policy runtime contract OK\n";
