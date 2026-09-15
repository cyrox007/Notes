<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/app/handlers/CryptMethods.php';
require_once $root . '/app/handlers/UUID.php';
require_once $root . '/app/services/PermissionService.php';
require_once $root . '/app/services/UserProvisioningService.php';
require_once $root . '/app/services/RegistrationPolicyService.php';

use App\Services\PermissionService;
use App\Services\RegistrationPolicyService;
use App\Services\UserProvisioningService;
use Core\DatabaseManager;

function failRegistration(string $message): never
{
    fwrite(STDERR, "Registration policy contract failed: {$message}\n");
    exit(1);
}

function expectCode(callable $operation, int $code, string $message): void
{
    try {
        $operation();
    } catch (DomainException|InvalidArgumentException $e) {
        if ((int) $e->getCode() === $code) {
            return;
        }
        failRegistration($message . " (unexpected code {$e->getCode()}: {$e->getMessage()})");
    }
    failRegistration($message);
}

/** @return array<string,string> */
function userInput(string $suffix): array
{
    return [
        'login' => 'reg-' . $suffix,
        'email' => 'reg-' . $suffix . '@example.test',
        'password' => 'StrongPass-' . $suffix . '-2026',
        'first_name' => 'Регистрация',
        'patronymic' => '',
        'surname' => 'Тест',
        'user_phone' => '',
    ];
}

$db = DatabaseManager::getInstance();
$permissions = new PermissionService($db);
$users = new UserProvisioningService($db, $permissions);
$policy = new RegistrationPolicyService($db, $permissions);

$adminId = (int) $db->fetchValue("SELECT id FROM users WHERE username='registration-admin' LIMIT 1");
$userId = (int) $db->fetchValue("SELECT id FROM users WHERE username='registration-user' LIMIT 1");
if ($adminId <= 0 || $userId <= 0) {
    failRegistration('missing RBAC fixtures');
}

putenv('REGISTRATION_INVITE_CODE');
if ($policy->mode() !== RegistrationPolicyService::MODE_DISABLED) {
    failRegistration('default mode must be disabled when no DB policy and no legacy invite exist');
}

putenv('REGISTRATION_INVITE_CODE=legacy-registration-code');
if ($policy->mode() !== RegistrationPolicyService::MODE_INVITE) {
    failRegistration('legacy invite must preserve invite-only mode until explicit DB policy is saved');
}
expectCode(
    static fn () => $policy->register(userInput('legacy-bad'), 'wrong-code', $users),
    403,
    'legacy invite mode accepted an invalid code'
);
$legacyUserId = $policy->register(userInput('legacy-ok'), 'legacy-registration-code', $users);
if ($legacyUserId <= 0) {
    failRegistration('legacy invite compatibility did not create a user');
}

$policy->saveMode($adminId, RegistrationPolicyService::MODE_OPEN);
if ($policy->mode() !== RegistrationPolicyService::MODE_OPEN) {
    failRegistration('admin could not enable open registration');
}
expectCode(
    static fn () => $policy->saveMode($userId, RegistrationPolicyService::MODE_DISABLED),
    403,
    'ordinary user changed registration mode'
);
expectCode(
    static fn () => $policy->saveMode($adminId, 'anything-goes'),
    422,
    'unknown registration mode was accepted'
);

$openUserId = $policy->register(userInput('open'), null, $users);
$openUser = $db->fetchOne(
    'SELECT role,is_active,account_status,password_hash FROM users WHERE id = :id',
    [':id' => $openUserId]
);
if (
    !$openUser
    || (int) $openUser['role'] !== 888
    || (int) $openUser['is_active'] !== 1
    || (string) $openUser['account_status'] !== 'active'
    || !password_verify('StrongPass-open-2026', (string) $openUser['password_hash'])
) {
    failRegistration('open registration created an invalid account state');
}
if ($permissions->roleCodesForUser($openUserId) !== ['user']) {
    failRegistration('self-registered user did not receive canonical RBAC user role');
}

$adminCreatedId = $users->createByAdmin($adminId, userInput('admin-created'));
if ($adminCreatedId <= 0 || $permissions->roleCodesForUser($adminCreatedId) !== ['user']) {
    failRegistration('admin provisioning did not create a basic RBAC user');
}
expectCode(
    static fn () => $users->createByAdmin($userId, userInput('forbidden-admin-create')),
    403,
    'ordinary user bypassed admin.users.manage during provisioning'
);

$policy->saveMode($adminId, RegistrationPolicyService::MODE_INVITE);
expectCode(
    static fn () => $policy->register(userInput('invite-empty'), '', $users),
    403,
    'invite-only registration accepted an empty invite'
);
expectCode(
    static fn () => $policy->register(userInput('invite-bad'), 'not-a-real-invite', $users),
    403,
    'invite-only registration accepted an unknown invite'
);

$invite = $policy->createInvite($adminId, 'Одноразовый тест', 1, date('Y-m-d H:i:s', time() + 3600));
if (($invite['code'] ?? '') === '' || ($invite['id'] ?? '') === '') {
    failRegistration('managed invite was not created');
}
$storedInviteJson = (string) $db->fetchValue(
    "SELECT setting_value FROM system_settings WHERE setting_key='registration_invites_json' LIMIT 1"
);
if (str_contains($storedInviteJson, $invite['code'])) {
    failRegistration('plaintext managed invite leaked into system_settings');
}
if (!str_contains($storedInviteJson, hash('sha256', $invite['code']))) {
    failRegistration('managed invite hash is missing from system_settings');
}

$invitedUserId = $policy->register(userInput('invite-ok'), $invite['code'], $users);
if ($invitedUserId <= 0 || $permissions->roleCodesForUser($invitedUserId) !== ['user']) {
    failRegistration('valid managed invite did not create a canonical user');
}
expectCode(
    static fn () => $policy->register(userInput('invite-reuse'), $invite['code'], $users),
    403,
    'one-use invite was accepted twice'
);

$revokable = $policy->createInvite($adminId, 'Отзываемый тест', 5, null);
$policy->revokeInvite($adminId, $revokable['id']);
expectCode(
    static fn () => $policy->register(userInput('revoked'), $revokable['code'], $users),
    403,
    'revoked invite was accepted'
);

expectCode(
    static fn () => $policy->createInvite($adminId, 'Просроченный', 1, date('Y-m-d H:i:s', time() - 60)),
    422,
    'past invite expiry was accepted'
);

$policy->saveMode($adminId, RegistrationPolicyService::MODE_DISABLED);
expectCode(
    static fn () => $policy->register(userInput('disabled'), null, $users),
    403,
    'disabled public registration still created a user'
);

// Once an explicit DB mode exists, the legacy env secret must no longer be an
// authorization path.
putenv('REGISTRATION_INVITE_CODE=legacy-registration-code');
if ($policy->mode() !== RegistrationPolicyService::MODE_DISABLED) {
    failRegistration('legacy invite overrode explicit DB registration policy');
}

$invites = $policy->listInvites($adminId);
if (count($invites) < 2) {
    failRegistration('admin invite list is incomplete');
}
expectCode(
    static fn () => $policy->listInvites($userId),
    403,
    'ordinary user could list managed invites'
);

echo "Registration policy runtime contract OK\n";
