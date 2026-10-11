<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/CliRuntime.php';
\Core\CliRuntime::loadEnvironment($root);
require_once $root . '/core/Version.php';
require_once $root . '/core/UpdateAccessBootstrap.php';
require_once $root . '/core/UpdateManifestVerifier.php';
require_once $root . '/core/UpdatePackageStager.php';
require_once $root . '/core/UpdateArchiveInspector.php';
require_once $root . '/core/UpdateRemoteTransport.php';
require_once $root . '/core/UpdateRemoteDelivery.php';

use App\Services\LicenseService;
use Core\UpdateAccessBootstrap;
use Core\UpdateArchiveInspector;
use Core\UpdateDownloadCredentials;
use Core\UpdateCredentialRefreshingTransport;
use Core\UpdateHttpsTransport;
use Core\UpdateManifestVerifier;
use Core\UpdatePackageStager;
use Core\UpdateRemoteDelivery;
use Core\Version;

$options = getopt('', [
    'feed-url:',
    'channel:',
    'stage-root:',
    'check-only',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo "Workspace Organizer — проверка подписанных обновлений\n\n";
    echo "Проверить канал без загрузки пакета:\n";
    echo "  php bin/update_remote.php --check-only [--feed-url=https://updates.example/feed.json] [--channel=stable] [--json]\n\n";
    echo "Скачать, проверить и подготовить подписанный пакет:\n";
    echo "  php bin/update_remote.php [--feed-url=https://updates.example/feed.json] [--channel=stable] \\\n";
    echo "      [--stage-root=/absolute/external/path] [--json]\n\n";
    echo "По умолчанию используются штатный stable-канал и внешний staging.\n";
    echo "Команда не включает режим обслуживания и не меняет рабочие файлы приложения.\n";
    exit(0);
}

$json = isset($options['json']);

/** @return never */
function remoteUpdaterFail(string $message, string $code = 'remote_update_failed', int $exitCode = 1): never
{
    global $json;
    if ($json) {
        echo json_encode([
            'status' => 'fail',
            'code' => $code,
            'message' => $message,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, "[FAIL] {$message}\n");
    }
    exit($exitCode);
}

$feedUrl = trim((string) ($options['feed-url'] ?? ''));
if ($feedUrl === '') {
    try {
        $feedUrl = UpdateAccessBootstrap::feedUrl();
    } catch (Throwable $e) {
        remoteUpdaterFail($e->getMessage(), 'feed_not_configured', 2);
    }
}

$channel = trim((string) ($options['channel'] ?? ''));
if ($channel === '') {
    try {
        $channel = UpdateAccessBootstrap::channel();
    } catch (Throwable $e) {
        remoteUpdaterFail($e->getMessage(), 'invalid_channel', 2);
    }
}

try {
    if (UpdateDownloadCredentials::accessMode() !== 'offline') {
        \Core\CliRuntime::registerAutoloader($root);
        require_once $root . '/core/Config.php';

        // Штатная CLI-проверка использует тот же автоматический bootstrap по
        // установленной лицензии, что и Admin UI. Готовый credential повторно
        // не ротируется.
        (new LicenseService())->ensureUpdateAccess();
    }

    $verifier = new UpdateManifestVerifier();
    if (!$verifier->hasTrustedKeys()) {
        remoteUpdaterFail(
            'В сборке не настроен доверенный публичный ключ проверки обновлений.',
            'trust_not_configured'
        );
    }

    $transport = new UpdateCredentialRefreshingTransport(
        static fn () => UpdateHttpsTransport::fromEnvironment(),
        static function (): void {
            (new LicenseService())->refreshUpdateAccess();
        }
    );
    $delivery = new UpdateRemoteDelivery(
        $root,
        $verifier,
        $transport,
        new UpdatePackageStager($root),
        new UpdateArchiveInspector()
    );

    if (isset($options['check-only'])) {
        $result = $delivery->check($feedUrl, $channel, Version::VERSION_CODE, PHP_VERSION);
    } else {
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
            remoteUpdaterFail(
                'Не настроен внешний staging. Проверьте UPDATE_STAGING_PATH или PRIVATE_STORAGE_PATH.',
                'staging_not_configured',
                2
            );
        }
        $result = $delivery->stage($feedUrl, $channel, $stageRoot, Version::VERSION_CODE, PHP_VERSION);
    }

    if ($json) {
        echo json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } else {
        $status = (string) ($result['status'] ?? '');
        if ($status === 'update_available') {
            echo "[OK] Доступно подписанное удалённое обновление\n";
            echo 'Канал:     ' . $result['channel'] . PHP_EOL;
            echo 'Цель:      ' . $result['target_version'] . ' (' . $result['target_version_code'] . ')' . PHP_EOL;
            echo 'Пакет:     ' . $result['package_filename'] . ' (' . $result['package_size'] . " байт)\n";
            echo 'ID ключа:  ' . $result['key_id'] . PHP_EOL;
            echo "Пакет не загружался. Рабочие файлы не изменялись.\n";
        } elseif ($status === 'up_to_date') {
            echo "[OK] Установка соответствует настроенному подписанному каналу\n";
            echo 'Код установленной версии: ' . Version::VERSION_CODE . PHP_EOL;
            echo 'Цель канала:              ' . $result['target_version'] . ' (' . $result['target_version_code'] . ')' . PHP_EOL;
            echo "Пакет не загружался. Рабочие файлы не изменялись.\n";
        } elseif ($status === 'ahead_of_feed') {
            echo "[OK] Установленная версия новее настроенного подписанного канала\n";
            echo 'Код установленной версии: ' . Version::VERSION_CODE . PHP_EOL;
            echo 'Цель канала:              ' . $result['target_version'] . ' (' . $result['target_version_code'] . ')' . PHP_EOL;
            echo "Пакет не загружался. Рабочие файлы не изменялись.\n";
        } elseif ($status === 'update_incompatible') {
            echo "[WARN] Доступно более новое подписанное обновление, несовместимое с этой установкой\n";
            echo 'Цель:     ' . $result['target_version'] . ' (' . $result['target_version_code'] . ')' . PHP_EOL;
            echo 'Причина:  ' . ($result['compatibility_message'] ?? 'политика совместимости отклонила обновление') . PHP_EOL;
            echo "Пакет не загружался. Рабочие файлы не изменялись.\n";
        } elseif ($status === 'staged') {
            echo "[OK] Удалённое подписанное обновление проверено, проаудировано и подготовлено\n";
            echo 'Канал:     ' . $result['channel'] . PHP_EOL;
            echo 'Цель:      ' . $result['target_version'] . ' (' . $result['target_version_code'] . ')' . PHP_EOL;
            echo 'Staging:   ' . $result['stage_dir'] . PHP_EOL;
            echo 'SHA-256:   ' . $result['package_sha256'] . PHP_EOL;
            echo "Режим обслуживания не включался, рабочие файлы не изменялись.\n";
        } else {
            throw new RuntimeException('Удалённый updater вернул неизвестное успешное состояние');
        }
    }
} catch (Throwable $e) {
    remoteUpdaterFail($e->getMessage());
}
