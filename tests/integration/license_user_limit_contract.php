<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('SITEPATH')) {
    define('SITEPATH', $root);
}

require_once $root . '/core/Environment.php';
if (is_file($root . '/.env')) {
    \Core\Environment::load($root . '/.env');
}
require_once $root . '/core/RuntimeAutoloader.php';
\Core\RuntimeAutoloader::register($root);
require_once $root . '/core/config.php';
require_once $root . '/app/handlers/UUID.php';
require_once $root . '/app/services/LicenseVerifier.php';

use App\Services\LicenseSeatPolicy;
use App\Services\LicenseService;
use App\Services\LicenseVerifier;
use App\Services\UserProvisioningService;
use Core\DatabaseManager;
use Core\LocalControlPlaneContext;
use DomainException;

function seatAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] license user limit: {$message}\n");
        exit(1);
    }
}

/** @param array<string,mixed> $payload */
function seatToken(string $keyId, array $payload, string $secretKey): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $encoded = LicenseVerifier::base64UrlEncode($json);
    $signed = LicenseVerifier::TOKEN_PREFIX . '.' . $keyId . '.' . $encoded;
    $signature = sodium_crypto_sign_detached($signed, $secretKey);
    return $signed . '.' . LicenseVerifier::base64UrlEncode($signature);
}

function insertSeatFixtureUser(DatabaseManager $db, string $name, bool $active = true): int
{
    $db->execute(
        'INSERT INTO users '
        . '(uid,username,email,password_hash,firstname,lastname,property,role,is_active,account_status,created_at,updated_at) '
        . 'VALUES (:uid,:username,:email,:password_hash,:firstname,:lastname,:property,888,:is_active,:status,NOW(),NOW())',
        [
            ':uid' => UUID::v4(),
            ':username' => $name,
            ':email' => $name . '@example.test',
            ':password_hash' => password_hash('SeatContract123!', PASSWORD_DEFAULT),
            ':firstname' => 'Seat',
            ':lastname' => 'Contract',
            ':property' => json_encode([], JSON_THROW_ON_ERROR),
            ':is_active' => $active ? 1 : 0,
            ':status' => $active ? 'active' : 'inactive',
        ]
    );
    return (int) $db->getPdo()->lastInsertId();
}

function seatInput(string $name): array
{
    return [
        'login' => $name,
        'email' => $name . '@example.test',
        'password' => 'SeatContract123!',
        'first_name' => 'Seat',
        'surname' => 'Contract',
        'patronymic' => '',
        'user_phone' => '',
    ];
}

$db = DatabaseManager::getInstance();
$db->execute('DELETE FROM users');
$db->execute(
    "UPDATE system_settings SET setting_value = '' WHERE setting_key = :key",
    [':key' => LicenseService::LICENSE_TOKEN_KEY]
);

$keyPair = sodium_crypto_sign_keypair();
$publicKey = sodium_crypto_sign_publickey($keyPair);
$secretKey = sodium_crypto_sign_secretkey($keyPair);
$keyId = 'seat-test-2026';
$now = time();

$verifier = new LicenseVerifier(
    [$keyId => LicenseVerifier::base64UrlEncode($publicKey)],
    static fn (): int => $now
);
$licenses = new LicenseService($db, null, $verifier);
$installationId = $licenses->installationId();
$context = LocalControlPlaneContext::forCli();

$firstId = insertSeatFixtureUser($db, 'seat-first');

$basePayload = [
    'v' => LicenseVerifier::PAYLOAD_VERSION,
    'license_id' => 'lic-seat-one',
    'installation_id' => $installationId,
    'issued_at' => $now - 30,
    'expires_at' => null,
    'edition' => 'team',
    'max_users' => 1,
    'features' => ['workspace.notes'],
];

$oneSeatToken = seatToken($keyId, $basePayload, $secretKey);
$status = $licenses->activateFromControlPlane($context, $oneSeatToken);
seatAssert(($status['valid'] ?? false) === true, 'one-seat license activation failed');
seatAssert(($status['max_users'] ?? null) === 1, 'max_users missing from license status');

$seatPolicy = new LicenseSeatPolicy($db, $licenses);
$usage = $seatPolicy->usage($status);
seatAssert($usage['active_users'] === 1, 'active user count is wrong');
seatAssert($usage['remaining_users'] === 0, 'remaining seat count is wrong');

$users = new UserProvisioningService($db, null, $seatPolicy);
$blocked = false;
try {
    $users->createSelfService(seatInput('seat-blocked'));
} catch (DomainException $e) {
    $blocked = str_contains($e->getMessage(), 'лимит лицензии');
}
seatAssert($blocked, 'provisioning exceeded signed max_users');

$db->execute(
    "UPDATE users SET is_active = 0, account_status = 'inactive' WHERE id = :id",
    [':id' => $firstId]
);
$createdId = $users->createSelfService(seatInput('seat-replacement'));
seatAssert($createdId > 0, 'deactivated account did not free a seat');
seatAssert($seatPolicy->activeUsers() === 1, 'replacement user did not consume exactly one seat');

$db->execute(
    "UPDATE users SET is_active = 1, account_status = 'active' WHERE id = :id",
    [':id' => $firstId]
);
seatAssert($seatPolicy->activeUsers() === 2, 'fixture did not create over-capacity state');

$tooSmallPayload = $basePayload;
$tooSmallPayload['license_id'] = 'lic-seat-too-small';
$tooSmallToken = seatToken($keyId, $tooSmallPayload, $secretKey);
$storedBefore = (string) $db->fetchValue(
    'SELECT setting_value FROM system_settings WHERE setting_key = :key',
    [':key' => LicenseService::LICENSE_TOKEN_KEY]
);
$rejectedActivation = false;
try {
    $licenses->activateFromControlPlane($context, $tooSmallToken);
} catch (DomainException $e) {
    $rejectedActivation = str_contains($e->getMessage(), 'сейчас активно 2');
}
seatAssert($rejectedActivation, 'license downgrade below current active users was accepted');
$storedAfter = (string) $db->fetchValue(
    'SELECT setting_value FROM system_settings WHERE setting_key = :key',
    [':key' => LicenseService::LICENSE_TOKEN_KEY]
);
seatAssert(hash_equals($storedBefore, $storedAfter), 'rejected license activation replaced the stored token');

$unlimitedPayload = $basePayload;
$unlimitedPayload['license_id'] = 'lic-seat-unlimited';
unset($unlimitedPayload['max_users']);
$unlimitedToken = seatToken($keyId, $unlimitedPayload, $secretKey);
$status = $licenses->activateFromControlPlane($context, $unlimitedToken);
seatAssert(($status['valid'] ?? false) === true, 'legacy/unlimited license activation failed');
seatAssert(($status['max_users'] ?? null) === null, 'license without max_users is not unlimited');

$createdUnlimited = $users->createSelfService(seatInput('seat-unlimited'));
seatAssert($createdUnlimited > 0, 'license without max_users unexpectedly blocked provisioning');
seatAssert($seatPolicy->activeUsers() === 3, 'unlimited license did not allow an additional active user');

$db->execute('DELETE FROM users');
$db->execute(
    "UPDATE system_settings SET setting_value = '' WHERE setting_key = :key",
    [':key' => LicenseService::LICENSE_TOKEN_KEY]
);

sodium_memzero($secretKey);
sodium_memzero($keyPair);

fwrite(STDOUT, "[OK] signed license max_users is enforced across provisioning and activation\n");
