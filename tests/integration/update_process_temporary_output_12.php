<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/UpdateProcessRunner.php';

use Core\UpdateProcessRunner;

function budgetCheck(bool $ok, string $description): void
{
    if (!$ok) {
        throw new RuntimeException('Бюджет вывода обновлятора: ' . $description);
    }
}

/** @return array<string,true> */
function budgetFiles(): array
{
    $found = [];
    $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR;
    foreach (['notes-up-out-*', 'notes-up-err-*'] as $mask) {
        foreach (glob($dir . $mask) ?: [] as $name) {
            $found[$name] = true;
        }
    }
    return $found;
}

budgetCheck(UpdateProcessRunner::available(), 'proc_open недоступен');
$runner = new UpdateProcessRunner();
$method = (new ReflectionClass($runner))->getMethod('runWithTemporaryFiles');
$before = budgetFiles();
$cwd = dirname(__DIR__, 2);

$normal = $method->invoke($runner, [PHP_BINARY, '-r',
    'fwrite(STDOUT, "готово"); fwrite(STDERR, "диагностика");'], $cwd, 10);
budgetCheck($normal['code'] === 0
    && $normal['stdout'] === 'готово'
    && $normal['stderr'] === 'диагностика',
    'раздельные stdout и stderr штатного процесса');

$tooMuch = $method->invoke($runner, [PHP_BINARY, '-r',
    '$chunk = str_repeat("X", 1048576); for ($i = 0; $i < 64; $i++) {'
    . ' fwrite(STDOUT, $chunk); fwrite(STDERR, $chunk); }'], $cwd, 15);
budgetCheck($tooMuch['code'] === 125, 'переполнение должно завершаться отказом с кодом 125');
budgetCheck(strlen($tooMuch['stdout']) <= 1048576
    && strlen($tooMuch['stderr']) <= 1048576,
    'захваченный вывод ограничен одним МиБ на канал');
budgetCheck(str_contains($tooMuch['stderr'], 'Превышен безопасный бюджет'),
    'отказ должен содержать понятную причину');

$timeout = $method->invoke($runner, [PHP_BINARY, '-r',
    'usleep(3000000);'], $cwd, 1);
budgetCheck($timeout['code'] === 124, 'зависший процесс должен завершаться кодом 124');
budgetCheck(budgetFiles() === $before,
    'временные файлы не должны оставаться после штатного завершения, лимита или тайм-аута');

$path = tempnam(sys_get_temp_dir(), 'notes-up-out-');
budgetCheck(is_string($path), 'не удалось создать временный файл для проверки уборки');
file_put_contents($path, 'устаревший вывод');
touch($path, time() - 90000);

try {
    $cleanup = $method->invoke($runner, [PHP_BINARY, '-r', 'echo "ok";'], $cwd, 10);
    budgetCheck($cleanup['code'] === 0 && !file_exists($path),
        'осиротевший файл старше суток должен быть удалён');
} finally {
    if (file_exists($path)) {
        unlink($path);
    }
}

echo "Бюджет Windows-обновлятора: отказ при переполнении, тайм-аут и очистка проверены\n";
