<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
putenv('UNIQUE_KEY=two-factor-contract-test-key-0123456789abcdef0123456789');

require_once $root . '/app/handlers/CryptMethods.php';
require_once $root . '/app/services/TwoFactorService.php';

use App\Services\TwoFactorService;

function failTwoFactorContract(string $message): never
{
    fwrite(STDERR, "Контракт двухфакторной аутентификации не пройден: {$message}\n");
    exit(1);
}

$service = new TwoFactorService();

// Тестовый секрет RFC 6238 SHA-1 «12345678901234567890». Workspace использует
// стандартное шестизначное усечение, поэтому сравниваются последние шесть цифр RFC-векторов.
$rfcSecret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
if ($service->codeForTime($rfcSecret, 59) !== '287082') {
    failTwoFactorContract('RFC-вектор для t=59 не совпал с шестизначным TOTP');
}
if ($service->codeForTime($rfcSecret, 1111111109) !== '081804') {
    failTwoFactorContract('RFC-вектор для t=1111111109 не совпал с шестизначным TOTP');
}

$currentCode = $service->codeForTime($rfcSecret, 59);
$counter = $service->matchingCounter($rfcSecret, $currentCode, null, 59);
if ($counter !== 1) {
    failTwoFactorContract('текущий счётчик TOTP не распознан');
}
if ($service->matchingCounter($rfcSecret, $currentCode, $counter, 59) !== null) {
    failTwoFactorContract('уже использованный TOTP был принят повторно');
}

$nextCode = $service->codeForTime($rfcSecret, 89);
if ($service->matchingCounter($rfcSecret, $nextCode, null, 59) !== 2) {
    failTwoFactorContract('отклонение часов на один интервал вперёд было отклонено');
}
$previousCode = $service->codeForTime($rfcSecret, 59);
if ($service->matchingCounter($rfcSecret, $previousCode, null, 89) !== 1) {
    failTwoFactorContract('отклонение часов на один интервал назад было отклонено');
}

$generated = $service->generateSecret();
if (preg_match('/^[A-Z2-7]{32}$/D', $generated) !== 1) {
    failTwoFactorContract('сгенерированный секрет не является 160-битным Base32');
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
        failTwoFactorContract('в URI настройки отсутствует: ' . $fragment);
    }
}

$encrypted = $service->encryptSecret($generated, 'user-contract-uid');
if ($encrypted === $generated || $service->decryptSecret($encrypted, 'user-contract-uid') !== $generated) {
    failTwoFactorContract('не пройдена проверка шифрования и расшифровки TOTP-секрета');
}
try {
    $service->decryptSecret($encrypted, 'different-user-uid');
    failTwoFactorContract('TOTP-секрет расшифровался с AAD другого пользователя');
} catch (Throwable) {
    // Ожидаемый отказ аутентифицированной расшифровки.
}

$recoveryCodes = $service->generateRecoveryCodes();
if (count($recoveryCodes) !== TwoFactorService::RECOVERY_CODE_COUNT) {
    failTwoFactorContract('неожиданное количество резервных кодов');
}
if (count(array_unique($recoveryCodes)) !== count($recoveryCodes)) {
    failTwoFactorContract('резервные коды не уникальны');
}
foreach ($recoveryCodes as $recoveryCode) {
    if (preg_match('/^[A-F0-9]{5}(?:-[A-F0-9]{5}){3}$/D', $recoveryCode) !== 1) {
        failTwoFactorContract('резервный код имеет неожиданный формат');
    }
}

$recoverySet = $service->hashRecoveryCodes($recoveryCodes);
$afterFirstUse = $service->consumeRecoveryCodeHashSet($recoverySet, $recoveryCodes[0]);
if (!is_string($afterFirstUse) || $afterFirstUse === $recoverySet) {
    failTwoFactorContract('действующий резервный код не был поглощён');
}
if ($service->consumeRecoveryCodeHashSet($afterFirstUse, $recoveryCodes[0]) !== null) {
    failTwoFactorContract('использованный резервный код был принят повторно');
}

$manifest = file_get_contents($root . '/database/migrations/manifest.json');
$identitySchema = file_get_contents($root . '/database/core_identity_schema.sql');
$legacyMessengerSchema = file_get_contents($root . '/database/messenger_schema.sql');
$databaseOwnership = file_get_contents($root . '/core/DatabaseOwnership.php');
$authController = file_get_contents($root . '/app/controllers/AuthController.php');
$router = file_get_contents($root . '/core/routerConfig.php');
$profileController = file_get_contents($root . '/modules/profile/controllers/ProfileController.php');
$profileProvider = file_get_contents($root . '/modules/profile/ProfileRuntimeProvider.php');
$profileView = file_get_contents($root . '/modules/profile/views/index.php');
$twoFactorView = file_get_contents($root . '/app/views/login_page/two_factor_view.php');
$docs = file_get_contents($root . '/docs/TWO_FACTOR_AUTH.md');
$policyService = file_get_contents($root . '/app/services/TwoFactorPolicyService.php');
$policyMiddleware = file_get_contents($root . '/app/middlewares/EnforceTwoFactorPolicy.php');
$adminController = file_get_contents($root . '/modules/admin/controllers/SettingsController.php');
$adminView = file_get_contents($root . '/modules/admin/views/settings.php');
$keyRotation = file_get_contents($root . '/app/services/DataKeyRotationService.php');

foreach ([
    'manifest' => $manifest,
    'identity schema' => $identitySchema,
    'legacy messenger schema' => $legacyMessengerSchema,
    'database ownership' => $databaseOwnership,
    'auth controller' => $authController,
    'router' => $router,
    'profile controller' => $profileController,
    'profile provider' => $profileProvider,
    'profile view' => $profileView,
    'challenge view' => $twoFactorView,
    'docs' => $docs,
    'сервис политики 2FA' => $policyService,
    'middleware политики 2FA' => $policyMiddleware,
    'контроллер настроек Admin' => $adminController,
    'представление настроек Admin' => $adminView,
    'ротация ключей' => $keyRotation,
] as $label => $source) {
    if (!is_string($source)) {
        failTwoFactorContract('не удалось прочитать ' . $label);
    }
}

if (!str_contains($manifest, '20260921_totp_two_factor.sql')) {
    failTwoFactorContract('миграция TOTP не зарегистрирована');
}
foreach (['totp_enabled', 'totp_secret', 'totp_last_counter', 'totp_recovery_codes', 'totp_confirmed_at'] as $column) {
    if (!str_contains($identitySchema, $column)) {
        failTwoFactorContract('в схеме новой установки отсутствует ' . $column);
    }
}

foreach (['totp_enabled', 'totp_secret', 'totp_last_counter', 'totp_recovery_codes', 'totp_confirmed_at'] as $column) {
    if (!str_contains($legacyMessengerSchema, $column)) {
        failTwoFactorContract('в совместимой полной схеме отсутствует ' . $column);
    }
}
if (!str_contains($databaseOwnership, "'database/migrations/20260921_totp_two_factor.sql'")) {
    failTwoFactorContract('владение БД не отмечает миграцию TOTP как принадлежащую Core');
}
foreach (['beginTwoFactor', 'verifyTwoFactor', 'verifyAndConsume', 'two_factor_pending_started_at'] as $fragment) {
    if (!str_contains($authController, $fragment)) {
        failTwoFactorContract('в сценарии аутентификации отсутствует ' . $fragment);
    }
}
foreach (['auth_two_factor', 'auth_two_factor_verify', 'TwoFactorRateLimit::class', 'CSRFMiddleware::class'] as $fragment) {
    if (!str_contains($router, $fragment)) {
        failTwoFactorContract('в маршрутах 2FA отсутствует ' . $fragment);
    }
}
foreach (['startTwoFactorSetup', 'confirmTwoFactorSetup', 'regenerateTwoFactorRecoveryCodes', 'disableTwoFactor'] as $fragment) {
    if (!str_contains($profileController, $fragment) || !str_contains($profileProvider, $fragment)) {
        failTwoFactorContract('в контракте 2FA профиля отсутствует ' . $fragment);
    }
}

$combined = implode("\n", [$authController, $profileController, $profileView, $twoFactorView, $docs]);
if (str_contains($combined, 'chart.googleapis.com')) {
    failTwoFactorContract('TOTP-секрет не должен отправляться внешнему сервису QR');
}
if (preg_match('/\bmd5\s*\(/i', $combined) === 1) {
    failTwoFactorContract('в реализацию 2FA попала устаревшая MD5-аутентификация');
}
if (!str_contains($profileView, 'Сохраните резервные коды сейчас')) {
    failTwoFactorContract('в профиле отсутствует однократный показ резервных кодов');
}

foreach ([
    'two_factor_required',
    'setRequired',
] as $fragment) {
    if (!str_contains((string) $policyService, $fragment)) {
        failTwoFactorContract('в сервисе общесистемной политики отсутствует ' . $fragment);
    }
}
foreach ([
    'global_two_factor_required',
    'SessionSecurity::destroyCurrentSession',
] as $fragment) {
    if (!str_contains((string) $policyMiddleware, $fragment)) {
        failTwoFactorContract('в принудительном применении политики отсутствует ' . $fragment);
    }
}
foreach ([
    'saveTwoFactorPolicy',
    'Двухфакторная аутентификация теперь обязательна для всех активных пользователей',
] as $fragment) {
    if (!str_contains((string) $adminController, $fragment)) {
        failTwoFactorContract('в Admin-контроллере политики отсутствует ' . $fragment);
    }
}
foreach ([
    'По выбору пользователя',
    'Обязательно для всех пользователей',
] as $fragment) {
    if (!str_contains((string) $adminView, $fragment)) {
        failTwoFactorContract('в Admin-интерфейсе политики отсутствует ' . $fragment);
    }
}
foreach ([
    'rotateTotpSecrets',
    'totp_users',
    'totp_converted',
    'two-factor-totp-secret:',
] as $fragment) {
    if (!str_contains((string) $keyRotation, $fragment)) {
        failTwoFactorContract('ротация UNIQUE_KEY не покрывает TOTP: ' . $fragment);
    }
}

fwrite(STDOUT, "Контракт двухфакторной аутентификации: OK\n");
