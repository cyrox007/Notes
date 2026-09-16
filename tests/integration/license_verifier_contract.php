<?php

declare(strict_types=1);

use App\Services\LicenseVerifier;

$root = dirname(__DIR__, 2);
require_once $root . '/app/services/LicenseVerifier.php';

function licenseAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

function licenseToken(string $keyId, array $payload, string $secretKey): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $encoded = LicenseVerifier::base64UrlEncode($json);
    $signed = LicenseVerifier::TOKEN_PREFIX . '.' . $keyId . '.' . $encoded;
    $signature = sodium_crypto_sign_detached($signed, $secretKey);
    return $signed . '.' . LicenseVerifier::base64UrlEncode($signature);
}

licenseAssert(extension_loaded('sodium'), 'sodium extension is required');

$keyPair = sodium_crypto_sign_keypair();
$publicKey = sodium_crypto_sign_publickey($keyPair);
$secretKey = sodium_crypto_sign_secretkey($keyPair);
$keyId = 'test-2026';
$now = 1_700_000_000;
$installationId = '11111111-2222-4333-8444-555555555555';

$verifier = new LicenseVerifier(
    [$keyId => LicenseVerifier::base64UrlEncode($publicKey)],
    static fn (): int => $now
);
licenseAssert($verifier->hasTrustedKeys(), 'injected public key was not loaded');
licenseAssert($verifier->trustedKeyIds() === [$keyId], 'trusted key id list is incorrect');

$payload = [
    'v' => 1,
    'license_id' => 'lic-test-001',
    'installation_id' => $installationId,
    'issued_at' => $now - 60,
    'expires_at' => $now + 3600,
    'edition' => 'standard',
    'customer' => 'Contract Test',
    'features' => ['notes', 'tasks', 'messenger'],
];
$token = licenseToken($keyId, $payload, $secretKey);
$status = $verifier->verify($token, $installationId);
licenseAssert($status['valid'] === true && $status['code'] === 'valid', 'valid license was rejected');
licenseAssert(($status['payload']['license_id'] ?? '') === 'lic-test-001', 'license payload was not preserved');

$otherInstallation = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
$status = $verifier->verify($token, $otherInstallation);
licenseAssert($status['valid'] === false && $status['code'] === 'wrong_installation', 'cross-installation token was accepted');

$parts = explode('.', $token);
$tamperedPayload = $parts[2];
$position = max(0, strlen($tamperedPayload) - 1);
$tamperedPayload[$position] = $tamperedPayload[$position] === 'A' ? 'B' : 'A';
$tampered = $parts[0] . '.' . $parts[1] . '.' . $tamperedPayload . '.' . $parts[3];
$status = $verifier->verify($tampered, $installationId);
licenseAssert($status['valid'] === false && $status['code'] === 'invalid_signature', 'tampered payload did not fail signature verification');

$expiredVerifier = new LicenseVerifier(
    [$keyId => LicenseVerifier::base64UrlEncode($publicKey)],
    static fn (): int => $now + 10_000
);
$status = $expiredVerifier->verify($token, $installationId);
licenseAssert($status['valid'] === false && $status['code'] === 'expired', 'expired license was accepted');

$futurePayload = $payload;
$futurePayload['issued_at'] = $now + 3600;
$futurePayload['expires_at'] = $now + 7200;
$status = $verifier->verify(licenseToken($keyId, $futurePayload, $secretKey), $installationId);
licenseAssert($status['valid'] === false && $status['code'] === 'not_yet_valid', 'future license was accepted');

$perpetualPayload = $payload;
$perpetualPayload['expires_at'] = null;
$status = $verifier->verify(licenseToken($keyId, $perpetualPayload, $secretKey), $installationId);
licenseAssert($status['valid'] === true, 'perpetual license was rejected');

$versionPayload = $payload;
$versionPayload['v'] = 2;
$status = $verifier->verify(licenseToken($keyId, $versionPayload, $secretKey), $installationId);
licenseAssert($status['valid'] === false && $status['code'] === 'unsupported_version', 'unsupported token version was accepted');

$status = $verifier->verify('wo1.unknown.' . $parts[2] . '.' . $parts[3], $installationId);
licenseAssert($status['valid'] === false && $status['code'] === 'unknown_key', 'unknown signing key was accepted');

$status = $verifier->verify('not-a-license', $installationId);
licenseAssert($status['valid'] === false && $status['code'] === 'malformed', 'malformed token was accepted');

$registryPath = $root . '/config/license_trusted_keys.php';
licenseAssert(is_file($registryPath), 'public license trust registry is missing');
$registry = require $registryPath;
licenseAssert(is_array($registry), 'public license trust registry must return an array');
$defaultVerifier = new LicenseVerifier();
licenseAssert(
    $defaultVerifier->trustedKeyIds() === array_values(array_map('strval', array_keys($registry))),
    'default verifier trust ids do not match the public registry'
);
licenseAssert($defaultVerifier->hasTrustedKeys() === ($registry !== []), 'default verifier trust state does not match public registry');

$verifierSource = (string) file_get_contents($root . '/app/services/LicenseVerifier.php');
$serviceSource = (string) file_get_contents($root . '/app/services/LicenseService.php');
$registrySource = (string) file_get_contents($registryPath);
licenseAssert(!str_contains($verifierSource, 'sodium_crypto_sign_secretkey('), 'runtime verifier derives or embeds a private key');
licenseAssert(!str_contains($serviceSource, 'sodium_crypto_sign_secretkey('), 'license service handles private signing material');
licenseAssert(!str_contains($registrySource, 'SECRET'), 'public trust registry appears to contain secret-key material');
licenseAssert(str_contains($verifierSource, 'sodium_crypto_sign_verify_detached'), 'Ed25519 detached signature verification is missing');
licenseAssert(str_contains($verifierSource, 'config/license_trusted_keys.php'), 'default verifier is not wired to the public trust registry');
licenseAssert(str_contains($serviceSource, 'wrong_installation') === false, 'service duplicates cryptographic verification semantics');

sodium_memzero($secretKey);
sodium_memzero($keyPair);
echo "[OK] installation-bound Ed25519 license verifier contract\n";
