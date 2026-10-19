<?php

declare(strict_types=1);

// Архитектурный контракт: модули используют средства ядра вместо прямого PDO.
// Для унаследованного кода остальных модулей список нарушений фиксируется
// отдельно и не должен расширяться без изменения самого контракта.
$root = dirname(__DIR__, 2);
$modules = $root . '/modules';
$violations = [];
$detector = '/->getPdo\s*\(|new\s+\\\\?(?:PDO|PrefixPDO)\s*\(|\$[a-zA-Z_][a-zA-Z0-9_]*pdo[a-zA-Z0-9_]*\s*->\s*(?:prepare|query|exec|beginTransaction|commit|rollBack)\s*\(/i';

foreach (new DirectoryIterator($modules) as $module) {
    if (!$module->isDir() || $module->isDot()) {
        continue;
    }
    $directory = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($module->getPathname(), FilesystemIterator::SKIP_DOTS)
    );
    foreach ($directory as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $source = (string) file_get_contents($file->getPathname());
        $lines = preg_split('/\R/', $source);
        foreach ($lines as $line => $sourceLine) {
            if (preg_match($detector, $sourceLine) === 1) {
                $violations[$path][] = $line + 1;
            }
        }
    }
}
ksort($violations, SORT_STRING);
foreach ($violations as $path => $lines) {
    echo $path . ': ' . implode(',', $lines) . PHP_EOL;
}
echo 'Нарушений границы БД: ' . array_sum(array_map('count', $violations)) . PHP_EOL;

if (in_array('--report', $argv, true)) {
    exit(0);
}

// Новая архитектура Files обязательна; остальные модули проходят
// постепенную миграцию по зафиксированному исходному списку.
foreach ($violations as $path => $lines) {
    if (str_starts_with($path, 'modules/files/')) {
        fwrite(STDERR, 'Прямой доступ к БД в Files запрещён: ' . $path . PHP_EOL);
        exit(1);
    }
}

$baselinePath = $root . '/tests/integration/module_database_legacy_12.json';
if (is_file($baselinePath)) {
    $baseline = json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($violations as $path => $lines) {
        if (str_starts_with($path, 'modules/files/')) {
            continue;
        }
        if (count($lines) > (int) ($baseline[$path] ?? 0)) {
            fwrite(STDERR, 'Добавлен обход ядра в модуле: ' . $path . PHP_EOL);
            exit(1);
        }
    }
}
echo "Контракт использования ядра модулями выполнен\n";
