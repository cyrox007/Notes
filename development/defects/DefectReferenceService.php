<?php

declare(strict_types=1);

namespace Modules\Defects\Services;

use Core\DatabaseManager;
use InvalidArgumentException;

/**
 * Справочники дефектов используют только соединение и запросы ядра.
 * Изменения выполняются в предметной службе модуля.
 */
final class DefectReferenceService
{
    public function __construct(private readonly DatabaseManager $database)
    {
    }

    /** @return list<array<string,mixed>> */
    public function areas(int $limit = 50, int $afterId = 0): array
    {
        $this->assertPage($limit, $afterId);

        return $this->database->fetchAll(
            'SELECT id, name FROM defect_areas WHERE is_archived = 0 AND id > :after_id ORDER BY id LIMIT ' . $limit,
            ['after_id' => $afterId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function categories(int $areaId, int $limit = 50, int $afterId = 0): array
    {
        $this->assertId($areaId);
        $this->assertPage($limit, $afterId);

        return $this->database->fetchAll(
            'SELECT id, name FROM defect_object_categories WHERE area_id = :area_id AND is_archived = 0 AND id > :after_id ORDER BY id LIMIT ' . $limit,
            ['area_id' => $areaId, 'after_id' => $afterId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function objects(int $categoryId, int $limit = 50, int $afterId = 0): array
    {
        $this->assertId($categoryId);
        $this->assertPage($limit, $afterId);

        return $this->database->fetchAll(
            'SELECT id, name FROM defect_objects WHERE category_id = :category_id AND is_archived = 0 AND id > :after_id ORDER BY id LIMIT ' . $limit,
            ['category_id' => $categoryId, 'after_id' => $afterId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function services(int $limit = 50, int $afterId = 0): array
    {
        $this->assertPage($limit, $afterId);

        return $this->database->fetchAll(
            'SELECT id, name FROM defect_services WHERE is_archived = 0 AND id > :after_id ORDER BY id LIMIT ' . $limit,
            ['after_id' => $afterId]
        );
    }

    public function createArea(string $name): int
    {
        $name = $this->validatedName($name);
        $this->database->execute(
            'INSERT INTO defect_areas (name) VALUES (:name)',
            ['name' => $name]
        );

        return (int) $this->database->lastInsertId();
    }

    public function createCategory(int $areaId, string $name): int
    {
        $this->assertId($areaId);
        $name = $this->validatedName($name);
        $parent = $this->database->fetchOne(
            'SELECT id FROM defect_areas WHERE id = :id AND is_archived = 0',
            ['id' => $areaId]
        );
        if ($parent === null) {
            throw new InvalidArgumentException('Участок недоступен');
        }

        $this->database->execute(
            'INSERT INTO defect_object_categories (area_id, name) VALUES (:area_id, :name)',
            ['area_id' => $areaId, 'name' => $name]
        );

        return (int) $this->database->lastInsertId();
    }

    public function createObject(int $categoryId, string $name): int
    {
        $this->assertId($categoryId);
        $name = $this->validatedName($name);
        $parent = $this->database->fetchOne(
            'SELECT c.id FROM defect_object_categories c
             JOIN defect_areas a ON a.id = c.area_id
             WHERE c.id = :id AND c.is_archived = 0 AND a.is_archived = 0',
            ['id' => $categoryId]
        );
        if ($parent === null) {
            throw new InvalidArgumentException('Категория или участок недоступны');
        }

        $this->database->execute(
            'INSERT INTO defect_objects (category_id, name) VALUES (:category_id, :name)',
            ['category_id' => $categoryId, 'name' => $name]
        );

        return (int) $this->database->lastInsertId();
    }

    public function createService(string $name): int
    {
        $name = $this->validatedName($name);
        $this->database->execute(
            'INSERT INTO defect_services (name) VALUES (:name)',
            ['name' => $name]
        );

        return (int) $this->database->lastInsertId();
    }

    private function validatedName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('Название справочника должно содержать от 1 до 160 символов');
        }

        return $name;
    }

    private function assertId(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Идентификатор справочника должен быть положительным');
        }
    }

    private function assertPage(int $limit, int $afterId): void
    {
        if ($limit < 1 || $limit > 100 || $afterId < 0) {
            throw new InvalidArgumentException('Недопустимые параметры страницы справочника');
        }
    }
}
