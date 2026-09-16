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
require_once $root . '/core/UpdateManifestVerifier.php';
require_once $root . '/core/UpdatePackageStager.php';
require_once $root . '/core/UpdateArchiveInspector.php';
require_once $root . '/core/UpdateRemoteTransport.php';
require_once $root . '/core/UpdateRemoteDelivery.php';

use Core\UpdateArchiveInspector;
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
    echo "Workspace Organizer remote signed update delivery\n\n";
    echo "Check the configured feed without downloading the package:\n";
    echo "  php bin/update_remote.php --check-only [--feed-url=https://updates.example/feed.json] [--channel=stable] [--json]\n\n";
    echo "Download, verify, audit and stage the signed package:\n";
    echo "  php bin/update_remote.php [--feed-url=https://updates.example/feed.json] [--channel=stable] \\\n";
    echo "      [--stage-root=/absolute/external/path] [--json]\n\n";
    echo "Defaults: UPDATE_FEED_URL, UPDATE_CHANNEL, UPDATE_STAGING_PATH.\n";
    echo "Remote delivery NEVER enters maintenance and NEVER modifies live application files.\n";
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
    $configured = getenv('UPDATE_FEED_URL');
    if (is_string($configured)) {
        $feedUrl = trim($configured);
    }
}
if ($feedUrl === '') {
    remoteUpdaterFail('No remote update feed configured. Set UPDATE_FEED_URL or pass --feed-url.', 'feed_not_configured', 2);
}

$channel = trim((string) ($options['channel'] ?? ''));
if ($channel === '') {
    $configured = getenv('UPDATE_CHANNEL');
    $channel = is_string($configured) && trim($configured) !== '' ? trim($configured) : 'stable';
}
if (!in_array($channel, ['alpha', 'beta', 'stable'], true)) {
    remoteUpdaterFail('UPDATE_CHANNEL must be alpha, beta or stable', 'invalid_channel', 2);
}

try {
    $verifier = new UpdateManifestVerifier();
    if (!$verifier->hasTrustedKeys()) {
        remoteUpdaterFail(
            'No trusted update public keys are configured. Remote signed updates remain disabled until the production update-key ceremony is completed.',
            'trust_not_configured'
        );
    }

    $delivery = new UpdateRemoteDelivery(
        $root,
        $verifier,
        new UpdateHttpsTransport(),
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
                'No external staging root configured. Set UPDATE_STAGING_PATH, PRIVATE_STORAGE_PATH, or pass --stage-root.',
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
        if (($result['status'] ?? '') === 'update_available') {
            echo "[OK] Signed remote update is available\n";
            echo 'Channel:   ' . $result['channel'] . PHP_EOL;
            echo 'Target:    ' . $result['target_version'] . ' (' . $result['target_version_code'] . ')' . PHP_EOL;
            echo 'Package:   ' . $result['package_filename'] . ' (' . $result['package_size'] . " bytes)\n";
            echo 'Key ID:    ' . $result['key_id'] . PHP_EOL;
            echo "Package was not downloaded. No live files were changed.\n";
        } else {
            echo "[OK] Remote signed update verified, audited and staged\n";
            echo 'Channel:   ' . $result['channel'] . PHP_EOL;
            echo 'Target:    ' . $result['target_version'] . ' (' . $result['target_version_code'] . ')' . PHP_EOL;
            echo 'Stage:     ' . $result['stage_dir'] . PHP_EOL;
            echo 'SHA-256:   ' . $result['package_sha256'] . PHP_EOL;
            echo "No maintenance was entered and no live files were changed.\n";
        }
    }
} catch (Throwable $e) {
    remoteUpdaterFail($e->getMessage());
}
