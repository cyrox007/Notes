<?php

declare(strict_types=1);

namespace Modules\Defects\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * Административный жизненный цикл собственных справочников Дефектов.
 *
 * Выбор таблиц жёстко задан модулем. Подключение, параметры SQL и транзакции
 * предоставляет ядро; изменение записи и история сохраняются атомарно.
 */
final class DefectReferenceLifecycleService
{
    private const array TABLES = [
        'areas' => ['table' => 'defect_areas', 'type' => 'area', 'parent' => null],
        'categories' => ['table' => 'defect_object_categories', 'type' => 'category', 'parent' => 'area_id'],
        'objects' => ['table' => 'defect_objects', 'type' => 'object', 'parent' => 'category_id'],
        'services' => ['table' => 'defect_services', 'type' => 'service', 'parent' => null],
    ];

    public function __construct(private readonly DatabaseManager $database)
    {
    }

    /** @return array{items:list<array<string,mixed>>,next_cursor:int|null} */
    public function list(string $kind, int $limit = 40, int $afterId = 0, bool $archived = false): array
    {
        $table = $this->definition($kind)['table'];
        if ($limit < 1 || $limit > 100 || $afterId < 0) {
            throw new InvalidArgumentException('Недопустимая страница справочника');
        }

        $columns = $this->definition($kind)['parent'] === null
            ? 'id,name,is_archived,version'
            : 'id,name,is_archived,version,' . $this->definition($kind)['parent'] . ' AS parent_id';
        $rows = $this->database->fetchAll(
            'SELECT ' . $columns . ' FROM ' . $table
            . ' WHERE id>:cursor AND is_archived=:archived ORDER BY id LIMIT ' . ($limit + 1),
            ['cursor' => $afterId, 'archived' => $archived ? 1 : 0]
        );
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['version'] = (int) $row['version'];
            $row['is_archived'] = (bool) $row['is_archived'];
            if (isset($row['parent_id'])) {
                $row['parent_id'] = (int) $row['parent_id'];
            }
        }
        unset($row);

        return ['items' => $rows, 'next_cursor' => $hasMore ? (int) end($rows)['id'] : null];
    }

    /** @return array{id:int,version:int,changed:bool} */
    public function rename(string $kind, int $id, int $version, int $actorId, string $name): array
    {
        $name = trim($name);
        $maxLength = $kind === 'objects' ? 180 : 160;
        if ($name === '' || mb_strlen($name) > $maxLength) {
            throw new InvalidArgumentException('Недопустимая длина названия');
        }

        return $this->change($kind, $id, $version, $actorId, 'updated', $name);
    }

    /** @return array{id:int,version:int,changed:bool} */
    public function archive(string $kind, int $id, int $version, int $actorId): array
    {
        return $this->change($kind, $id, $version, $actorId, 'archived');
    }

    /** @return array{id:int,version:int,changed:bool} */
    public function restore(string $kind, int $id, int $version, int $actorId): array
    {
        return $this->change($kind, $id, $version, $actorId, 'restored');
    }

    /**
     * @return array{id:int,version:int,changed:bool}
     */
    private function change(
        string $kind,
        int $id,
        int $version,
        int $actorId,
        string $event,
        ?string $name = null
    ): array {
        $definition = $this->definition($kind);
        if ($id < 1 || $version < 1 || $actorId < 1) {
            throw new InvalidArgumentException('Некорректная запись, версия или автор');
        }

        $this->database->beginTransaction();
        try {
            $table = $definition['table'];
            $before = $this->database->fetchOne(
                'SELECT id,name,is_archived,version' . ($definition['parent'] === null
                    ? '' : ',' . $definition['parent'])
                . ' FROM ' . $table . ' WHERE id=:id',
                ['id' => $id]
            );
            if ($before === null) {
                throw new DomainException('Запись справочника не найдена', 404);
            }
            if ((int) $before['version'] !== $version) {
                throw new DomainException('Запись изменена другим пользователем. Обновите список.', 409);
            }

            $wasArchived = (bool) $before['is_archived'];
            if ($event === 'updated' && $wasArchived) {
                throw new DomainException('Нельзя редактировать архивную запись', 409);
            }
            if ($event === 'archived' && $wasArchived) {
                throw new DomainException('Запись уже в архиве', 409);
            }
            if ($event === 'restored' && !$wasArchived) {
                throw new DomainException('Запись уже активна', 409);
            }

            if ($event === 'updated' && $before['name'] === $name) {
                $this->database->endTransaction();
                return ['id' => $id, 'version' => $version, 'changed' => false];
            }
            if ($event === 'archived') {
                $this->guardArchive($kind, $id);
            }
            if ($event === 'restored') {
                $this->guardRestore($kind, $before);
            }

            $column = $event === 'updated' ? 'name' : 'is_archived';
            $newValue = $event === 'updated' ? $name : ($event === 'archived' ? 1 : 0);
            $affected = $this->database->execute(
                'UPDATE ' . $table . ' SET ' . $column . '=:value,version=version+1'
                . ' WHERE id=:id AND version=:version AND is_archived=:archived',
                [
                    'value' => $newValue,
                    'id' => $id,
                    'version' => $version,
                    'archived' => $wasArchived ? 1 : 0,
                ]
            );
            if ($affected !== 1) {
                throw new DomainException('Запись изменена другим пользователем. Обновите список.', 409);
            }

            (new DefectHistoryService($this->database))->record(
                $definition['type'], $id, $event, $actorId, [
                    'name_before' => $before['name'],
                    'name_after' => $event === 'updated' ? $name : $before['name'],
                    'archived_before' => $wasArchived,
                    'archived_after' => $event === 'archived' ? true
                        : ($event === 'restored' ? false : $wasArchived),
                    'version_before' => $version,
                    'version_after' => $version + 1,
                ]
            );
            $this->database->endTransaction();
            return ['id' => $id, 'version' => $version + 1, 'changed' => true];
        } catch (Throwable $error) {
            $this->database->endTransaction(false);
            throw $error;
        }
    }

    private function guardArchive(string $kind, int $id): void
    {
        $queries = [
            'areas' => 'SELECT COUNT(*) FROM defect_object_categories WHERE area_id=:id AND is_archived=0',
            'categories' => 'SELECT COUNT(*) FROM defect_objects WHERE category_id=:id AND is_archived=0',
            'objects' => "SELECT COUNT(*) FROM defects WHERE object_id=:id AND status='open'",
            'services' => "SELECT COUNT(*) FROM defect_responsible_services dr
                           JOIN defects d ON d.id=dr.defect_id
                           WHERE dr.service_id=:id AND d.status='open'",
        ];

        if ((int) $this->database->fetchValue($queries[$kind], ['id' => $id]) > 0) {
            throw new DomainException('Нельзя архивировать запись с активными зависимостями', 409);
        }
    }

    /** @param array<string,mixed> $before */
    private function guardRestore(string $kind, array $before): void
    {
        $parents = [
            'categories' => ['table' => 'defect_areas', 'column' => 'area_id'],
            'objects' => ['table' => 'defect_object_categories', 'column' => 'category_id'],
        ];
        if (!isset($parents[$kind])) {
            return;
        }

        $parent = $parents[$kind];
        $row = $this->database->fetchOne(
            'SELECT id FROM ' . $parent['table'] . ' WHERE id=:id AND is_archived=0',
            ['id' => (int) $before[$parent['column']]]
        );
        if ($row === null) {
            throw new DomainException('Сначала восстановите родительский справочник', 409);
        }
    }

    /** @return array{table:string,type:string,parent:?string} */
    private function definition(string $kind): array
    {
        return self::TABLES[$kind] ?? throw new InvalidArgumentException('Неизвестный справочник');
    }
}
