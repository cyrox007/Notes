<?php

declare(strict_types=1);

// Проверяем файловый вариант обновлятора и в Windows, и в Linux.
// На Linux вызываем его непосредственно через отражение; в Windows
// это штатный путь исполнения UpdateProcessRunner::run().
$root = dirname(__DIR__, 2);
require_once $root . '/core/UpdateProcessRunner.php';

use Core\UpdateProcessRunner;

function assertDiskBudget(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, 'Ограничение диска обновлятора: ' . $message . PHP_EOL);
    exit(1);
}

$runner = new UpdateProcessRunner();
$method = new ReflectionMethod(UpdateProcessRunner::class, 'runWithTemporaryFiles');
$invoke = static function (array $command, int $timeoutSeconds) use ($method, $runner, $root): array {
    return $method->invoke($runner, $command, $root, $timeoutSeconds);
};

$tmp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR;
$findArtifacts = static function () use ($tmp): array {
    $files = array_merge(
        glob($tmp . 'notes-up-out-*') ?: [],
        glob($tmp . 'notes-up-err-*') ?: [],
    );
    sort($files);
    return $files;
};

$initialArtifacts = $findArtifacts();
$result = $invoke([
    PHP_BINARY,
    '-r',
    'for ($i=0; $i<12; $i++) { fwrite(STDOUT, str_repeat("O", 524288)); '
    . 'fwrite(STDERR, str_repeat("E", 524288)); } exit(23);',
], 20);
assertDiskBudget($result['code'] === 23, 'вывод до квоты не должен прерывать процесс');
assertDiskBudget(strlen($result['stdout']) === 1048576, 'stdout ограничен 1 МиБ');
assertDiskBudget(strlen($result['stderr']) === 1048576, 'stderr ограничен 1 МиБ');
assertDiskBudget($findArtifacts() === $initialArtifacts, 'после успешной команды остались временные файлы');

// Генерация заведомо больше 8 МиБ через два канала и с задержкой,
// чтобы проверить контроль ещё при работающем дочернем процессе.
$started = microtime(true);
$overBudget = $invoke([
    PHP_BINARY,
    '-r',
    'for ($i=0; $i<48; $i++) { fwrite(STDOUT, str_repeat("A", 524288)); '
    . 'fwrite(STDERR, str_repeat("B", 524288)); usleep(25000); } exit(0);',
], 20);
$elapsed = microtime(true) - $started;
assertDiskBudget($overBudget['code'] === 125, 'превышение квоты обязано возвращать код 125');
assertDiskBudget(str_contains($overBudget['stderr'], 'бюджет временного вывода'),
    'оператор не получил понятную причину остановки');
assertDiskBudget($elapsed < 10, 'процесс не остановился своевременно после превышения лимита');
assertDiskBudget(strlen($overBudget['stdout']) <= 1048576
    && strlen($overBudget['stderr']) <= 1048576, 'при превышении квоты нарушен лимит памяти');
assertDiskBudget($findArtifacts() === $initialArtifacts,
    'после превышения квоты остались временные файлы');

$timedOut = $invoke([PHP_BINARY, '-r', 'sleep(4);'], 1);
assertDiskBudget($timedOut['code'] === 124, 'сохранить отдельный код тайм-аута 124');
assertDiskBudget($findArtifacts() === $initialArtifacts,
    'после тайм-аута остались временные файлы');

$orphanPath = $tmp . 'notes-up-out-' . bin2hex(random_bytes(8));
file_put_contents($orphanPath, 'устаревшая проверочная запись');
touch($orphanPath, time() - 90000);
try {
    $restarted = $invoke([PHP_BINARY, '-r', 'echo "again";'], 5);
    assertDiskBudget($restarted['code'] === 0 && $restarted['stdout'] === 'again',
        'после отказа обычные обновления должны запускаться');
    assertDiskBudget(!file_exists($orphanPath), 'старый временный файл не очищен при новом запуске');
    assertDiskBudget($findArtifacts() === $initialArtifacts,
        'повторный запуск оставил файлы');
} finally {
    @unlink($orphanPath);
}

// Изолированный каталог можно разместить на отдельном томе с квотой ОС.
$separate = $tmp . 'notes-up-isolated-' . bin2hex(random_bytes(6));
assertDiskBudget(mkdir($separate, 0700), 'не удалось создать отдельный каталог');
try {
    putenv('NOTES_UPDATER_TEMP_DIRECTORY=' . $separate);
    $isolated = $invoke([PHP_BINARY, '-r', 'fwrite(STDOUT, "ok"); fwrite(STDERR, "details");'], 10);
    assertDiskBudget($isolated['code'] === 0 && $isolated['stdout'] === 'ok',
        'запуск в отдельном каталоге не работает');
    assertDiskBudget((glob($separate . DIRECTORY_SEPARATOR . 'notes-up-*') ?: []) === [],
        'в выделенном каталоге остались временные файлы');
    assertDiskBudget($findArtifacts() === $initialArtifacts,
        'отдельный временный каталог затронул системный каталог');

    putenv('NOTES_UPDATER_TEMP_DIRECTORY=' . $separate . DIRECTORY_SEPARATOR . 'missing');
    $denied = false;
    try {
        $invoke([PHP_BINARY, '-r', 'echo "must not run";'], 5);
    } catch (RuntimeException $error) {
        $denied = str_contains($error->getMessage(), 'Временный каталог');
    }
    assertDiskBudget($denied, 'при недоступном каталоге нет безопасного отказа');
} finally {
    putenv('NOTES_UPDATER_TEMP_DIRECTORY');
    @rmdir($separate);
}

echo 'Временный вывод обновлятора: квота, timeout, очистка и повтор проверены' . PHP_EOL;
