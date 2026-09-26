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
        livePermAssert(mkdir($dir, 0755, true), 'не удалось создать каталог фикстуры');
    }
    livePermAssert(file_put_contents($path, $bytes) === strlen($bytes), 'не удалось записать файл фикстуры');
    @chmod($path, 0644);
}

$temp = sys_get_temp_dir() . '/wo-live-permissions-' . bin2hex(random_bytes(5));
livePermAssert(mkdir($temp, 0700, true), 'не удалось создать временный каталог');

try {
    $live = $temp . '/live';
    $candidate = $temp . '/candidate';
    $backup = $temp . '/backup/perm-test-001';
    livePermAssert(mkdir($live, 0755, true), 'не удалось создать live-root');
    livePermAssert(mkdir($candidate . '/core', 0755, true), 'не удалось создать candidate/core');
    livePermAssert(mkdir($backup . '/code/core', 0700, true), 'не удалось создать backup/core');

    $old = "<?php\ndeclare(strict_types=1);\nnamespace Core;\nclass Version { public const VERSION = 'old'; public const VERSION_CODE = 1; }\n";
    $new = "<?php\ndeclare(strict_types=1);\nnamespace Core;\nclass Version { public const VERSION = 'new'; public const VERSION_CODE = 2; }\n";
    livePermWrite($live . '/core/Version.php', $old);
    @chmod($live . '/core', 0755);
    livePermWrite($backup . '/code/core/Version.php', $old);
    // Каталоги backup намеренно приватные (0700), но этот режим нельзя переносить на live-каталоги.
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
        'created_at' => time(),
        'application_root' => str_replace('\\', '/', realpath($live) ?: $live),
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
    livePermAssert(is_file($plan['plan_path'] ?? ''), 'не создан неизменяемый пофайловый updater-план');
    livePermAssert(!isset($plan['new_dir'], $plan['old_dir']), 'пофайловый updater не должен готовить каталоги для directory swap');

    if (PHP_OS_FAMILY !== 'Windows') {
        $planMode = fileperms((string) $plan['plan_path']);
        livePermAssert(is_int($planMode) && ($planMode & 0777) === 0600, 'updater-план должен быть приватным');
    }

    $switch = $applier->switchPrepared($plan);
    livePermAssert(($switch['file_level'] ?? false) === true, 'apply не подтвердил пофайловый режим');
    clearstatcache(true, $live . '/core');
    if (PHP_OS_FAMILY !== 'Windows') {
        $liveMode = fileperms($live . '/core');
        livePermAssert(is_int($liveMode) && ($liveMode & 0777) === 0755, 'live core потерял режим 0755');
    }

    $applier->restoreCode('perm-test-001', $backup, $candidate);
    clearstatcache(true, $live . '/core');
    if (PHP_OS_FAMILY !== 'Windows') {
        $restoredMode = fileperms($live . '/core');
        livePermAssert(is_int($restoredMode) && ($restoredMode & 0777) === 0755, 'rollback перенёс приватный режим backup-каталога в live');
    }
    livePermAssert(file_get_contents($live . '/core/Version.php') === $old, 'rollback изменил содержимое Version.php');

    echo "[OK] Пофайловый apply/rollback сохраняет корректные режимы каталогов\n";
} finally {
    livePermRemove($temp);
}
