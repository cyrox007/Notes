<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Мост updater 1.0.14 → 1.0.15 доступен только из PHP CLI.\n");
    exit(2);
}

$options = getopt('', ['yes', 'root:', 'help']);
if (isset($options['help']) || !isset($options['yes'])) {
    echo "Одноразовый мост updater Workspace Organizer 1.0.14 → 1.0.15\n\n";
    echo "Запускайте этот файл из доверенного распакованного exact-пакета 1.0.15, а не из live-каталога 1.0.14.\n\n";
    echo "Использование:\n";
    echo "  php tools/release/bootstrap-1.0.14-updater.php --yes --root=/path/to/workspace\n";
    echo "После успешного завершения установите 1.0.15 обычной кнопкой в интерфейсе.\n";
    exit(isset($options['help']) ? 0 : 2);
}

function bootstrap1014Fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "[ОШИБКА] {$message}\n");
    exit($code);
}

function bootstrap1014Normalize(string $path): string
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
}

function bootstrap1014Inside(string $path, string $parent): bool
{
    $path = bootstrap1014Normalize($path);
    $parent = bootstrap1014Normalize($parent);
    return $path === $parent || str_starts_with($path . '/', $parent . '/');
}

/** @return array{0:string,1:int} */
function bootstrap1014Version(string $root): array
{
    $path = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Version.php';
    if (!is_file($path) || is_link($path) || !is_readable($path)) {
        bootstrap1014Fail('Не найден core/Version.php: ' . $path, 3);
    }

    $source = file_get_contents($path);
    if (!is_string($source)) {
        bootstrap1014Fail('Не удалось прочитать core/Version.php: ' . $path, 3);
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
        bootstrap1014Fail('Не удалось определить версию по core/Version.php: ' . $path, 3);
    }

    return [$version, $code];
}

function bootstrap1014EnsureParent(string $path, int $mode): void
{
    if (is_dir($path)) {
        return;
    }
    if (file_exists($path) || is_link($path)) {
        throw new RuntimeException('Путь каталога занят небезопасным объектом: ' . $path);
    }
    if (!mkdir($path, $mode, true) && !is_dir($path)) {
        throw new RuntimeException('Не удалось создать каталог: ' . $path);
    }
}

function bootstrap1014CopyVerified(string $source, string $target): void
{
    if (!is_file($source) || is_link($source) || !is_readable($source)) {
        throw new RuntimeException('Источник моста отсутствует или небезопасен: ' . $source);
    }

    bootstrap1014EnsureParent(dirname($target), 0755);

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

    $finalSize = filesize($target);
    $finalSha = hash_file('sha256', $target);
    if ($finalSize !== $expectedSize || !is_string($finalSha) || !hash_equals($expectedSha, $finalSha)) {
        throw new RuntimeException('Итоговая проверка файла не пройдена: ' . $target);
    }
}

function bootstrap1014RemoveTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        $target = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($target) && !is_link($target)) {
            bootstrap1014RemoveTree($target);
            continue;
        }
        @unlink($target);
    }
    @rmdir($path);
}

function bootstrap1014Contains(string $root, string $relative, string $needle): bool
{
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $source = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
    return is_string($source) && str_contains($source, $needle);
}

$sourceRoot = realpath(dirname(__DIR__, 2));
$requestedRoot = trim((string) ($options['root'] ?? ''));
$liveRoot = $requestedRoot !== '' ? realpath($requestedRoot) : false;

if (!is_string($sourceRoot) || !is_dir($sourceRoot)) {
    bootstrap1014Fail('Не удалось определить корень доверенного пакета 1.0.15.', 3);
}
if (!is_string($liveRoot) || !is_dir($liveRoot) || is_link($requestedRoot)) {
    bootstrap1014Fail('Укажите существующий live-каталог exact 1.0.14 через --root.', 3);
}
if (bootstrap1014Inside($sourceRoot, $liveRoot) || bootstrap1014Inside($liveRoot, $sourceRoot)) {
    bootstrap1014Fail('Доверенный пакет 1.0.15 и live-установка должны находиться в разных каталогах.', 3);
}

[$sourceVersion, $sourceCode] = bootstrap1014Version($sourceRoot);
[$liveVersion, $liveCode] = bootstrap1014Version($liveRoot);
if ($sourceVersion !== '1.0.15' || $sourceCode !== 10015) {
    bootstrap1014Fail(
        "Мост должен запускаться из exact-пакета 1.0.15 (10015), получено {$sourceVersion} ({$sourceCode}).",
        4
    );
}
if ($liveVersion !== '1.0.14' || $liveCode !== 10014) {
    bootstrap1014Fail(
        "Мост предназначен только для exact live-версии 1.0.14 (10014), получено {$liveVersion} ({$liveCode}).",
        4
    );
}

// Переносится только исполнитель обновления. Version.php, миграции и прикладной
// код остаются 1.0.14 до штатной транзакции, поэтому мост не считается релизом.
$files = [
    'assets/js/update-web-runner.js',
    'app/services/MaintenanceModeService.php',
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
    'core/UpdateDatabaseRestorer.php',
        'core/UpdateDatabaseRestoreSteps.php',
    'core/UpdateExternalRuntime.php',
    'core/UpdateFileMutator.php',
    'core/UpdateInProcessRunner.php',
    'core/UpdateLiveApplier.php',
    'core/UpdateManifestVerifier.php',
    'core/UpdateMigrationPreflight.php',
    'core/UpdatePackageStager.php',
    'core/UpdatePath.php',
    'core/UpdateStepBudget.php',
    'core/UpdateStepCheckpoint.php',
    'core/UpdatePhpCli.php',
    'core/PrivateStorageResolver.php',
    'core/UpdateProcessRunner.php',
    'core/UpdateReleaseCandidate.php',
    'core/UpdateRemoteDelivery.php',
    'core/UpdateRollbackCodeRestorer.php',
    'core/UpdateTransactionJournal.php',
    'core/UpdateTransactionStateMachine.php',
    'core/UpdateVerifiedStage.php',
    'core/UpdateWebContinuation.php',
    'core/UpdateWebHealthProbe.php',
    'core/UpdateWebHttpBridge.php',
    'core/UpdateWebTransaction.php',
];

$backupRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'notes-updater-bootstrap-1.0.15-' . bin2hex(random_bytes(8));
bootstrap1014EnsureParent($backupRoot, 0700);

/** @var array<string,string> $backedUp */
$backedUp = [];
/** @var list<string> $created */
$created = [];

try {
    foreach ($files as $relative) {
        $native = str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $source = $sourceRoot . DIRECTORY_SEPARATOR . $native;
        $target = $liveRoot . DIRECTORY_SEPARATOR . $native;

        if (!is_file($source) || is_link($source)) {
            throw new RuntimeException('В доверенном пакете отсутствует обязательный файл: ' . $relative);
        }

        if (file_exists($target) || is_link($target)) {
            if (!is_file($target) || is_link($target)) {
                throw new RuntimeException('Live-путь updater небезопасен: ' . $relative);
            }
            $backup = $backupRoot . DIRECTORY_SEPARATOR . $native;
            bootstrap1014EnsureParent(dirname($backup), 0700);
            bootstrap1014CopyVerified($target, $backup);
            $backedUp[$relative] = $backup;
        } else {
            $created[] = $relative;
        }

        bootstrap1014CopyVerified($source, $target);
    }

    [$afterVersion, $afterCode] = bootstrap1014Version($liveRoot);
    if ($afterVersion !== '1.0.14' || $afterCode !== 10014) {
        throw new RuntimeException('Мост изменил версию приложения до штатного обновления.');
    }

    $capabilities = [
        ['core/UpdateBackupManager.php', 'validateDump($databasePath)'],
        ['core/UpdateDatabaseRestorer.php', 'public function validateDump(string $path): void'],
        ['core/UpdateRemoteDelivery.php', 'updater.attempt_started'],
        ['assets/js/update-web-runner.js', 'REQUEST_TIMEOUT_MS = 90000'],
        ['core/UpdateWebTransaction.php', "'phase' => 'backup'"],
    ];
    foreach ($capabilities as [$relative, $needle]) {
        if (!bootstrap1014Contains($liveRoot, $relative, $needle)) {
            throw new RuntimeException('Не подтверждена возможность нового updater: ' . $relative);
        }
    }
} catch (Throwable $error) {
    foreach (array_reverse($created) as $relative) {
        $target = $liveRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($target) && !is_link($target)) {
            @unlink($target);
        }
    }
    foreach (array_reverse($backedUp, true) as $relative => $backup) {
        $target = $liveRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($backup) || is_link($backup)) {
            continue;
        }
        try {
            bootstrap1014CopyVerified($backup, $target);
        } catch (Throwable $restoreError) {
            bootstrap1014Fail(
                'Мост прерван, а восстановление updater-файла не удалось: '
                . $relative . '; ' . $restoreError->getMessage(),
                7
            );
        }
    }
    bootstrap1014RemoveTree($backupRoot);
    bootstrap1014Fail('Мост не применён, исходные updater-файлы восстановлены: ' . $error->getMessage(), 6);
}

bootstrap1014RemoveTree($backupRoot);
fwrite(
    STDOUT,
    "[OK] Исправленный updater 1.0.15 предварительно установлен поверх exact 1.0.14. "
    . "Версия приложения, миграции и база данных не изменялись. Теперь установите 1.0.15 штатной кнопкой.\n"
);
