<?php

declare(strict_types=1);

use Core\UpdateReadiness;

$root = dirname(__DIR__, 2);
require_once $root . '/core/UpdateManifestVerifier.php';
require_once $root . '/core/UpdateDownloadCredentials.php';
require_once $root . '/core/UpdateReadiness.php';

function readinessAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$temp = sys_get_temp_dir() . '/wo-update-readiness-' . bin2hex(random_bytes(6));
readinessAssert(mkdir($temp, 0700, true), 'cannot create readiness temp root');

$names = [
    'UPDATE_FEED_URL',
    'UPDATE_CHANNEL',
    'UPDATE_ACCESS_MODE',
    'UPDATE_CREDENTIALS_FILE',
    'PRIVATE_STORAGE_PATH',
    'UPDATE_STAGING_PATH',
    'UPDATE_STATE_PATH',
    'UPDATE_BACKUP_PATH',
    'UPDATE_RELEASE_PATH',
    'DBUSER',
    'DBNAME',
];
$previous = [];
foreach ($names as $name) {
    $previous[$name] = getenv($name);
}

try {
    putenv('UPDATE_FEED_URL=https://updates.example.test/stable/feed.json');
    putenv('UPDATE_CHANNEL=stable');
    putenv('UPDATE_ACCESS_MODE=offline');
    putenv('UPDATE_CREDENTIALS_FILE');
    putenv('PRIVATE_STORAGE_PATH=' . $temp . '/private');
    putenv('UPDATE_STAGING_PATH=' . $temp . '/stage');
    putenv('UPDATE_STATE_PATH=' . $temp . '/state');
    putenv('UPDATE_BACKUP_PATH=' . $temp . '/backups');
    putenv('UPDATE_RELEASE_PATH=' . $temp . '/releases');
    putenv('DBUSER=readiness');
    putenv('DBNAME=readiness');

    $snapshot = (new UpdateReadiness($root))->inspect();
    readinessAssert(($snapshot['ready_for_check'] ?? false) === true, 'valid signed-feed configuration is not check-ready');
    readinessAssert(($snapshot['ready_for_apply'] ?? false) === true, 'valid external updater roots are not apply-ready');
    readinessAssert(($snapshot['checks']['php_cli']['ok'] ?? false) === true, 'PHP CLI не найден для web-установки');
    readinessAssert(($snapshot['issues'] ?? []) === [], 'ready snapshot unexpectedly reports issues');

    putenv('UPDATE_FEED_URL=http://updates.example.test/stable/feed.json');
    $badFeed = (new UpdateReadiness($root))->inspect();
    readinessAssert(($badFeed['ready_for_check'] ?? true) === false, 'plain HTTP feed was accepted');

    putenv('UPDATE_FEED_URL=https://updates.example.test/stable/feed.json');
    putenv('UPDATE_STAGING_PATH=' . $root . '/cache/update-stage');
    $inside = (new UpdateReadiness($root))->inspect();
    readinessAssert(($inside['ready_for_apply'] ?? true) === false, 'updater staging inside application tree was accepted');
    readinessAssert(
        ($inside['checks']['path_staging']['ok'] ?? true) === false,
        'inside-tree staging path was not identified'
    );

    echo "[OK] updater readiness contract\n";
} finally {
    foreach ($previous as $name => $value) {
        if ($value === false) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }
    @rmdir($temp);
}
