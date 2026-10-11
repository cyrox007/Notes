<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/CliRuntime.php';
\Core\CliRuntime::loadEnvironment($root);
require_once $root . '/core/Version.php';
require_once $root . '/core/UpdateManifestVerifier.php';
require_once $root . '/core/UpdatePackageStager.php';
require_once $root . '/core/UpdateArchiveInspector.php';

use Core\UpdateArchiveInspector;
use Core\UpdateManifestVerifier;
use Core\UpdatePackageStager;
use Core\Version;

$options = getopt('', [
    'manifest:',
    'signature:',
    'package:',
    'stage-root:',
    'verify-only',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo "Предварительная проверка подписанного обновления Workspace Organizer\n\n";
    echo "Только проверить:\n";
    echo "  php bin/update.php --manifest=/path/update.json --signature=/path/update.sig \\\n";
    echo "      --package=/path/package.zip --verify-only [--json]\n\n";
    echo "Проверить и подготовить пакет вне рабочего дерева приложения:\n";
    echo "  php bin/update.php --manifest=/path/update.json --signature=/path/update.sig \\\n";
    echo "      --package=/path/package.zip [--stage-root=/absolute/external/path] [--json]\n\n";
    echo "Подпись/хеш пакета и структура ZIP проверяются до подготовки.\n";
    echo "Команда НИКОГДА не распаковывает пакет и не изменяет рабочие файлы приложения.\n";
    exit(0);
}

$json = isset($options['json']);

/** @return never */
function updaterFail(string $message, string $code = 'update_failed', int $exitCode = 1): never
{
    global $json;
    if ($json) {
        echo json_encode([
            'status' => 'fail',
            'code' => $code,
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, "[FAIL] {$message}\n");
    }
    exit($exitCode);
}

function updaterLocalFile(string $value, string $label, int $maxBytes = 0): string
{
    $value = trim($value);
    if ($value === '' || str_contains($value, '://')) {
        updaterFail("{$label}: требуется явный путь к локальному файлу", 'invalid_input', 2);
    }
    if (!is_file($value) || is_link($value) || !is_readable($value)) {
        updaterFail("{$label}: требуется читаемый обычный локальный файл, не символическая ссылка", 'invalid_input', 2);
    }
    if ($maxBytes > 0) {
        $size = filesize($value);
        if (!is_int($size) || $size <= 0 || $size > $maxBytes) {
            updaterFail("{$label}: некорректный размер файла", 'invalid_input', 2);
        }
    }
    $real = realpath($value);
    if (!is_string($real)) {
        updaterFail("{$label}: не удалось разрешить путь", 'invalid_input', 2);
    }
    return $real;
}

$manifestPath = updaterLocalFile(
    (string) ($options['manifest'] ?? ''),
    'manifest',
    UpdateManifestVerifier::MAX_MANIFEST_BYTES
);
$signaturePath = updaterLocalFile((string) ($options['signature'] ?? ''), 'signature', 1024);
$packagePath = updaterLocalFile((string) ($options['package'] ?? ''), 'package');

$manifestBytes = file_get_contents($manifestPath);
$signatureToken = file_get_contents($signaturePath);
if (!is_string($manifestBytes) || !is_string($signatureToken)) {
    updaterFail('Не удалось прочитать манифест обновления или подпись', 'read_failed');
}

try {
    $verifier = new UpdateManifestVerifier();
    if (!$verifier->hasTrustedKeys()) {
        updaterFail(
            'Не настроены доверенные публичные ключи обновлений. Подписанные обновления остаются отключены до настройки production trust root.',
            'trust_not_configured'
        );
    }

    $status = $verifier->verify($manifestBytes, trim($signatureToken));
    if (!($status['valid'] ?? false)) {
        updaterFail(
            (string) ($status['message'] ?? 'Проверка манифеста обновления завершилась ошибкой'),
            (string) ($status['code'] ?? 'manifest_invalid')
        );
    }
    $manifest = $status['manifest'] ?? null;
    if (!is_array($manifest)) {
        updaterFail('В проверенном манифесте отсутствуют данные обновления', 'manifest_invalid');
    }

    $stager = new UpdatePackageStager($root);
    $stager->assertCompatibility($manifest, Version::VERSION_CODE, PHP_VERSION);
    $package = $stager->verifyPackage($manifest, $packagePath);

    // Структурный аудит архива выполняется только после успешной проверки
    // подписанного размера/хеша. Он работает только на чтение и ничего не распаковывает.
    $archive = (new UpdateArchiveInspector())->inspect($packagePath);

    if (isset($options['verify-only'])) {
        $result = [
            'status' => 'verified',
            'installed_version' => Version::VERSION,
            'installed_version_code' => Version::VERSION_CODE,
            'target_version' => (string) $manifest['version'],
            'target_version_code' => (int) $manifest['version_code'],
            'key_id' => $status['key_id'],
            'package_sha256' => $package['sha256'],
            'archive' => $archive,
        ];
        if ($json) {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        } else {
            echo "[OK] Подписанное обновление и ZIP-пакет успешно проверены\n";
            echo "Установлено: " . Version::VERSION . ' (' . Version::VERSION_CODE . ")\n";
            echo "Цель:       " . $manifest['version'] . ' (' . $manifest['version_code'] . ")\n";
            echo "ID ключа:   " . $status['key_id'] . "\n";
            echo "SHA-256:    " . $package['sha256'] . "\n";
            echo "Записей:    " . $archive['entries'] . ' (' . $archive['files'] . " файлов)\n";
            echo "Рабочие файлы не изменялись.\n";
        }
        exit(0);
    }

    $stageRoot = trim((string) ($options['stage-root'] ?? ''));
    if ($stageRoot === '') {
        $configured = getenv('UPDATE_STAGING_PATH');
        if (is_string($configured) && trim($configured) !== '') {
            $stageRoot = trim($configured);
        } else {
            $private = getenv('PRIVATE_STORAGE_PATH');
            if (is_string($private) && trim($private) !== '') {
                $stageRoot = rtrim(trim($private), '/\\') . DIRECTORY_SEPARATOR . 'updates';
            }
        }
    }
    if ($stageRoot === '') {
        updaterFail(
            'Не настроен внешний staging-каталог. Задайте UPDATE_STAGING_PATH, PRIVATE_STORAGE_PATH или --stage-root.',
            'staging_not_configured',
            2
        );
    }

    $staged = $stager->stage(
        $manifest,
        $manifestBytes,
        trim($signatureToken),
        $packagePath,
        $stageRoot,
        Version::VERSION_CODE,
        PHP_VERSION
    );

    $result = [
        'status' => 'staged',
        'installed_version' => Version::VERSION,
        'target_version' => (string) $manifest['version'],
        'target_version_code' => (int) $manifest['version_code'],
        'key_id' => $status['key_id'],
        'stage_dir' => $staged['stage_dir'],
        'package_sha256' => $staged['sha256'],
        'archive' => $archive,
        'live_files_changed' => false,
    ];
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        echo "[OK] Подписанное обновление проверено, проаудировано и подготовлено\n";
        echo "Цель:      " . $manifest['version'] . ' (' . $manifest['version_code'] . ")\n";
        echo "Staging:   " . $staged['stage_dir'] . "\n";
        echo "SHA-256:   " . $staged['sha256'] . "\n";
        echo "Записей:   " . $archive['entries'] . ' (' . $archive['files'] . " файлов)\n";
        echo "Рабочие файлы не изменялись. Применение/откат намеренно не выполняются на этом слое.\n";
    }
} catch (Throwable $e) {
    updaterFail($e->getMessage());
}
