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

use App\Services\LicenseService;
use App\Services\LicenseVerifier;
use Core\DatabaseManager;
use Core\LocalControlPlaneContext;
use Core\ModuleLifecycleStore;
use Core\ModuleRegistry;
use Core\Version;

function controlPlaneAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] core control plane: {$message}\n");
        exit(1);
    }
}

$routerSource = (string) file_get_contents($root . '/core/routerConfig.php');
$guardSource = (string) file_get_contents($root . '/app/middlewares/EnforceLicenseMutation.php');
$baseSource = (string) file_get_contents($root . '/app/views/core/base.php');
$cliSource = (string) file_get_contents($root . '/bin/control.php');
$adminSource = (string) file_get_contents($root . '/modules/admin/AdminRuntimeProvider.php');

controlPlaneAssert(str_contains($routerSource, "'/system'"), 'Core system route group is missing');
controlPlaneAssert(str_contains($routerSource, "'/license'"), 'Core license recovery route is missing');
controlPlaneAssert(str_contains($routerSource, 'LicenseRecoveryController::class'), 'Core recovery controller is not registered');
controlPlaneAssert(str_contains($guardSource, "'/system/license/activate'"), 'license guard does not allow Core activation recovery');
controlPlaneAssert(str_contains($guardSource, "'/system/license/clear'"), 'license guard does not allow Core clear recovery');
controlPlaneAssert(str_contains($guardSource, "localUrl('/system/license')"), 'read-only recovery link still depends on Admin');
controlPlaneAssert(str_contains($baseSource, "route('system_license')"), 'read-only banner still depends on Admin route');
controlPlaneAssert(str_contains($adminSource, "'/license'"), 'Admin UI compatibility route unexpectedly disappeared');
controlPlaneAssert(str_contains($cliSource, '--token-file='), 'CLI secure token-file input is missing');
controlPlaneAssert(str_contains($cliSource, '--stdin'), 'CLI STDIN token input is missing');
controlPlaneAssert(!str_contains($cliSource, '--token='), 'CLI must not accept raw license token in process arguments');

$reflection = new ReflectionClass(LocalControlPlaneContext::class);
$constructor = $reflection->getConstructor();
controlPlaneAssert($constructor !== null && $constructor->isPrivate(), 'control-plane context constructor must remain private');

$db = DatabaseManager::getInstance();
$context = LocalControlPlaneContext::forCli();
$store = new ModuleLifecycleStore($db);
$registry = ModuleRegistry::boot($root . '/modules', Version::VERSION, $store);

$disabled = $registry->transitionLifecycle('admin', 'disabled');
controlPlaneAssert(($disabled['configured_state'] ?? null) === 'disabled', 'Admin configured state did not become disabled');
controlPlaneAssert(($disabled['effective_state'] ?? null) === 'disabled', 'Admin effective state did not become disabled');

$keypair = sodium_crypto_sign_keypair();
$publicKey = sodium_crypto_sign_publickey($keypair);
$secretKey = sodium_crypto_sign_secretkey($keypair);
$keyId = 'control-plane-test';
$verifier = new LicenseVerifier(
    [$keyId => LicenseVerifier::base64UrlEncode($publicKey)],
    static fn (): int => 1_789_700_000
);
$service = new LicenseService($db, null, $verifier);
$installationId = $service->installationId();

$payload = [
    'v' => LicenseVerifier::PAYLOAD_VERSION,
    'license_id' => 'control-plane-test-license',
    'installation_id' => $installationId,
    'issued_at' => 1_789_699_900,
    'expires_at' => null,
    'edition' => 'test',
    'features' => ['workspace.admin'],
];
$payloadEncoded = LicenseVerifier::base64UrlEncode(
    json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
);
$signed = LicenseVerifier::TOKEN_PREFIX . '.' . $keyId . '.' . $payloadEncoded;
$signature = sodium_crypto_sign_detached($signed, $secretKey);
$token = $signed . '.' . LicenseVerifier::base64UrlEncode($signature);

$activated = $service->activateFromControlPlane($context, $token);
controlPlaneAssert(($activated['valid'] ?? false) === true, 'local control plane could not activate a valid license');
controlPlaneAssert(($activated['license_id'] ?? '') === 'control-plane-test-license', 'activated license id drifted');

$registry = ModuleRegistry::boot($root . '/modules', Version::VERSION, $store);
$afterLicense = $registry->lifecycleFor('admin');
controlPlaneAssert(
    ($afterLicense['configured_state'] ?? null) === 'disabled'
        && ($afterLicense['effective_state'] ?? null) === 'disabled',
    'license activation changed Admin lifecycle state'
);

$cleared = $service->clearFromControlPlane($context);
controlPlaneAssert(($cleared['code'] ?? '') === 'unlicensed', 'local control plane did not clear the license');

$registry = ModuleRegistry::boot($root . '/modules', Version::VERSION, $store);
$enabled = $registry->transitionLifecycle('admin', 'enabled');
controlPlaneAssert(($enabled['configured_state'] ?? null) === 'enabled', 'explicit Admin enable failed');
controlPlaneAssert(($enabled['effective_state'] ?? null) === 'enabled', 'explicit Admin enable did not become effective');

fwrite(STDOUT, "[OK] core control plane keeps license and module lifecycle independent\n");
