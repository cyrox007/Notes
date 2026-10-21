<?php

declare(strict_types=1);

/**
 * Побайтовая сверка истории миграций с неизменяемым опубликованным тегом.
 * Путь к исходному пакету передаёт стенд, тест ничего не скачивает.
 */
$root = dirname(__DIR__, 2);
$reference = $argv[1] ?? '';
if (!is_string($reference) || !is_dir($reference . '/database/migrations')) {
    throw new RuntimeException('Передайте путь к проверенному исходному дереву v1.1.0');
}

require_once $root . '/core/MigrationManifest.php';
require_once $root . '/core/MigrationBaseline.php';

$target = new \Core\MigrationManifest($root);
$previous = new \Core\MigrationManifest($reference);
$targetNames = $target->names();
$previousNames = $previous->names();
$baseline = \Core\MigrationBaseline::appliedNames($targetNames, 11000);

if (count($baseline) !== 36) {
    throw new RuntimeException('Неожиданное число опубликованных миграций 1.1.0');
}
if ($previousNames !== $baseline) {
    throw new RuntimeException('Порядок или состав исторических миграций отличается от v1.1.0');
}

foreach ($baseline as $name) {
    $historical = $previous->readMigration($name);
    $current = $target->readMigration($name);
    if (!hash_equals(hash('sha256', $historical), hash('sha256', $current))) {
        throw new RuntimeException('Историческая миграция изменена: ' . $name);
    }
}

echo 'Исторические миграции v1.1.0: ' . count($baseline)
    . ' файлов совпадают побайтно, порядок не изменён' . PHP_EOL;
