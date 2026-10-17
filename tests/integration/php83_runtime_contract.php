<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function runtimeCheck(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "Контракт runtime PHP 8.3 нарушен: {$message}\n");
    exit(1);
}

function runtimeRequireMarker(string $root, string $path, string $marker, string $message): void
{
    $content = file_get_contents($root . '/' . $path);
    runtimeCheck(is_string($content) && str_contains($content, $marker), $message);
}

runtimeCheck(PHP_VERSION_ID >= 80300, 'проверка должна выполняться на PHP 8.3 или новее');
runtimeRequireMarker(
    $root,
    'composer.json',
    '"php": ">=8.3"',
    'Composer должен фиксировать минимум PHP 8.3'
);
runtimeRequireMarker(
    $root,
    'composer.lock',
    '"php": ">=8.3"',
    'composer.lock должен совпадать с минимумом PHP 8.3'
);
runtimeRequireMarker(
    $root,
    'core/InstallerEnvironmentService.php',
    "'PHP 8.3+' => version_compare(PHP_VERSION, '8.3.0', '>=')",
    'web-установщик должен отклонять PHP ниже 8.3'
);
runtimeRequireMarker(
    $root,
    'bin/healthcheck.php',
    "version_compare(PHP_VERSION, '8.3.0', '>=')",
    'healthcheck должен проверять минимум PHP 8.3'
);
runtimeRequireMarker(
    $root,
    'core/CliRuntime.php',
    "private const MINIMUM_PHP_VERSION = '8.3.0';",
    'общий CLI bootstrap должен фиксировать минимум PHP 8.3'
);
runtimeRequireMarker(
    $root,
    'core/UpdateExternalCliSupport.php',
    "private const MINIMUM_PHP_VERSION = '8.3.0';",
    'автономный обновлятор должен фиксировать минимум PHP 8.3'
);
runtimeRequireMarker(
    $root,
    'core/UpdatePhpCli.php',
    "version_compare(\$version, \$minimumVersion, '>=')",
    'выбранный UPDATE_PHP_BINARY должен проверяться по реальной версии'
);
runtimeRequireMarker(
    $root,
    'core/UpdateVerifiedStage.php',
    '$stager->assertCompatibility($manifest, Version::VERSION_CODE, PHP_VERSION);',
    'подписанный пакет должен проходить PHP preflight до destructive-фазы'
);

require_once $root . '/core/UpdateProcessRunner.php';
require_once $root . '/core/UpdatePhpCli.php';

runtimeCheck(\Core\UpdateProcessRunner::available(), 'proc_open должен быть доступен в профильном CI 1.2');
$processResult = (new \Core\UpdateProcessRunner())->run(
    [PHP_BINARY, '-r', 'fwrite(STDOUT, "runtime-ok"); exit(23);'],
    $root,
    10
);
runtimeCheck($processResult['code'] === 23, 'должен сохраняться реальный код завершения дочернего PHP-процесса');
runtimeCheck(trim($processResult['stdout']) === 'runtime-ok', 'должен сохраняться stdout дочернего PHP-процесса');

$previousUpdatePhp = getenv('UPDATE_PHP_BINARY');
putenv('UPDATE_PHP_BINARY=' . PHP_BINARY);
try {
    $resolved = \Core\UpdatePhpCli::resolve('8.3.0');
    runtimeCheck(is_file($resolved), 'совместимый PHP CLI должен успешно разрешаться');

    $rejected = false;
    try {
        \Core\UpdatePhpCli::resolve('99.0.0');
    } catch (RuntimeException $exception) {
        $rejected = str_contains($exception->getMessage(), '99.0.0');
    }
    runtimeCheck($rejected, 'CLI ниже целевого requires_php должен отклоняться до запуска обновления');
} finally {
    if ($previousUpdatePhp === false) {
        putenv('UPDATE_PHP_BINARY');
    } else {
        putenv('UPDATE_PHP_BINARY=' . $previousUpdatePhp);
    }
}

echo 'Контракт эксплуатационного runtime PHP 8.3 выполнен' . PHP_EOL;
