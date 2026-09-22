<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function trustFail(string $message): never
{
    fwrite(STDERR, "[ОШИБКА] production trust domains: {$message}\n");
    exit(1);
}

function trustAssert(bool $condition, string $message): void
{
    if (!$condition) {
        trustFail($message);
    }
}

function trustDecodePublic(string $encoded, string $domain, string $keyId): string
{
    if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) {
        trustFail("{$domain} public key {$keyId} не является canonical base64url");
    }
    $padding = (4 - (strlen($encoded) % 4)) % 4;
    $raw = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', $padding), true);
    if (!is_string($raw) || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        trustFail("{$domain} public key {$keyId} не является raw Ed25519 public key");
    }
    return $raw;
}

trustAssert(extension_loaded('sodium'), 'требуется расширение sodium');

$license = require $root . '/config/license_trusted_keys.php';
$update = require $root . '/config/update_trusted_keys.php';
trustAssert(is_array($license), 'license trust registry должен возвращать массив');
trustAssert(is_array($update), 'update trust registry должен возвращать массив');

$licenseFingerprints = [];
foreach ($license as $keyId => $encoded) {
    trustAssert(
        is_string($keyId) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/D', $keyId) === 1,
        'некорректный license key ID'
    );
    trustAssert(is_string($encoded), "license public key {$keyId} должен быть строкой");
    $licenseFingerprints[$keyId] = hash('sha256', trustDecodePublic($encoded, 'license', $keyId));
}

$updateFingerprints = [];
foreach ($update as $keyId => $encoded) {
    trustAssert(
        is_string($keyId) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,47}$/D', $keyId) === 1,
        'некорректный update key ID'
    );
    trustAssert(is_string($encoded), "update public key {$keyId} должен быть строкой");
    $updateFingerprints[$keyId] = hash('sha256', trustDecodePublic($encoded, 'update', $keyId));
}

trustAssert(
    array_intersect(array_keys($licenseFingerprints), array_keys($updateFingerprints)) === [],
    'license и update key ID должны быть независимыми'
);
trustAssert(
    array_intersect(array_values($licenseFingerprints), array_values($updateFingerprints)) === [],
    'license и update signing domains не должны использовать одну пару Ed25519'
);

$licenseLib = (string) file_get_contents($root . '/tools/vendor-license/lib.php');
$updateLib = (string) file_get_contents($root . '/tools/vendor-update/lib.php');
trustAssert(
    str_contains($licenseLib, "WORKSPACE_LICENSE_SECRET_PREFIX = 'wo-ed25519-secret-v1:'"),
    'изменился domain prefix файла private key лицензии'
);
trustAssert(
    str_contains($updateLib, "WORKSPACE_UPDATE_SECRET_PREFIX = 'wo-update-ed25519-secret-v1:'"),
    'изменился domain prefix файла private key обновления'
);
trustAssert(
    !str_contains($licenseLib, "wo-update-ed25519-secret-v1:"),
    'license tooling ошибочно распознаёт domain private key обновления'
);
trustAssert(
    !str_contains($updateLib, "wo-ed25519-secret-v1:"),
    'update tooling ошибочно распознаёт domain private key лицензии'
);

$releaseGate = (string) file_get_contents($root . '/.github/workflows/release-gate.yml');
trustAssert(
    str_contains($releaseGate, 'Production license public key registry is empty'),
    'master release gate не требует production license public keys'
);
trustAssert(
    str_contains($releaseGate, 'Production update public key registry is empty'),
    'master release gate не требует production update public keys'
);
trustAssert(
    str_contains($releaseGate, 'License/update public keys must use independent key material'),
    'master release gate не проверяет независимость signing key material'
);

$ceremony = (string) file_get_contents($root . '/docs/PRODUCTION_TRUST_CEREMONY.md');
foreach ([
    'offline',
    'config/license_trusted_keys.php',
    'config/update_trusted_keys.php',
    'tools/vendor-license/keygen.php',
    'tools/vendor-update/keygen.php',
    'Никогда не коммитьте',
] as $marker) {
    trustAssert(str_contains($ceremony, $marker), "runbook trust ceremony не содержит marker: {$marker}");
}

fwrite(STDOUT, "[OK] production trust domains лицензий и обновлений остаются независимыми\n");
