<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

$options = getopt('', ['yes', 'root:', 'help']);
if (isset($options['help']) || !isset($options['yes'])) {
    echo "Полный патч updater для Workspace Organizer 1.0.13/14/15\n\n";
    echo "Распакуйте архив патча отдельно от live-каталога.\n\n";
    echo "Использование:\n";
    echo "  php tools/release/repair-updater-full.php --yes --root=/path/to/workspace\n";
    echo "Версия приложения сохраняется. После патча повторите проверку целевого обновления.\n";
    exit(isset($options['help']) ? 0 : 2);
}

function repairUpdaterFail(string $message, int $code = 1): never
{
    fwrite(STDERR, "[ОШИБКА] {$message}\n");
    exit($code);
}

function repairUpdaterNormalize(string $path): string
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
}

function repairUpdaterInside(string $path, string $parent): bool
{
    $path = repairUpdaterNormalize($path);
    $parent = repairUpdaterNormalize($parent);
    return $path === $parent || str_starts_with($path . '/', $parent . '/');
}

/** @return array{0:string,1:int} */
function repairUpdaterVersion(string $root): array
{
    $path = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Version.php';
    if (!is_file($path) || is_link($path) || !is_readable($path)) {
        repairUpdaterFail('Не найден core/Version.php: ' . $path, 3);
    }

    $source = file_get_contents($path);
    if (!is_string($source)) {
        repairUpdaterFail('Не удалось прочитать core/Version.php: ' . $path, 3);
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
        repairUpdaterFail('Не удалось определить версию по core/Version.php: ' . $path, 3);
    }

    return [$version, $code];
}

function repairUpdaterEnsureParent(string $path, int $mode): void
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

function repairUpdaterCopyVerified(string $source, string $target): void
{
    if (!is_file($source) || is_link($source) || !is_readable($source)) {
        throw new RuntimeException('Источник моста отсутствует или небезопасен: ' . $source);
    }

    repairUpdaterEnsureParent(dirname($target), 0755);

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

function repairUpdaterRemoveTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        $target = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($target) && !is_link($target)) {
            repairUpdaterRemoveTree($target);
            continue;
        }
        @unlink($target);
    }
    @rmdir($path);
}

function repairUpdaterContains(string $root, string $relative, string $needle): bool
{
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $source = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
    return is_string($source) && str_contains($source, $needle);
}

$sourceRoot = realpath(dirname(__DIR__, 2));
$requestedRoot = trim((string) ($options['root'] ?? ''));
$liveRoot = $requestedRoot !== '' ? realpath($requestedRoot) : false;

if (!is_string($sourceRoot) || !is_dir($sourceRoot)) {
    repairUpdaterFail('Не удалось определить корень доверенного пакета 1.0.15.', 3);
}
if (!is_string($liveRoot) || !is_dir($liveRoot) || is_link($requestedRoot)) {
    repairUpdaterFail('Укажите существующий live-каталог 1.0.13/14/15 через --root.', 3);
}
if (repairUpdaterInside($sourceRoot, $liveRoot) || repairUpdaterInside($liveRoot, $sourceRoot)) {
    repairUpdaterFail('Доверенный пакет 1.0.15 и live-установка должны находиться в разных каталогах.', 3);
}

[$sourceVersion, $sourceCode] = repairUpdaterVersion($sourceRoot);
[$liveVersion, $liveCode] = repairUpdaterVersion($liveRoot);
if ($sourceVersion !== '1.0.15' || $sourceCode !== 10015) {
    repairUpdaterFail(
        "Мост должен запускаться из exact-пакета 1.0.15 (10015), получено {$sourceVersion} ({$sourceCode}).",
        4
    );
}
$allowed = ['1.0.13' => 10013, '1.0.14' => 10014, '1.0.15' => 10015];
if (($allowed[$liveVersion] ?? null) !== $liveCode) {
    repairUpdaterFail(
        "Патч предназначен для версий 1.0.13, 1.0.14 и 1.0.15, получено {$liveVersion} ({$liveCode}).",
        4
    );
}

if (is_file($liveRoot . '/.env')) {
    require_once $liveRoot . '/core/Environment.php';
    \Core\Environment::load($liveRoot . '/.env');
    require_once $liveRoot . '/app/services/MaintenanceModeService.php';
    if ((new \App\Services\MaintenanceModeService(null, $liveRoot))->state()['active']) {
        repairUpdaterFail('Сначала завершите восстановление активной транзакции; патч не меняет работающий updater.', 4);
    }
}

// Переносится только исполнитель обновления. Version.php, миграции и прикладной
// код сохраняют установленную версию до штатной транзакции.
$files = [
    'bin/migrate.php',
    'bin/update_external_apply.php',
    'core/Environment.php',
    'bin/update_web_entry.php',
    'assets/js/update-web-runner.js',
    'app/services/MaintenanceModeService.php',
    'core/DatabaseOwnership.php',
    'core/HostingCompatibility.php',
    'core/MigrationBaseline.php',
    'core/MigrationManifest.php',
    'core/ModuleManifest.php',
    'core/SecurityEventLog.php',
    'core/ServiceLog.php',
    'core/SchemaReadiness.php',
    'core/WebSocketEndpoint.php',
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
    'core/UpdateReadiness.php',
    'core/UpdateTransactionJournal.php',
    'core/UpdateTransactionStateMachine.php',
    'core/UpdateVerifiedStage.php',
    'core/UpdateWebContinuation.php',
    'core/UpdateWebHealthProbe.php',
    'core/UpdateWebHttpBridge.php',
    'core/UpdateWebRuntimeLauncher.php',
    'core/UpdateWebTransaction.php',
];

$backupRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'notes-updater-bootstrap-1.0.15-' . bin2hex(random_bytes(8));
repairUpdaterEnsureParent($backupRoot, 0700);

/** @var array<string,string> $backedUp */
$backedUp = [];
/** @var list<string> $created */
$created = [];

try {
    // Validate the complete payload before modifying any live file.
    foreach ($files as $relative) {
        if (!is_file($sourceRoot . '/' . $relative) || is_link($sourceRoot . '/' . $relative)) {
            throw new RuntimeException('Неполный архив updater: ' . $relative);
        }
        $ancestor = dirname($liveRoot . '/' . $relative);
        while (!file_exists($ancestor) && !is_link($ancestor)) $ancestor = dirname($ancestor);
        $resolvedAncestor = realpath($ancestor);
        if ($resolvedAncestor === false || !repairUpdaterInside($resolvedAncestor, $liveRoot)) {
            throw new RuntimeException('Каталог updater выходит за пределы установки: ' . $relative);
        }
    }
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
            repairUpdaterEnsureParent(dirname($backup), 0700);
            repairUpdaterCopyVerified($target, $backup);
            $backedUp[$relative] = $backup;
        } else {
            $created[] = $relative;
        }

        repairUpdaterCopyVerified($source, $target);
    }

    // Backport only the synchronous Windows guard, preserving the installed
    // admin service and its version-specific dependencies.
    $relative = 'modules/admin/services/AdminUpdateService.php';
    $target = $liveRoot . '/' . $relative;
    if (is_file($target) && !is_link($target)) {
        $service = (string) file_get_contents($target);
        $marker = '// updater-repair-windows-sync-guard';
        if (!str_contains($service, $marker)) {
            $guard = "\n        // updater-repair-windows-sync-guard\n"
                . "        if (PHP_OS_FAMILY === 'Windows' && PHP_SAPI !== 'cli') {\n"
                . "            throw new \\RuntimeException('Обновите страницу и включите JavaScript для безопасного обновления на Windows.');\n"
                . "        }\n";
            $patched = preg_replace_callback(
                '/(private function applyWebSynchronously\([^{}]*\): array\s*\{)/s',
                static fn (array $match): string => $match[1] . $guard,
                $service, 1, $count
            );
            if (!is_string($patched) || $count !== 1) {
                throw new RuntimeException('Не найден безопасный путь backport Windows: ' . $relative);
            }
            $backup = $backupRoot . '/' . $relative;
            repairUpdaterEnsureParent(dirname($backup), 0700);
            repairUpdaterCopyVerified($target, $backup);
            $backedUp[$relative] = $backup;
            $patchedSource = $backupRoot . '/patched-admin-service.php';
            if (file_put_contents($patchedSource, $patched) !== strlen($patched)) {
                throw new RuntimeException('Не удалось подготовить Windows guard');
            }
            repairUpdaterCopyVerified($patchedSource, $target);
        }
    }

    [$afterVersion, $afterCode] = repairUpdaterVersion($liveRoot);
    if ($afterVersion !== $liveVersion || $afterCode !== $liveCode) {
        throw new RuntimeException('Мост изменил версию приложения до штатного обновления.');
    }

    $capabilities = [
        ['core/UpdatePackageStager.php', '$signedIdentity = hash('],
        ['core/UpdateBackupManager.php', 'VIRTUAL|STORED'],
        ['core/DatabaseOwnership.php', '$packageVersionCode'],
        ['core/UpdateWebRuntimeLauncher.php', '/update-continuations/'],
        ['core/UpdateBackupManager.php', 'validateDump($databasePath)'],
        ['core/UpdateDatabaseRestorer.php', 'public function validateDump(string $path): void'],
        ['core/UpdateRemoteDelivery.php', 'updater.attempt_started'],
        ['assets/js/update-web-runner.js', 'REQUEST_TIMEOUT_MS = 90000'],
        ['core/UpdateWebTransaction.php', "'phase' => 'backup'"],
    ];
    foreach ($capabilities as [$relative, $needle]) {
        if (!repairUpdaterContains($liveRoot, $relative, $needle)) {
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
            repairUpdaterCopyVerified($backup, $target);
        } catch (Throwable $restoreError) {
            repairUpdaterFail(
                'Мост прерван, а восстановление updater-файла не удалось: '
                . $relative . '; ' . $restoreError->getMessage(),
                7
            );
        }
    }
    // Retain original updater files outside the live tree for manual recovery.
    repairUpdaterFail('Мост не применён, исходные updater-файлы восстановлены: ' . $error->getMessage(), 6);
}

// Retain original updater files outside the live tree for manual recovery.
fwrite(
    STDOUT,
    "[OK] Полный исправленный updater установлен; версия приложения сохранена. "
    . "Версия приложения, миграции и база данных не изменялись. Установленная версия: {$liveVersion}. Повторите проверку обновлений.\n"
);

fwrite(STDOUT, "Original updater backup: {$backupRoot}\n");
