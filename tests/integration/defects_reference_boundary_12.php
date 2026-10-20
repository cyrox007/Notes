<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/modules/defects/services/DefectReferenceService.php';
$source = (string) file_get_contents($path);

function assertDefectReferences(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, 'Нарушение контракта справочников дефектов: ' . $message . PHP_EOL);
    exit(1);
}

assertDefectReferences(str_contains($source, 'private readonly DatabaseManager $database'), 'нужен менеджер БД ядра');
assertDefectReferences(substr_count($source, '$this->database->fetchAll(') === 4, 'выборки должны идти через ядро');
assertDefectReferences(!str_contains($source, 'new PDO(') && !str_contains($source, 'getPdo('), 'прямое соединение запрещено');
assertDefectReferences(str_contains($source, 'ORDER BY id LIMIT '), 'нужна ограниченная пагинация');
assertDefectReferences(str_contains($source, '$limit > 100'), 'нужен предел размера страницы');

echo 'Контракт чтения справочников дефектов выполнен' . PHP_EOL;
