<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/core/Environment.php';
if (is_file($root . '/.env')) {
    \Core\Environment::load($root . '/.env');
}
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
    echo "Эта команда не включает maintenance и не меняет рабочие файлы приложения.\n";
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
        require_once $root . '/core/RuntimeAutoloader.php';
        \Core\RuntimeAutoloader::register($root);
        require_once $root . '/core/config.php';

        // Для 1.0.2+ штатная CLI-проверка использует тот же автоматический
        // bootstrap по установленной лицензии, что и Admin UI. Готовый
        // credential повторно не ротируется.
        (new LicenseService())->ensureUpdateAccess();
    }

    $verifier = new UpdateManifestVerifier();
    if (!$verifier->hasTrustedKeys()) {
        remoteUpdaterFail(
            'В сборке не настроен доверенный публичный ключ проверки обновлений.',
            'trust_not_configured'
        );
    }

    $delivery = new UpdateRemoteDelivery(
        $root,
        $verifier,
        UpdateHttpsTransport::fromEnvironment(),
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
            echo "[OK] Signed remote update is available\n";
            echo 'Channel:   ' . $result['channel'] . PHP_EOL;
            echo 'Target:    ' . $result['target_version'] . ' (' . $result['target_version_code'] . ')' . PHP_EOL;
            echo 'Package:   ' . $result['package_filename'] . ' (' . $result['package_size'] . " bytes)\n";
            echo 'Key ID:    ' . $result['key_id'] . PHP_EOL;
            echo "Package was not downloaded. No live files were changed.\n";
        } elseif ($status === 'up_to_date') {
            echo "[OK] Installation is up to date with the configured signed feed\n";
            echo 'Installed version code: ' . Version::VERSION_CODE . PHP_EOL;
            echo 'Feed target:            ' . $result['target_version'] . ' (' . $result['target_version_code'] . ')' . PHP_EOL;
            echo "Package was not downloaded. No live files were changed.\n";
        } elseif ($status === 'ahead_of_feed') {
            echo "[OK] Installed version is newer than the configured signed feed\n";
            echo 'Installed version code: ' . Version::VERSION_CODE . PHP_EOL;
            echo 'Feed target:            ' . $result['target_version'] . ' (' . $result['target_version_code'] . ')' . PHP_EOL;
            echo "Package was not downloaded. No live files were changed.\n";
        } elseif ($status === 'update_incompatible') {
            echo "[WARN] A newer signed update exists but is incompatible with this installation\n";
            echo 'Target:  ' . $result['target_version'] . ' (' . $result['target_version_code'] . ')' . PHP_EOL;
            echo 'Reason:  ' . ($result['compatibility_message'] ?? 'compatibility policy rejected the update') . PHP_EOL;
            echo "Package was not downloaded. No live files were changed.\n";
        } elseif ($status === 'staged') {
            echo "[OK] Remote signed update verified, audited and staged\n";
            echo 'Channel:   ' . $result['channel'] . PHP_EOL;
            echo 'Target:    ' . $result['target_version'] . ' (' . $result['target_version_code'] . ')' . PHP_EOL;
            echo 'Stage:     ' . $result['stage_dir'] . PHP_EOL;
            echo 'SHA-256:   ' . $result['package_sha256'] . PHP_EOL;
            echo "No maintenance was entered and no live files were changed.\n";
        } else {
            throw new RuntimeException('Remote updater returned an unknown success state');
        }
    }
} catch (Throwable $e) {
    remoteUpdaterFail($e->getMessage());
}
