<?php

declare(strict_types=1);

// Контракт владения таблицами из docs/MODULE_DEVELOPMENT.md и docs/MODULE_PLATFORM.md.
// Анализируются строковые литералы SQL; динамически составленные выражения
// дополнительно проверяются при ревью и контрактными испытаниями.
$root = dirname(__DIR__, 2);
$modulesRoot = $root . '/modules';
$owners = [];
$violations = [];

foreach (new DirectoryIterator($modulesRoot) as $module) {
    if (!$module->isDir() || $module->isDot()) {
        continue;
    }
    $moduleId = $module->getFilename();
    $manifest = json_decode(
        (string) file_get_contents($module->getPathname() . '/module.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    foreach ($manifest['database']['tables'] ?? [] as $table) {
        if (isset($owners[$table]) && $owners[$table] !== $moduleId) {
            throw new RuntimeException('Дублируется владелец таблицы: ' . $table);
        }
        $owners[$table] = $moduleId;
    }
}
ksort($owners);

foreach (new DirectoryIterator($modulesRoot) as $module) {
    if (!$module->isDir() || $module->isDot()) {
        continue;
    }

    $moduleId = $module->getFilename();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($module->getPathname(), FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $tokens = token_get_all((string) file_get_contents($file->getPathname()));
        foreach ($tokens as $token) {
            if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            $literal = $token[1];
            preg_match_all(
                '/\b(?:FROM|JOIN|INTO|UPDATE|TRUNCATE(?:\s+TABLE)?|DELETE\s+FROM)\s+[`]?([a-z][a-z0-9_]*)[`]?/i',
                $literal,
                $matches
            );
            foreach ($matches[1] as $table) {
                $owner = $owners[strtolower($table)] ?? null;
                if ($owner === null || $owner === $moduleId) {
                    continue;
                }
                $key = $path . '|SQL:' . strtolower($table) . '(' . $owner . ')';
                $violations[$key] = ($violations[$key] ?? 0) + 1;
            }

            // Прямое подключение внутренностей соседнего модуля запрещено.
            preg_match_all('~(?:modules/|modules\\\\)([a-z][a-z0-9_-]+)[/\\\\]~i', $literal, $paths);
            foreach ($paths[1] as $other) {
                if ($other !== $moduleId && is_dir($modulesRoot . '/' . $other)) {
                    $key = $path . '|PHP:module(' . $other . ')';
                    $violations[$key] = ($violations[$key] ?? 0) + 1;
                }
            }
        }
    }
}
ksort($violations);
echo 'Выявлено обращений к чужим данным и файлам модулей: '
    . array_sum($violations) . PHP_EOL;
foreach ($violations as $key => $count) {
    echo $key . ' × ' . $count . PHP_EOL;
}

if (in_array('--report', $argv, true)) {
    exit(0);
}

$baselinePath = __DIR__ . '/module_schema_ownership_exceptions_12.json';
$exceptions = json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($exceptions)) {
    throw new RuntimeException('Некорректная база согласованных исключений владения данными');
}
foreach ($exceptions as $key => $reason) {
    if (!is_string($reason) || mb_strlen(trim($reason)) < 20) {
        throw new RuntimeException('Исключение без технического обоснования: ' . $key);
    }
}
$unknown = array_diff_key($violations, $exceptions);
if ($unknown !== []) {
    fwrite(STDERR, "Несогласованные нарушения владения данными:\n");
    foreach ($unknown as $key => $count) {
        fwrite(STDERR, "- {$key}: {$count}\n");
    }
    exit(1);
}
echo "Проверка границ владения таблицами модулей пройдена\n";
