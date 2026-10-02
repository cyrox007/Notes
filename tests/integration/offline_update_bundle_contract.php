<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/core/Version.php';
require_once $root . '/core/UpdateManifestVerifier.php';
require_once $root . '/core/UpdateOfflineBundle.php';

use Core\UpdateManifestVerifier;
use Core\UpdateOfflineBundle;
use Core\Version;

function offlineBundleAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] offline update: {$message}\n");
        exit(1);
    }
}

$source = (string) file_get_contents($root . '/core/UpdateOfflineBundle.php');
foreach ([
    'UpdateArchiveInspector',
    'UpdateManifestVerifier',
    'UpdatePackageStager',
    'manifest.json',
    'manifest.sig',
    'MAX_BUNDLE_BYTES',
] as $marker) {
    offlineBundleAssert(str_contains($source, $marker), "нет защитного marker {$marker}");
}

$view = (string) file_get_contents($root . '/modules/admin/views/updates.php');
offlineBundleAssert(str_contains($view, 'Установить обновление из архива'), 'Admin не показывает offline update');
offlineBundleAssert(str_contains($view, 'Произвольный архив приложения не устанавливается'), 'Admin не объясняет запрет неподписанного ZIP');

if (!class_exists(ZipArchive::class)) {
    fwrite(STDOUT, "[OK] offline update source contract; ext-zip недоступен для runtime drill\n");
    exit(0);
}

$temp = sys_get_temp_dir() . '/workspace-offline-update-' . bin2hex(random_bytes(6));
$stage = $temp . '/stage';
if (!mkdir($stage, 0700, true) && !is_dir($stage)) {
    fwrite(STDERR, "[FAIL] offline update: не удалось создать временный staging\n");
    exit(1);
}

function offlineBundleRemoveTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

try {
    $packageName = 'workspace-organizer-v9.9.9-offline-test.zip';
    $packagePath = $temp . '/' . $packageName;
    $inner = new ZipArchive();
    offlineBundleAssert($inner->open($packagePath, ZipArchive::CREATE | ZipArchive::EXCL) === true, 'не удалось создать внутренний ZIP');
    offlineBundleAssert($inner->addFromString('offline-contract.txt', "signed offline update\n"), 'не удалось добавить файл во внутренний ZIP');
    $inner->close();

    $packageSize = filesize($packagePath);
    $packageSha = hash_file('sha256', $packagePath);
    offlineBundleAssert(is_int($packageSize) && $packageSize > 0, 'некорректный размер внутреннего ZIP');
    offlineBundleAssert(is_string($packageSha) && preg_match('/^[0-9a-f]{64}$/D', $packageSha) === 1, 'некорректный SHA-256 внутреннего ZIP');

    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = sodium_crypto_sign_publickey($pair);
    $keyId = 'offline-test-key';

    $manifest = [
        'schema' => 1,
        'product' => 'workspace-organizer',
        'version' => '9.9.9-offline-test',
        'version_code' => Version::VERSION_CODE + 1,
        'channel' => 'stable',
        'issued_at' => time(),
        'source_commit' => str_repeat('a', 40),
        'min_source_version_code' => Version::VERSION_CODE,
        'requires_php' => '8.1',
        'package' => [
            'filename' => $packageName,
            'sha256' => $packageSha,
            'size' => $packageSize,
            'format' => 'zip',
        ],
    ];
    $manifestBytes = json_encode(
        $manifest,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    $signature = sodium_crypto_sign_detached(
        UpdateManifestVerifier::DOMAIN . $manifestBytes,
        $secret
    );
    $token = UpdateManifestVerifier::SIGNATURE_PREFIX
        . '.' . $keyId
        . '.' . UpdateManifestVerifier::base64UrlEncode($signature);

    $outerPath = $temp . '/offline-update.zip';
    $outer = new ZipArchive();
    offlineBundleAssert($outer->open($outerPath, ZipArchive::CREATE | ZipArchive::EXCL) === true, 'не удалось создать внешний ZIP');
    offlineBundleAssert($outer->addFromString('manifest.json', $manifestBytes), 'не удалось добавить manifest');
    offlineBundleAssert($outer->addFromString('manifest.sig', $token . "\n"), 'не удалось добавить signature');
    offlineBundleAssert($outer->addFile($packagePath, $packageName), 'не удалось добавить ZIP релиза');
    $outer->close();

    $verifier = new UpdateManifestVerifier([
        $keyId => UpdateManifestVerifier::base64UrlEncode($public),
    ]);
    $result = (new UpdateOfflineBundle($root, $verifier))->stageFile(
        $outerPath,
        $stage,
        Version::VERSION_CODE,
        PHP_VERSION
    );

    offlineBundleAssert(($result['status'] ?? '') === 'staged', 'offline bundle не перешёл в staged');
    offlineBundleAssert(($result['source'] ?? '') === 'offline_bundle', 'offline bundle потерял источник');
    offlineBundleAssert((int) ($result['target_version_code'] ?? 0) === Version::VERSION_CODE + 1, 'целевая версия изменилась');
    offlineBundleAssert(hash_equals($packageSha, (string) ($result['package_sha256'] ?? '')), 'SHA-256 изменился');

    $stageDir = (string) ($result['stage_dir'] ?? '');
    offlineBundleAssert(is_dir($stageDir), 'immutable stage не создан');
    offlineBundleAssert(is_file($stageDir . '/' . $packageName), 'ZIP релиза отсутствует в stage');
    offlineBundleAssert(
        hash_equals($packageSha, (string) hash_file('sha256', $stageDir . '/' . $packageName)),
        'staged ZIP отличается от подписанного'
    );

    sodium_memzero($secret);
    sodium_memzero($pair);
    fwrite(STDOUT, "[OK] подписанный offline bundle проходит тот же immutable staging\n");
} finally {
    offlineBundleRemoveTree($temp);
}
