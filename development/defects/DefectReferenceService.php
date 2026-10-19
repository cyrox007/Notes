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
