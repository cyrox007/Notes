<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$bridge = $root . '/tools/release/bootstrap-1.0.14-updater.php';

function bootstrapContractAssert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[ОШИБКА] {$message}\n");
    exit(1);
}

function bootstrapContractRemoveTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        $target = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($target) && !is_link($target)) {
            bootstrapContractRemoveTree($target);
            continue;
        }
        @unlink($target);
    }
    @rmdir($path);
}

function bootstrapContractWriteVersion(string $root, string $version, int $code): void
{
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Version.php');
    $source = preg_replace("/public const VERSION = '[^']+';/", "public const VERSION = '{$version}';", $source, 1);
    $source = preg_replace('/public const VERSION_CODE = [0-9]+;/', 'public const VERSION_CODE = ' . $code . ';', (string) $source, 1);
    bootstrapContractAssert(is_string($source), 'Не удалось подготовить тестовый Version.php');
    $dir = $root . '/core';
    bootstrapContractAssert(is_dir($dir) || mkdir($dir, 0700, true), 'Не удалось создать core');
    bootstrapContractAssert(file_put_contents($dir . '/Version.php', $source) !== false, 'Не удалось записать Version.php');
}

/** @return array{0:int,1:string,2:string} */
function bootstrapContractRun(string $bridge, string $liveRoot): array
{
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($bridge)
        . ' --yes --root=' . escapeshellarg($liveRoot);
    $pipes = [];
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    bootstrapContractAssert(is_resource($process), 'Не удалось запустить мост updater');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    return [(int) $status, (string) $stdout, (string) $stderr];
}

bootstrapContractAssert(is_file($bridge), 'Отсутствует мост 1.0.14 → 1.0.15');
$bridgeSource = (string) file_get_contents($bridge);
bootstrapContractAssert(str_contains($bridgeSource, "\$sourceVersion !== '1.0.15'"), 'Нет exact-проверки источника 1.0.15');
bootstrapContractAssert(str_contains($bridgeSource, "\$liveVersion !== '1.0.14'"), 'Нет exact-проверки live 1.0.14');
bootstrapContractAssert(!str_contains($bridgeSource, "'core/Version.php',"), 'Мост не должен копировать Version.php');
bootstrapContractAssert(!str_contains($bridgeSource, "'database/migrations/"), 'Мост не должен копировать миграции');

$temp = sys_get_temp_dir() . '/notes-bootstrap-1014-' . bin2hex(random_bytes(6));
bootstrapContractAssert(mkdir($temp, 0700, true), 'Не удалось создать временный каталог');

try {
    $success = $temp . '/success';
    bootstrapContractAssert(mkdir($success, 0700, true), 'Не удалось создать успешный стенд');
    bootstrapContractWriteVersion($success, '1.0.14', 10014);
    [$code, $stdout, $stderr] = bootstrapContractRun($bridge, $success);
    bootstrapContractAssert($code === 0, 'Мост не применился к exact 1.0.14: ' . $stderr);
    bootstrapContractAssert(str_contains($stdout, '[OK]'), 'Мост не сообщил об успешной установке исполнителя');
    bootstrapContractAssert(
        hash_file('sha256', $success . '/core/UpdateDatabaseRestorer.php')
            === hash_file('sha256', $root . '/core/UpdateDatabaseRestorer.php'),
        'Потоковый восстановитель не перенесён без изменений'
    );
    bootstrapContractAssert(
        hash_file('sha256', $success . '/assets/js/update-web-runner.js')
            === hash_file('sha256', $root . '/assets/js/update-web-runner.js'),
        'Браузерный исполнитель не перенесён без изменений'
    );
    $versionAfter = (string) file_get_contents($success . '/core/Version.php');
    bootstrapContractAssert(str_contains($versionAfter, "VERSION = '1.0.14'"), 'Мост изменил версию приложения');
    bootstrapContractAssert(str_contains($versionAfter, 'VERSION_CODE = 10014'), 'Мост изменил код версии приложения');

    $rollback = $temp . '/rollback';
    bootstrapContractAssert(mkdir($rollback, 0700, true), 'Не удалось создать аварийный стенд');
    bootstrapContractWriteVersion($rollback, '1.0.14', 10014);
    bootstrapContractAssert(mkdir($rollback . '/assets/js', 0700, true), 'Не удалось создать assets/js');
    $oldRunner = "/* старый updater 1.0.14 */\n";
    bootstrapContractAssert(
        file_put_contents($rollback . '/assets/js/update-web-runner.js', $oldRunner) !== false,
        'Не удалось создать старый runner'
    );
    bootstrapContractAssert(
        mkdir($rollback . '/core/UpdateApplyCommand.php', 0700, true),
        'Не удалось создать отказной live-путь'
    );
    [$rollbackCode, , $rollbackError] = bootstrapContractRun($bridge, $rollback);
    bootstrapContractAssert($rollbackCode !== 0, 'Небезопасный live-путь ошибочно принят');
    bootstrapContractAssert(str_contains($rollbackError, 'исходные updater-файлы восстановлены'), 'Нет подтверждения отката моста');
    bootstrapContractAssert(
        file_get_contents($rollback . '/assets/js/update-web-runner.js') === $oldRunner,
        'Первый заменённый файл не восстановлен после отказа следующего файла'
    );

    $wrongVersion = $temp . '/wrong-version';
    bootstrapContractAssert(mkdir($wrongVersion, 0700, true), 'Не удалось создать стенд другой версии');
    bootstrapContractWriteVersion($wrongVersion, '1.0.13', 10013);
    [$wrongCode, , $wrongError] = bootstrapContractRun($bridge, $wrongVersion);
    bootstrapContractAssert($wrongCode !== 0, 'Мост ошибочно применился не к 1.0.14');
    bootstrapContractAssert(str_contains($wrongError, 'только для exact live-версии 1.0.14'), 'Нет точного отказа для чужой версии');
    bootstrapContractAssert(!file_exists($wrongVersion . '/assets/js/update-web-runner.js'), 'При отказе по версии началась запись файлов');

    echo "[OK] Мост 1.0.14 → 1.0.15: exact-границы, сохранение версии и откат файлов подтверждены\n";
} finally {
    bootstrapContractRemoveTree($temp);
}
