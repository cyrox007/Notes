<?php

declare(strict_types=1);

use Core\UpdateExternalRuntime;
use Core\UpdatePath;
use Core\Version;

$root = dirname(__DIR__, 2);
require_once $root . '/core/UpdateExternalRuntime.php';

function externalRuntimeAssert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[ОШИБКА] {$message}\n");
    exit(1);
}

function externalRuntimeRemoveTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        $target = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($target) && !is_link($target)) {
            externalRuntimeRemoveTree($target);
            continue;
        }
        @unlink($target);
    }

    @rmdir($path);
}

/** @return array{code:int,stdout:string,stderr:string} */
function externalRuntimeRunHelp(string $entrypoint): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        [PHP_BINARY, $entrypoint, '--help'],
        $descriptors,
        $pipes,
        dirname($entrypoint),
        null,
        ['bypass_shell' => true]
    );
    externalRuntimeAssert(is_resource($process), 'Не удалось запустить внешний updater entrypoint');

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    return [
        'code' => is_int($code) ? $code : 1,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

$temp = sys_get_temp_dir() . '/wo-external-runtime-' . bin2hex(random_bytes(6));
$private = $temp . '/private';
externalRuntimeAssert(mkdir($private, 0700, true), 'Не удалось создать внешний private storage');

$previousPrivate = getenv('PRIVATE_STORAGE_PATH');
$previousCredential = getenv('UPDATE_CREDENTIALS_FILE');

try {
    putenv('PRIVATE_STORAGE_PATH=' . $private);
    putenv('UPDATE_CREDENTIALS_FILE=');

    $prepared = (new UpdateExternalRuntime($root))->prepare();
    $runtimeRoot = (string) ($prepared['runtime_root'] ?? '');
    $entrypoint = (string) ($prepared['entrypoint'] ?? '');

    externalRuntimeAssert(is_dir($runtimeRoot), 'Внешний updater runtime не создан');
    externalRuntimeAssert(is_file($entrypoint), 'Внешний updater entrypoint отсутствует');
    externalRuntimeAssert(
        !UpdatePath::inside($runtimeRoot, (string) realpath($root))
            && !UpdatePath::inside((string) realpath($root), $runtimeRoot),
        'Внешний updater runtime оказался в live-tree'
    );
    externalRuntimeAssert(
        (int) ($prepared['source_version_code'] ?? 0) === Version::VERSION_CODE,
        'Внешний runtime привязан к другой исходной версии'
    );
    externalRuntimeAssert(
        preg_match('/^[0-9a-f]{64}$/', (string) ($prepared['manifest_sha256'] ?? '')) === 1,
        'Manifest внешнего runtime не имеет SHA-256'
    );
    externalRuntimeAssert((int) ($prepared['files'] ?? 0) >= 20, 'Внешний runtime неполный');

    $again = (new UpdateExternalRuntime($root))->prepare();
    externalRuntimeAssert(
        realpath((string) ($again['runtime_root'] ?? '')) === realpath($runtimeRoot),
        'Повторная подготовка создала другой runtime для тех же исходников'
    );
    externalRuntimeAssert(
        hash_equals(
            (string) ($prepared['manifest_sha256'] ?? ''),
            (string) ($again['manifest_sha256'] ?? '')
        ),
        'Повторная подготовка изменила manifest внешнего runtime'
    );

    $help = externalRuntimeRunHelp($entrypoint);
    externalRuntimeAssert($help['code'] === 0, 'Внешний updater entrypoint не запускается');
    externalRuntimeAssert(
        str_contains($help['stdout'], 'Внешний транзакционный обновлятор'),
        'Внешний updater entrypoint вернул неожиданный help'
    );

    $tamper = $runtimeRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'UpdateDatabaseRestorer.php';
    externalRuntimeAssert(file_put_contents($tamper, "\n// tamper\n", FILE_APPEND) !== false, 'Не удалось подготовить tamper-проверку');

    try {
        (new UpdateExternalRuntime($root))->prepare();
        externalRuntimeAssert(false, 'Повреждённый внешний runtime был ошибочно принят');
    } catch (RuntimeException $e) {
        externalRuntimeAssert(
            str_contains($e->getMessage(), 'Проверка внешнего updater runtime не пройдена'),
            'Повреждённый runtime вернул неожиданную ошибку'
        );
    }

    echo "[OK] Внешний updater runtime: отдельное дерево, SHA-256, запуск и tamper rejection\n";
} finally {
    if ($previousPrivate === false) {
        putenv('PRIVATE_STORAGE_PATH');
    } else {
        putenv('PRIVATE_STORAGE_PATH=' . $previousPrivate);
    }

    if ($previousCredential === false) {
        putenv('UPDATE_CREDENTIALS_FILE');
    } else {
        putenv('UPDATE_CREDENTIALS_FILE=' . $previousCredential);
    }

    externalRuntimeRemoveTree($temp);
}
