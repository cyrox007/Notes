<?php

declare(strict_types=1);

use Core\UpdateRollbackCodeRestorer;

$root = dirname(__DIR__, 2);
require_once $root . '/core/UpdateRollbackCodeRestorer.php';

function fileRollbackAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

function fileRollbackWrite(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать каталог тестового файла');
    }
    if (file_put_contents($path, $content) !== strlen($content)) {
        throw new RuntimeException('Не удалось записать тестовый файл');
    }
}

function fileRollbackRemove(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        fileRollbackRemove($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'notes-file-rollback-' . bin2hex(random_bytes(6));
$live = $temp . DIRECTORY_SEPARATOR . 'live';
$backup = $temp . DIRECTORY_SEPARATOR . 'backup';
$transactionId = 'rollback-file-001';

try {
    fileRollbackAssert(mkdir($live, 0755, true), 'Не удалось создать live-tree');
    fileRollbackAssert(mkdir($backup . DIRECTORY_SEPARATOR . 'code', 0700, true), 'Не удалось создать backup');

    $sourceFiles = [
        'bin/update_apply.php' => "<?php echo 'old updater';\n",
        'core/Old.php' => "<?php echo 'old core';\n",
        'legacy.php' => "<?php echo 'legacy';\n",
    ];

    $entries = [];
    $bytes = 0;
    foreach ($sourceFiles as $relative => $content) {
        $path = $backup . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        fileRollbackWrite($path, $content);
        @chmod($path, 0644);
        $size = strlen($content);
        $entries[] = [
            'path' => $relative,
            'sha256' => hash('sha256', $content),
            'size' => $size,
            'mode' => 0644,
        ];
        $bytes += $size;
    }

    $codeManifest = [
        'schema' => 1,
        'files' => count($entries),
        'bytes' => $bytes,
        'excluded_roots' => ['.git', 'vendor', 'cache', 'compile', 'uploads', 'notes-private-storage', '.logs'],
        'entries' => $entries,
    ];
    $codeBytes = json_encode(
        $codeManifest,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    fileRollbackWrite($backup . DIRECTORY_SEPARATOR . 'code-manifest.json', $codeBytes);

    $backupManifest = [
        'schema' => 1,
        'transaction_id' => $transactionId,
        'created_at' => time(),
        'application_root' => realpath($live),
        'code' => [
            'path' => 'code',
            'manifest' => 'code-manifest.json',
            'manifest_sha256' => hash('sha256', $codeBytes),
            'files' => count($entries),
            'bytes' => $bytes,
        ],
        'database' => [],
    ];
    fileRollbackWrite(
        $backup . DIRECTORY_SEPARATOR . 'backup.json',
        json_encode(
            $backupManifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL
    );

    fileRollbackWrite($live . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'update_apply.php', "<?php echo 'broken updater';\n");
    fileRollbackWrite($live . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'New.php', "<?php echo 'new core';\n");
    fileRollbackWrite($live . DIRECTORY_SEPARATOR . 'new.php', "<?php echo 'new release';\n");

    fileRollbackWrite($live . DIRECTORY_SEPARATOR . '.env', "SECRET=survives\n");
    fileRollbackWrite($live . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'user.bin', "user-data\n");
    fileRollbackWrite($live . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'vendor-update' . DIRECTORY_SEPARATOR . 'private.key', "must-survive\n");

    $result = (new UpdateRollbackCodeRestorer($live))->restore($transactionId, $backup);

    fileRollbackAssert(($result['file_level'] ?? false) === true, 'Rollback не подтвердил пофайловый режим');
    fileRollbackAssert(($result['candidate_required'] ?? true) === false, 'Rollback не должен зависеть от candidate');
    fileRollbackAssert(($result['restored_files'] ?? 0) === 3, 'Rollback восстановил неверное число файлов');

    foreach ($sourceFiles as $relative => $content) {
        $path = $live . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        fileRollbackAssert(is_file($path) && file_get_contents($path) === $content, 'Не восстановлен файл: ' . $relative);
    }

    fileRollbackAssert(!file_exists($live . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'New.php'), 'Не удалён target-only core/New.php');
    fileRollbackAssert(!file_exists($live . DIRECTORY_SEPARATOR . 'new.php'), 'Не удалён target-only new.php');
    fileRollbackAssert(file_get_contents($live . DIRECTORY_SEPARATOR . '.env') === "SECRET=survives\n", '.env изменён rollback');
    fileRollbackAssert(file_get_contents($live . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'user.bin') === "user-data\n", 'Пользовательский файл изменён rollback');
    fileRollbackAssert(file_get_contents($live . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'vendor-update' . DIRECTORY_SEPARATOR . 'private.key') === "must-survive\n", 'Приватный vendor-update файл изменён rollback');

    $restorerSource = file_get_contents($root . '/core/UpdateRollbackCodeRestorer.php');
    fileRollbackAssert(is_string($restorerSource) && str_contains($restorerSource, 'UpdateFileMutator'), 'Rollback не использует пофайловый mutator');
    fileRollbackAssert(!str_contains($restorerSource, 'failed-release'), 'Rollback всё ещё содержит quarantine целого release-каталога');
    fileRollbackAssert(!str_contains($restorerSource, '@rename($live'), 'Rollback всё ещё переименовывает live entry верхнего уровня');

    echo "[OK] Пофайловый rollback: восстановление, удаление target-only файлов и сохранение mutable-путей\n";
} finally {
    fileRollbackRemove($temp);
}
