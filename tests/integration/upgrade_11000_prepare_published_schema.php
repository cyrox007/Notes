<?php

declare(strict_types=1);

/**
 * Создаёт SQL опубликованной схемы 1.1.0 в пространстве DBPREFIX.
 * Читает только переданный проверенный файл дерева published; не меняет БД.
 */
$root = dirname(__DIR__, 2);
require_once $root . '/core/DatabasePrefix.php';

$schema = $argv[1] ?? null;
$published = realpath($root . '/../published');
$path = is_string($schema) ? realpath($schema) : false;
if (!is_string($published) || !is_string($path)
    || !str_starts_with($path, $published . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR)
    || is_link((string) $schema) || !is_file($path)) {
    throw new RuntimeException('Допустима только опубликованная SQL-схема из дерева v1.1.0');
}

$sql = file_get_contents($path);
if (!is_string($sql) || $sql === '' || strlen($sql) > 16777216) {
    throw new RuntimeException('Опубликованная схема отсутствует или превышает безопасный размер');
}

$namespace = new \Core\DatabasePrefix();
echo $namespace->sql($sql, true);
