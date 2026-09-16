<?php

declare(strict_types=1);

use Core\UpdateLiveApplier;

$root = dirname(__DIR__, 2);
require_once $root . '/core/UpdateLiveApplier.php';

function livePermAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

function livePermRemove(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            livePermRemove($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function livePermWrite(string $path, string $bytes): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        livePermAssert(mkdir($dir, 0755, true), 'cannot create fixture directory');
    }
    livePermAssert(file_put_contents($path, $bytes) === strlen($bytes), 'cannot write fixture file');
    @chmod($path, 0644);
}

$temp = sys_get_temp_dir() . '/wo-live-permissions-' . bin2hex(random_bytes(5));
livePermAssert(mkdir($temp, 0700, true), 'cannot create temp root');

try {
    $live = $temp . '/live';
    $candidate = $temp . '/candidate';
    $backup = $temp . '/backup/perm-test-001';
    livePermAssert(mkdir($live, 0755, true), 'cannot create live root');
    livePermAssert(mkdir($candidate . '/core', 0755, true), 'cannot create candidate core');
    livePermAssert(mkdir($backup . '/code/core', 0700, true), 'cannot create backup core');

    $old = "<?php\ndeclare(strict_types=1);\nnamespace Core;\nclass Version { public const VERSION = 'old'; public const VERSION_CODE = 1; }\n";
    $new = "<?php\ndeclare(strict_types=1);\nnamespace Core;\nclass Version { public const VERSION = 'new'; public const VERSION_CODE = 2; }\n";
    livePermWrite($live . '/core/Version.php', $old);
    @chmod($live . '/core', 0755);
    livePermWrite($backup . '/code/core/Version.php', $old);
    // Backup directories are intentionally private (0700) in UpdateBackupManager.
    @chmod($backup . '/code', 0700);
    @chmod($backup . '/code/core', 0700);
    livePermWrite($candidate . '/core/Version.php', $new);
    @chmod($candidate . '/core', 0755);

    $oldHash = hash('sha256', $old);
    $codeManifest = [
        'schema' => 1,
        'files' => 1,
        'bytes' => strlen($old),
        'excluded_roots' => [],
        'entries' => [[
            'path' => 'core/Version.php',
            'sha256' => $oldHash,
            'size' => strlen($old),
            'mode' => 0644,
        ]],
    ];
    $codeBytes = json_encode($codeManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    livePermWrite($backup . '/code-manifest.json', $codeBytes);
    $backupManifest = [
        'schema' => 1,
        'transaction_id' => 'perm-test-001',
        'code' => [
            'path' => 'code',
            'manifest' => 'code-manifest.json',
            'manifest_sha256' => hash('sha256', $codeBytes),
            'files' => 1,
            'bytes' => strlen($old),
        ],
        'database' => [],
    ];
    livePermWrite(
        $backup . '/backup.json',
        json_encode($backupManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL
    );

    $tree = [
        'schema' => 1,
        'archive_root' => 'workspace-organizer-perm-test',
        'target_version' => 'new',
        'target_version_code' => 2,
        'files' => [
            'core/Version.php' => [
                'sha256' => hash('sha256', $new),
                'size' => strlen($new),
            ],
        ],
        'file_count' => 1,
        'total_bytes' => strlen($new),
    ];
    livePermWrite(
        $candidate . '/.workspace-release-tree.json',
        json_encode($tree, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL
    );

    $applier = new UpdateLiveApplier($live);
    $plan = $applier->prepareCodeSwitch('perm-test-001', $candidate, $backup);
    $preparedMode = fileperms($plan['new_dir'] . '/core');
    livePermAssert(is_int($preparedMode) && ($preparedMode & 0777) === 0755, 'prepared candidate core is not 0755');

    $applier->switchPrepared($plan);
    clearstatcache(true, $live . '/core');
    $liveMode = fileperms($live . '/core');
    livePermAssert(is_int($liveMode) && ($liveMode & 0777) === 0755, 'activated live core is not 0755');

    $applier->restoreCode('perm-test-001', $backup, $candidate);
    clearstatcache(true, $live . '/core');
    $restoredMode = fileperms($live . '/core');
    livePermAssert(is_int($restoredMode) && ($restoredMode & 0777) === 0755, 'restored live core inherited private backup directory mode');
    livePermAssert(file_get_contents($live . '/core/Version.php') === $old, 'rollback content changed in permission contract');

    echo "[OK] updater live directory permissions contract\n";
} finally {
    livePermRemove($temp);
}
