<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
putenv('UNIQUE_KEY=two-factor-contract-test-key-0123456789abcdef0123456789');

require_once $root . '/app/handlers/CryptMethods.php';
require_once $root . '/app/services/TwoFactorService.php';

use App\Services\TwoFactorService;

function failTwoFactorContract(string $message): never
{
    fwrite(STDERR, "Two-factor contract failed: {$message}\n");
    exit(1);
}

$service = new TwoFactorService();

// RFC 6238 SHA-1 test secret "12345678901234567890". Workspace uses the
// standard 6-digit truncation, so compare the last six digits of the RFC vectors.
$rfcSecret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
if ($service->codeForTime($rfcSecret, 59) !== '287082') {
    failTwoFactorContract('RFC vector at t=59 did not match 6-digit TOTP');
}
if ($service->codeForTime($rfcSecret, 1111111109) !== '081804') {
    failTwoFactorContract('RFC vector at t=1111111109 did not match 6-digit TOTP');
}

$currentCode = $service->codeForTime($rfcSecret, 59);
$counter = $service->matchingCounter($rfcSecret, $currentCode, null, 59);
if ($counter !== 1) {
    failTwoFactorContract('current TOTP counter was not recognized');
}
if ($service->matchingCounter($rfcSecret, $currentCode, $counter, 59) !== null) {
    failTwoFactorContract('successfully consumed TOTP was accepted a second time');
}

$nextCode = $service->codeForTime($rfcSecret, 89);
if ($service->matchingCounter($rfcSecret, $nextCode, null, 59) !== 2) {
    failTwoFactorContract('one-step positive clock drift was rejected');
}
$previousCode = $service->codeForTime($rfcSecret, 59);
if ($service->matchingCounter($rfcSecret, $previousCode, null, 89) !== 1) {
    failTwoFactorContract('one-step negative clock drift was rejected');
}

$generated = $service->generateSecret();
if (preg_match('/^[A-Z2-7]{32}$/D', $generated) !== 1) {
    failTwoFactorContract('generated secret is not a 160-bit Base32 value');
}

$uri = $service->provisioningUri('alex@example.test', 'Workspace Organizer', $generated);
foreach ([
    'otpauth://totp/',
    'secret=' . $generated,
    'issuer=Workspace%20Organizer',
    'algorithm=SHA1',
    'digits=6',
    'period=30',
] as $fragment) {
    if (!str_contains($uri, $fragment)) {
        failTwoFactorContract('provisioning URI is missing: ' . $fragment);
    }
}

$encrypted = $service->encryptSecret($generated, 'user-contract-uid');
if ($encrypted === $generated || $service->decryptSecret($encrypted, 'user-contract-uid') !== $generated) {
    failTwoFactorContract('TOTP secret encryption round-trip failed');
}
try {
    $service->decryptSecret($encrypted, 'different-user-uid');
    failTwoFactorContract('TOTP secret was decryptable with different user AAD');
} catch (Throwable) {
    // Expected authenticated-decryption failure.
}

$recoveryCodes = $service->generateRecoveryCodes();
if (count($recoveryCodes) !== TwoFactorService::RECOVERY_CODE_COUNT) {
    failTwoFactorContract('unexpected recovery-code count');
}
if (count(array_unique($recoveryCodes)) !== count($recoveryCodes)) {
    failTwoFactorContract('recovery codes are not unique');
}
foreach ($recoveryCodes as $recoveryCode) {
    if (preg_match('/^[A-F0-9]{5}(?:-[A-F0-9]{5}){3}$/D', $recoveryCode) !== 1) {
        failTwoFactorContract('recovery code has unexpected format');
    }
}

$recoverySet = $service->hashRecoveryCodes($recoveryCodes);
$afterFirstUse = $service->consumeRecoveryCodeHashSet($recoverySet, $recoveryCodes[0]);
if (!is_string($afterFirstUse) || $afterFirstUse === $recoverySet) {
    failTwoFactorContract('valid recovery code was not consumed');
}
if ($service->consumeRecoveryCodeHashSet($afterFirstUse, $recoveryCodes[0]) !== null) {
    failTwoFactorContract('consumed recovery code was accepted again');
}

$manifest = file_get_contents($root . '/database/migrations/manifest.json');
$identitySchema = file_get_contents($root . '/database/core_identity_schema.sql');
$authController = file_get_contents($root . '/app/controllers/AuthController.php');
$router = file_get_contents($root . '/core/routerConfig.php');
$profileController = file_get_contents($root . '/modules/profile/controllers/ProfileController.php');
$profileProvider = file_get_contents($root . '/modules/profile/ProfileRuntimeProvider.php');
$profileView = file_get_contents($root . '/modules/profile/views/index.php');
$twoFactorView = file_get_contents($root . '/app/views/login_page/two_factor_view.php');
$docs = file_get_contents($root . '/docs/TWO_FACTOR_AUTH.md');

foreach ([
    'manifest' => $manifest,
    'identity schema' => $identitySchema,
    'auth controller' => $authController,
    'router' => $router,
    'profile controller' => $profileController,
    'profile provider' => $profileProvider,
    'profile view' => $profileView,
    'challenge view' => $twoFactorView,
    'docs' => $docs,
] as $label => $source) {
    if (!is_string($source)) {
        failTwoFactorContract('cannot read ' . $label);
    }
}

if (!str_contains($manifest, '20260921_totp_two_factor.sql')) {
    failTwoFactorContract('TOTP migration is not registered');
}
foreach (['totp_enabled', 'totp_secret', 'totp_last_counter', 'totp_recovery_codes', 'totp_confirmed_at'] as $column) {
    if (!str_contains($identitySchema, $column)) {
        failTwoFactorContract('fresh identity schema is missing ' . $column);
    }
}
foreach (['beginTwoFactor', 'verifyTwoFactor', 'verifyAndConsume', 'two_factor_pending_started_at'] as $fragment) {
    if (!str_contains($authController, $fragment)) {
        failTwoFactorContract('authentication flow is missing ' . $fragment);
    }
}
foreach (['auth_two_factor', 'auth_two_factor_verify', 'TwoFactorRateLimit::class', 'CSRFMiddleware::class'] as $fragment) {
    if (!str_contains($router, $fragment)) {
        failTwoFactorContract('two-factor router contract is missing ' . $fragment);
    }
}
foreach (['startTwoFactorSetup', 'confirmTwoFactorSetup', 'regenerateTwoFactorRecoveryCodes', 'disableTwoFactor'] as $fragment) {
    if (!str_contains($profileController, $fragment) || !str_contains($profileProvider, $fragment)) {
        failTwoFactorContract('profile two-factor contract is missing ' . $fragment);
    }
}

$combined = implode("\n", [$authController, $profileController, $profileView, $twoFactorView, $docs]);
if (str_contains($combined, 'chart.googleapis.com')) {
    failTwoFactorContract('TOTP secret must not be sent to an external QR service');
}
if (preg_match('/\bmd5\s*\(/i', $combined) === 1) {
    failTwoFactorContract('legacy MD5 authentication leaked into the two-factor implementation');
}
if (!str_contains($profileView, 'Сохраните резервные коды сейчас')) {
    failTwoFactorContract('one-time recovery-code disclosure is not represented in profile UI');
}

fwrite(STDOUT, "Two-factor authentication contract: OK\n");
