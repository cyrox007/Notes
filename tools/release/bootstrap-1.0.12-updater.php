<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Мост updater 1.0.12 доступен только из PHP CLI.\n");
    exit(2);
}

$options = getopt('', ['yes', 'root:', 'help']);
if (isset($options['help']) || !isset($options['yes'])) {
    echo "Одноразовый мост updater Workspace Organizer 1.0.12 → 1.0.14\n\n";
    echo "Запускайте этот файл из доверенного распакованного пакета 1.0.14, а не из live-каталога 1.0.12.\n\n";
    echo "Использование:\n";
    echo "  php tools/release/bootstrap-1.0.12-updater.php --yes --root=/path/to/workspace\n";
    echo "После успешного завершения откройте Workspace Organizer и установите 1.0.14 обычной кнопкой.\n";
    exit(isset($options['help']) ? 0 : 2);
}

function bootstrapFail(string $message, int $code = 1): never
{
    fwrite(STDERR, "[ОШИБКА] {$message}\n");
    exit($code);
}

function bootstrapNormalize(string $path): string
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
}

function bootstrapInside(string $path, string $parent): bool
{
    $path = bootstrapNormalize($path);
    $parent = bootstrapNormalize($parent);
    return $path === $parent || str_starts_with($path . '/', $parent . '/');
}

function bootstrapVersion(string $root): array
{
    $path = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Version.php';
    if (!is_file($path) || is_link($path) || !is_readable($path)) {
        bootstrapFail('Не найден core/Version.php: ' . $path, 3);
    }

    $source = file_get_contents($path);
    if (!is_string($source)) {
        bootstrapFail('Не удалось прочитать core/Version.php: ' . $path, 3);
    }

    $version = null;
    $code = null;
    if (preg_match("/public const VERSION = '([^']+)';/", $source, $match) === 1) {
        $version = (string) $match[1];
    }
    if (preg_match('/public const VERSION_CODE = ([0-9]+);/', $source, $match) === 1) {
        $code = (int) $match[1];
    }

    if ($version === null || $code === null) {
        bootstrapFail('Не удалось определить версию по core/Version.php: ' . $path, 3);
    }

    return [$version, $code];
}

function bootstrapCopyVerified(string $source, string $target): void
{
    if (!is_file($source) || is_link($source) || !is_readable($source)) {
        throw new RuntimeException('Источник моста отсутствует или небезопасен: ' . $source);
    }

    $parent = dirname($target);
    if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
        throw new RuntimeException('Не удалось создать каталог: ' . $parent);
    }

    $expectedSize = filesize($source);
    $expectedSha = hash_file('sha256', $source);
    if (!is_int($expectedSize) || !is_string($expectedSha)) {
        throw new RuntimeException('Не удалось проверить источник: ' . $source);
    }

    $temp = $target . '.bootstrap-' . bin2hex(random_bytes(6)) . '.tmp';
    $input = fopen($source, 'rb');
    $output = fopen($temp, 'xb');
    if ($input === false || $output === false) {
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_resource($output)) {
            fclose($output);
        }
        @unlink($temp);
        throw new RuntimeException('Не удалось подготовить временную копию: ' . $target);
    }

    try {
        $copied = stream_copy_to_stream($input, $output);
        if ($copied !== $expectedSize || !fflush($output)) {
            throw new RuntimeException('Файл скопирован не полностью: ' . $target);
        }
    } finally {
        fclose($input);
        fclose($output);
    }

    $actualSha = hash_file('sha256', $temp);
    if (!is_string($actualSha) || !hash_equals($expectedSha, $actualSha)) {
        @unlink($temp);
        throw new RuntimeException('SHA-256 временной копии не совпадает: ' . $target);
    }

    $mode = file_exists($target) ? fileperms($target) : fileperms($source);
    if (is_int($mode)) {
        @chmod($temp, $mode & 0777);
    }

    if (PHP_OS_FAMILY === 'Windows' && file_exists($target) && !@unlink($target)) {
        @unlink($temp);
        throw new RuntimeException('Не удалось заменить файл на Windows: ' . $target);
    }
    if (!@rename($temp, $target)) {
        @unlink($temp);
        throw new RuntimeException('Не удалось атомарно заменить файл: ' . $target);
    }
}

$sourceRoot = realpath(dirname(__DIR__, 2));
$requestedRoot = trim((string) ($options['root'] ?? ''));
$liveRoot = $requestedRoot !== '' ? realpath($requestedRoot) : false;

if (!is_string($sourceRoot) || !is_dir($sourceRoot)) {
    bootstrapFail('Не удалось определить корень доверенного пакета 1.0.14.', 3);
}
if (!is_string($liveRoot) || !is_dir($liveRoot) || is_link($requestedRoot)) {
    bootstrapFail('Укажите существующий live-каталог 1.0.12 через --root.', 3);
}
if (bootstrapInside($sourceRoot, $liveRoot) || bootstrapInside($liveRoot, $sourceRoot)) {
    bootstrapFail('Доверенный пакет 1.0.14 и live-установка 1.0.12 должны находиться в разных каталогах.', 3);
}

[$sourceVersion, $sourceCode] = bootstrapVersion($sourceRoot);
[$liveVersion, $liveCode] = bootstrapVersion($liveRoot);
if ($sourceVersion !== '1.0.14' || $sourceCode !== 10014) {
    bootstrapFail(
        "Мост должен запускаться из exact-пакета 1.0.14 (10014), получено {$sourceVersion} ({$sourceCode}).",
        4
    );
}
if ($liveVersion !== '1.0.12' || $liveCode !== 10012) {
    bootstrapFail(
        "Мост предназначен только для exact live-версии 1.0.12 (10012), получено {$liveVersion} ({$liveCode}).",
        4
    );
}

$files = [
    'app/services/MaintenanceModeService.php',
    'bin/migrate.php',
    'core/DatabaseOwnership.php',
    'core/HostingCompatibility.php',
    'core/MigrationBaseline.php',
    'core/MigrationManifest.php',
    'core/ModuleManifest.php',
    'core/SecurityEventLog.php',
    'core/ServiceLog.php',
    'core/UpdateApplyCommand.php',
    'core/UpdateApplyOperationLock.php',
    'core/UpdateArchiveInspector.php',
    'core/UpdateAutomaticRecovery.php',
    'core/UpdateBackupManager.php',
    'core/UpdateBootRecoveryGate.php',
    'core/UpdateCandidateVerifier.php',
    'core/UpdateCodeSwitcher.php',
    'core/UpdateCommandRunner.php',
    'core/UpdateCoordinatorLock.php',
    'core/UpdateDatabaseMigrator.php',
    'core/UpdateExternalRuntime.php',
    'core/UpdateDatabaseRestorer.php',
    'core/UpdateFileMutator.php',
    'core/UpdateInProcessRunner.php',
    'core/UpdateLiveApplier.php',
    'core/UpdateManifestVerifier.php',
    'core/UpdateMigrationPreflight.php',
    'core/UpdatePackageStager.php',
    'core/UpdatePath.php',
    'core/UpdatePhpCli.php',
    'core/PrivateStorageResolver.php',
    'core/UpdateProcessRunner.php',
    'core/UpdateReleaseCandidate.php',
    'core/UpdateRollbackCodeRestorer.php',
    'core/UpdateTransactionJournal.php',
    'core/UpdateTransactionStateMachine.php',
    'core/UpdateWebContinuation.php',
    'core/UpdateWebHealthProbe.php',
    'core/UpdateVerifiedStage.php',
    'core/UpdateWebHttpBridge.php',
    'core/UpdateWebTransaction.php',
    'database/migrations/20260930_module_entitlements.sql',
    'database/migrations/manifest.json',
];

$backupRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'notes-updater-bootstrap-1.0.12-' . bin2hex(random_bytes(8));
if (!mkdir($backupRoot, 0700, true) && !is_dir($backupRoot)) {
    bootstrapFail('Не удалось создать временную резервную копию файлов updater.', 5);
}

$backedUp = [];
$created = [];

try {
    foreach ($files as $relative) {
        $source = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $target = $liveRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($source) || is_link($source)) {
            throw new RuntimeException('В доверенном пакете отсутствует обязательный файл: ' . $relative);
        }

        if (file_exists($target)) {
            if (!is_file($target) || is_link($target)) {
                throw new RuntimeException('Live-путь updater небезопасен: ' . $relative);
            }
            $backup = $backupRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $backupParent = dirname($backup);
            if (!is_dir($backupParent) && !mkdir($backupParent, 0700, true) && !is_dir($backupParent)) {
                throw new RuntimeException('Не удалось создать каталог резервной копии: ' . $relative);
            }
            if (!copy($target, $backup)) {
                throw new RuntimeException('Не удалось сохранить резервную копию: ' . $relative);
            }
            $backedUp[$relative] = $backup;
        } else {
            $created[] = $relative;
        }

        bootstrapCopyVerified($source, $target);
    }

    [$afterVersion, $afterCode] = bootstrapVersion($liveRoot);
    if ($afterVersion !== '1.0.12' || $afterCode !== 10012) {
        throw new RuntimeException('Мост не должен менять версию приложения до штатного обновления.');
    }

    $migrationBaseline = $liveRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'MigrationBaseline.php';
    $continuation = $liveRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'UpdateWebContinuation.php';
    $apply = $liveRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'UpdateApplyCommand.php';

    $baselineSource = file_get_contents($migrationBaseline);
    $continuationSource = file_get_contents($continuation);
    $applySource = file_get_contents($apply);
    if (!is_string($baselineSource)
        || !str_contains($baselineSource, "10012 => '20260930_file_upload_limit.sql'")
        || !is_string($continuationSource)
        || str_contains($continuationSource, "if (\$replace && file_exists(\$path) && !@unlink(\$path))")
        || !is_string($applySource)
        || !str_contains($applySource, '--baseline-version-code=')) {
        throw new RuntimeException('Проверка применённого updater-моста не пройдена.');
    }
} catch (Throwable $e) {
    foreach (array_reverse($created) as $relative) {
        $target = $liveRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($target) && !is_link($target)) {
            @unlink($target);
        }
    }
    foreach (array_reverse($backedUp, true) as $relative => $backup) {
        $target = $liveRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($backup) && !is_link($backup)) {
            @copy($backup, $target);
        }
    }

    bootstrapFail('Мост не применён, исходные файлы восстановлены: ' . $e->getMessage(), 6);
}

fwrite(
    STDOUT,
    "[OK] Updater exact-версии 1.0.12 подготовлен к безопасному web-only обновлению на 1.0.14. "
    . "Версия приложения и база данных не изменялись. Теперь установите 1.0.14 обычной кнопкой в интерфейсе.\n"
);
