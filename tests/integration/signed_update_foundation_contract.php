<?php

declare(strict_types=1);

use Core\UpdateManifestVerifier;
use Core\UpdatePackageStager;
use Core\Version;

$root = dirname(__DIR__, 2);
require_once $root . '/core/UpdateManifestVerifier.php';
require_once $root . '/core/UpdatePackageStager.php';
require_once $root . '/core/Version.php';

function updateAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

function updateRemoveTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            updateRemoveTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

updateAssert(extension_loaded('sodium'), 'sodium extension is required');

$temp = sys_get_temp_dir() . '/wo-update-contract-' . bin2hex(random_bytes(6));
updateAssert(mkdir($temp, 0700, true), 'unable to create external update test directory');

try {
    $packageName = 'workspace-organizer-v-test.zip';
    $packagePath = $temp . '/' . $packageName;
    $packageBytes = "PK\x03\x04" . random_bytes(256);
    updateAssert(file_put_contents($packagePath, $packageBytes) === strlen($packageBytes), 'unable to create package fixture');

    $pair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($pair);
    $secretKey = sodium_crypto_sign_secretkey($pair);
    $keyId = 'update-test-2026';

    $manifest = [
        'schema' => UpdateManifestVerifier::MANIFEST_SCHEMA,
        'product' => 'workspace-organizer',
        'version' => '1.0.0-test.1',
        'version_code' => Version::VERSION_CODE + 1,
        'channel' => 'beta',
        'issued_at' => time() - 5,
        'source_commit' => str_repeat('a', 40),
        'min_source_version_code' => Version::VERSION_CODE,
        'requires_php' => '8.1.0',
        'package' => [
            'filename' => $packageName,
            'sha256' => hash('sha256', $packageBytes),
            'size' => strlen($packageBytes),
            'format' => 'zip',
        ],
        'notes' => 'Updater foundation contract fixture',
    ];
    $manifestBytes = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    $signature = sodium_crypto_sign_detached(UpdateManifestVerifier::DOMAIN . $manifestBytes, $secretKey);
    $signatureToken = UpdateManifestVerifier::SIGNATURE_PREFIX . '.' . $keyId . '.'
        . UpdateManifestVerifier::base64UrlEncode($signature);

    $verifier = new UpdateManifestVerifier([
        $keyId => UpdateManifestVerifier::base64UrlEncode($publicKey),
    ]);
    $status = $verifier->verify($manifestBytes, $signatureToken);
    updateAssert(($status['valid'] ?? false) === true, 'valid update manifest was rejected');
    updateAssert(($status['key_id'] ?? '') === $keyId, 'verified update key id was not preserved');
    updateAssert((int) (($status['manifest']['version_code'] ?? 0)) === Version::VERSION_CODE + 1, 'manifest payload was not preserved');

    $tampered = str_replace('Updater foundation contract fixture', 'Tampered fixture', $manifestBytes);
    $status = $verifier->verify($tampered, $signatureToken);
    updateAssert(($status['code'] ?? '') === 'invalid_signature', 'tampered manifest did not fail signature verification');

    $unknown = str_replace('.' . $keyId . '.', '.unknown-key.', $signatureToken);
    $status = $verifier->verify($manifestBytes, $unknown);
    updateAssert(($status['code'] ?? '') === 'unknown_key', 'unknown update signing key was accepted');

    $wrongDomainSignature = sodium_crypto_sign_detached($manifestBytes, $secretKey);
    $wrongDomainToken = UpdateManifestVerifier::SIGNATURE_PREFIX . '.' . $keyId . '.'
        . UpdateManifestVerifier::base64UrlEncode($wrongDomainSignature);
    $status = $verifier->verify($manifestBytes, $wrongDomainToken);
    updateAssert(($status['code'] ?? '') === 'invalid_signature', 'signature without update domain separation was accepted');

    $futureManifest = $manifest;
    $futureManifest['issued_at'] = time() + UpdateManifestVerifier::CLOCK_SKEW_SECONDS + 60;
    $futureBytes = json_encode($futureManifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    $futureToken = UpdateManifestVerifier::SIGNATURE_PREFIX . '.' . $keyId . '.'
        . UpdateManifestVerifier::base64UrlEncode(
            sodium_crypto_sign_detached(UpdateManifestVerifier::DOMAIN . $futureBytes, $secretKey)
        );
    $status = $verifier->verify($futureBytes, $futureToken);
    updateAssert(($status['code'] ?? '') === 'future_manifest', 'future-issued update manifest was accepted');

    $stager = new UpdatePackageStager($root);
    $verifiedManifest = $verifier->verify($manifestBytes, $signatureToken)['manifest'] ?? null;
    updateAssert(is_array($verifiedManifest), 'verified manifest missing before staging');
    $stager->assertCompatibility($verifiedManifest, Version::VERSION_CODE, PHP_VERSION);
    $package = $stager->verifyPackage($verifiedManifest, $packagePath);
    updateAssert(hash_equals(hash('sha256', $packageBytes), $package['sha256']), 'package hash verification failed');

    $stageRoot = $temp . '/stage';
    $staged = $stager->stage(
        $verifiedManifest,
        $manifestBytes,
        $signatureToken,
        $packagePath,
        $stageRoot,
        Version::VERSION_CODE,
        PHP_VERSION
    );
    updateAssert(is_dir($staged['stage_dir']), 'external staging directory was not created');
    updateAssert(is_file($staged['package']), 'staged package missing');
    updateAssert(hash_equals(hash('sha256', $packageBytes), (string) hash_file('sha256', $staged['package'])), 'staged package hash changed');
    updateAssert(file_get_contents($staged['manifest']) === $manifestBytes, 'staged manifest bytes changed');
    updateAssert(trim((string) file_get_contents($staged['signature'])) === $signatureToken, 'staged signature changed');

    $again = $stager->stage(
        $verifiedManifest,
        $manifestBytes,
        $signatureToken,
        $packagePath,
        $stageRoot,
        Version::VERSION_CODE,
        PHP_VERSION
    );
    updateAssert($again['stage_dir'] === $staged['stage_dir'], 'staging the same signed package was not idempotent');

    $insideRootRejected = false;
    try {
        $stager->stage(
            $verifiedManifest,
            $manifestBytes,
            $signatureToken,
            $packagePath,
            $root . '/cache/update-contract-stage',
            Version::VERSION_CODE,
            PHP_VERSION
        );
    } catch (Throwable $e) {
        $insideRootRejected = str_contains($e->getMessage(), 'outside the live application tree');
    }
    updateAssert($insideRootRejected, 'staging inside the live application tree was not rejected');
    updateRemoveTree($root . '/cache/update-contract-stage');

    $downgradeRejected = false;
    $downgrade = $verifiedManifest;
    $downgrade['version_code'] = Version::VERSION_CODE;
    try {
        $stager->assertCompatibility($downgrade, Version::VERSION_CODE, PHP_VERSION);
    } catch (Throwable $e) {
        $downgradeRejected = str_contains($e->getMessage(), 'newer');
    }
    updateAssert($downgradeRejected, 'same-version/downgrade update was not rejected');

    $registry = require $root . '/config/update_trusted_keys.php';
    updateAssert(is_array($registry), 'update trusted-key registry must return an array');
    $defaultVerifier = new UpdateManifestVerifier();
    updateAssert($defaultVerifier->trustedKeyIds() === array_values(array_keys($registry)), 'default verifier does not use the public update registry');

    $source = (string) file_get_contents($root . '/core/UpdateManifestVerifier.php');
    updateAssert(!str_contains($source, 'sodium_crypto_sign_secretkey('), 'runtime update verifier handles a private signing key');
    updateAssert(str_contains($source, 'sodium_crypto_sign_verify_detached'), 'runtime update verifier does not verify detached Ed25519 signatures');
    updateAssert(str_contains($source, 'WorkspaceOrganizerUpdateManifest/v1'), 'update signature domain separation is missing');

    sodium_memzero($secretKey);
    sodium_memzero($pair);
    echo "[OK] signed updater foundation contract\n";
} finally {
    updateRemoveTree($temp);
    updateRemoveTree($root . '/cache/update-contract-stage');
}
