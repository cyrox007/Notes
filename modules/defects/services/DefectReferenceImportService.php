<?php

declare(strict_types=1);

namespace Modules\Defects\Services;

use Core\DatabaseManager;
use InvalidArgumentException;
use Throwable;

/**
 * Импорт только справочников модуля. Результат сначала просматривается,
 * затем повторно проверяется и применяется в одной транзакции ядра.
 */
final class DefectReferenceImportService
{
    private const int MAX_BYTES = 524288;
    private const int MAX_ROWS = 200;

    public function __construct(private readonly DatabaseManager $database)
    {
    }

    /** @return array{hash:string,total:int,rows:list<array<string,mixed>>,errors:list<string>} */
    public function preview(string $contents): array
    {
        [$rows, $errors] = $this->parse($contents);
        if ($errors === []) {
            $errors = $this->validateParents($rows);
        }

        return [
            'hash' => hash('sha256', $contents),
            'total' => count($rows),
            'rows' => $rows,
            'errors' => $errors,
        ];
    }

    /** @return array{created:int,existing:int,total:int} */
    public function apply(string $contents, string $expectedHash, ?int $actorId = null): array
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $expectedHash) !== 1
            || !hash_equals($expectedHash, hash('sha256', $contents))) {
            throw new InvalidArgumentException('Файл изменился после предварительного просмотра');
        }

        $preview = $this->preview($contents);
        if ($preview['errors'] !== []) {
            throw new InvalidArgumentException(implode('; ', array_slice($preview['errors'], 0, 3)));
        }

        $priority = ['area' => 0, 'service' => 1, 'category' => 2, 'object' => 3];
        $rows = $preview['rows'];
        usort($rows, static fn (array $a, array $b): int =>
            ($priority[$a['type']] <=> $priority[$b['type']]) ?: ($a['line'] <=> $b['line']));

        $created = 0;
        $existing = 0;
        $references = new DefectReferenceService($this->database);

        $this->database->beginTransaction();
        try {
            foreach ($rows as $row) {
                $old = $this->findExisting($row);
                if ($old !== null) {
                    $existing++;
                    continue;
                }

                $this->createRow($references, $row, $actorId);
                $created++;
            }
            $this->database->endTransaction();
        } catch (Throwable $error) {
            $this->database->endTransaction(false);
            throw $error;
        }

        return ['created' => $created, 'existing' => $existing, 'total' => count($rows)];
    }

    /** @return array{0:list<array<string,mixed>>,1:list<string>} */
    private function parse(string $contents): array
    {
        if ($contents === '' || strlen($contents) > self::MAX_BYTES
            || str_contains($contents, "\0") || !mb_check_encoding($contents, 'UTF-8')) {
            throw new InvalidArgumentException('Нужен текстовый файл UTF-8 размером не более 512 КиБ');
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $rows = [];
        $errors = [];
        $seen = [];

        foreach ($lines as $number => $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (strlen($line) > 512) {
                $errors[] = 'Строка ' . ($number + 1) . ': превышена длина';
                continue;
            }

            $columns = array_map('trim', str_getcsv($line, ';', '"', ''));
            $type = strtolower($columns[0] ?? '');
            $length = ['area' => 2, 'service' => 2, 'category' => 3, 'object' => 4][$type] ?? 0;
            if ($length === 0 || count($columns) !== $length
                || count(array_filter($columns, static fn (string $value): bool =>
                    $value === '' || mb_strlen($value) > 160)) !== 0) {
                $errors[] = 'Строка ' . ($number + 1) . ': неверный тип, количество полей или длина';
                continue;
            }

            $key = mb_strtolower(implode('|', $columns));
            if (isset($seen[$key])) {
                $errors[] = 'Строка ' . ($number + 1) . ': повтор записи';
                continue;
            }
            $seen[$key] = true;
            $rows[] = ['line' => $number + 1, 'type' => $type, 'fields' => array_slice($columns, 1)];

            if (count($rows) > self::MAX_ROWS) {
                throw new InvalidArgumentException('Импорт ограничен 200 записями за одну операцию');
            }
        }

        if ($rows === [] && $errors === []) {
            $errors[] = 'Файл не содержит записей';
        }

        return [$rows, $errors];
    }

    /** @param list<array<string,mixed>> $rows @return list<string> */
    private function validateParents(array $rows): array
    {
        $plannedAreas = [];
        $plannedCategories = [];
        foreach ($rows as $row) {
            if ($row['type'] === 'area') {
                $plannedAreas[mb_strtolower($row['fields'][0])] = true;
            }
            if ($row['type'] === 'category') {
                $plannedCategories[mb_strtolower(implode('|', $row['fields']))] = true;
            }
        }

        $errors = [];
        foreach ($rows as $row) {
            $fields = $row['fields'];
            if ($row['type'] === 'category' && !isset($plannedAreas[mb_strtolower($fields[0])])
                && $this->findArea($fields[0]) === null) {
                $errors[] = 'Строка ' . $row['line'] . ': участок не найден';
            }
            if ($row['type'] === 'object' && !isset($plannedCategories[mb_strtolower($fields[0] . '|' . $fields[1])])
                && $this->findCategory($fields[0], $fields[1]) === null) {
                $errors[] = 'Строка ' . $row['line'] . ': категория не найдена';
            }
        }

        return $errors;
    }

    /** @param array<string,mixed> $row */
    private function findExisting(array $row): ?int
    {
        $fields = $row['fields'];
        return match ($row['type']) {
            'area' => $this->findArea($fields[0]),
            'service' => $this->findService($fields[0]),
            'category' => $this->findCategory($fields[0], $fields[1]),
            'object' => $this->findObject($fields[0], $fields[1], $fields[2]),
        };
    }

    /** @param array<string,mixed> $row */
    private function createRow(DefectReferenceService $service, array $row, ?int $actorId): void
    {
        $fields = $row['fields'];
        match ($row['type']) {
            'area' => $service->createArea($fields[0], $actorId),
            'service' => $service->createService($fields[0], $actorId),
            'category' => $service->createCategory(
                $this->requiredId($this->findArea($fields[0])), $fields[1], $actorId
            ),
            'object' => $service->createObject(
                $this->requiredId($this->findCategory($fields[0], $fields[1])), $fields[2], $actorId
            ),
        };
    }

    private function requiredId(?int $id): int
    {
        if ($id === null) {
            throw new InvalidArgumentException('Внешняя ссылка справочника отсутствует');
        }
        return $id;
    }

    private function findArea(string $name): ?int
    {
        $id = $this->database->fetchValue(
            'SELECT id FROM defect_areas WHERE name=:name AND is_archived=0 LIMIT 1',
            ['name' => $name]
        );
        return $id === null ? null : (int) $id;
    }

    private function findService(string $name): ?int
    {
        $id = $this->database->fetchValue(
            'SELECT id FROM defect_services WHERE name=:name AND is_archived=0 LIMIT 1',
            ['name' => $name]
        );
        return $id === null ? null : (int) $id;
    }

    private function findCategory(string $area, string $category): ?int
    {
        $areaId = $this->findArea($area);
        if ($areaId === null) {
            return null;
        }
        $id = $this->database->fetchValue(
            'SELECT id FROM defect_object_categories
             WHERE area_id=:area AND name=:name AND is_archived=0 LIMIT 1',
            ['area' => $areaId, 'name' => $category]
        );
        return $id === null ? null : (int) $id;
    }

    private function findObject(string $area, string $category, string $name): ?int
    {
        $categoryId = $this->findCategory($area, $category);
        if ($categoryId === null) {
            return null;
        }
        $id = $this->database->fetchValue(
            'SELECT id FROM defect_objects
             WHERE category_id=:category AND name=:name AND is_archived=0 LIMIT 1',
            ['category' => $categoryId, 'name' => $name]
        );
        return $id === null ? null : (int) $id;
    }
}
