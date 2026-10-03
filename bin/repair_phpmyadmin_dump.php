<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/DatabaseDumpCompatibility.php';

use Core\DatabaseDumpCompatibility;

$options = getopt('', ['input:', 'output:']);
$input = trim((string) ($options['input'] ?? ''));
$output = trim((string) ($options['output'] ?? ''));

if ($input === '' || $output === '') {
    fwrite(STDERR, "Использование:\n  php bin/repair_phpmyadmin_dump.php --input=backup.sql --output=backup-fixed.sql\n\nИсходный файл никогда не изменяется на месте.\n");
    exit(2);
}

try {
    $result = DatabaseDumpCompatibility::repairPhpMyAdminDump($input, $output);
    fwrite(
        STDOUT,
        "[OK] Исправленный дамп создан: {$output}\n"
        . "Исправлено индексных определений: {$result['replacements']}\n"
        . "Размер результата: {$result['bytes']} байт\n"
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[FAIL] Не удалось исправить дамп: {$e->getMessage()}\n");
    exit(1);
}
