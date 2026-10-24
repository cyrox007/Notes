<?php

declare(strict_types=1);

namespace Modules\WorkPermits\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * Каждый справочник владеет своей таблицей. Названия таблиц выбираются
 * только из фиксированного перечня, никогда не приходят из HTTP как SQL.
 */
final class WorkPermitReferenceService
{
    private const TABLES = [
        'organizations' => 'work_permit_organizations',
        'departments' => 'work_permit_departments',
        'facilities' => 'work_permit_facilities',
        'employees' => 'work_permit_employees',
    ];

    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /** @return array{items:list<array<string,mixed>>,next_cursor:?int} */
    public function listing(
        string $kind,
        int $organizationId = 0,
        bool $includeArchived = false,
        int $limit = 50,
        int $beforeId = 0
    ): array {
        $table = $this->table($kind);
        if ($organizationId < 0 || $beforeId < 0 || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Некорректные параметры справочника');
        }
        $conditions = [];
        $params = [];
        if (!$includeArchived) {
            $conditions[] = 'is_archived=0';
        }
        if ($kind !== 'organizations' && $organizationId > 0) {
            $conditions[] = 'organization_id=:organization';
            $params[':organization'] = $organizationId;
        }
        if ($beforeId > 0) {
            $conditions[] = 'id<:before';
            $params[':before'] = $beforeId;
        }
        $where = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $fields = $kind === 'organizations' ? 'id,name,time_zone,is_archived,version'
            : ($kind === 'employees' ? 'id,organization_id,department_id,name,electrical_group,is_archived,version'
                : 'id,organization_id,name,is_archived,version');
        $rows = $this->db->fetchAll(
            'SELECT ' . $fields . ' FROM ' . $table . $where
                . ' ORDER BY id DESC LIMIT ' . ($limit + 1),
            $params
        );
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return [
            'items' => $rows,
            'next_cursor' => $hasMore ? (int) $rows[array_key_last($rows)]['id'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{id:int,version:int}
     */
    public function create(string $kind, array $data, int $actor): array
    {
        $table = $this->table($kind);
        $name = $this->validatedName($data['name'] ?? null, $kind);
        $org = $this->positiveId($data['organization_id'] ?? null);
        $dept = $this->optionalId($data['department_id'] ?? null);
        $group = $this->group($data['electrical_group'] ?? '');
        if ($kind === 'organizations') {
            $org = null;
            $dept = null;
        } else {
            if ($org === null) {
                throw new InvalidArgumentException('Выберите организацию');
            }
            $this->active('organizations', $org);
        }
        if ($kind === 'employees' && $dept !== null) {
            $department = $this->active('departments', $dept);
            if ((int) $department['organization_id'] !== $org) {
                throw new DomainException('Подразделение принадлежит другой организации', 409);
            }
        }

        $cols = ['name'];
        $params = [':name' => $name];
        if ($kind === 'organizations') {
            $cols[] = 'time_zone';
            $params[':time_zone'] = WorkPermitLocalDay::validate($data['time_zone'] ?? 'UTC');
        }
        if ($kind !== 'organizations') {
            $cols[] = 'organization_id';
            $params[':organization_id'] = $org;
        }
        if ($kind === 'employees') {
            $cols[] = 'department_id';
            $cols[] = 'electrical_group';
            $params[':department_id'] = $dept;
            $params[':electrical_group'] = $group;
        }
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $cols);
        $this->db->beginTransaction();
        try {
            $this->db->execute(
                'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')',
                $params
            );
            $id = (int) $this->db->lastInsertId();
            $this->record($kind, $id, $actor, 'created', 1);
            $this->db->endTransaction();
            return ['id' => $id, 'version' => 1];
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
    }

    /**
     * Сравнение версии и запись истории выполняются в одной транзакции.
     *
     * @return array{id:int,version:int,archived:bool}
     */
    public function change(
        string $kind,
        int $id,
        int $expectedVersion,
        int $actor,
        ?string $name = null,
        ?bool $archived = null
    ): array {
        $table = $this->table($kind);
        if ($id < 1 || $expectedVersion < 1 || (($name === null) === ($archived === null))) {
            throw new InvalidArgumentException('Укажите одно изменение и текущую версию');
        }
        $params = [':id' => $id, ':version' => $expectedVersion];
        if ($name !== null) {
            $params[':value'] = $this->validatedName($name, $kind);
            $set = 'name=:value';
            $action = 'renamed';
        } else {
            $params[':value'] = $archived ? 1 : 0;
            $set = 'is_archived=:value';
            $action = $archived ? 'archived' : 'restored';
        }

        $this->db->beginTransaction();
        try {
            $changed = $this->db->execute(
                'UPDATE ' . $table . ' SET ' . $set . ',version=version+1
                 WHERE id=:id AND version=:version',
                $params
            );
            if ($changed !== 1) {
                throw new DomainException('Справочник изменён другим пользователем или запись не найдена', 409);
            }
            $this->record($kind, $id, $actor, $action, $expectedVersion + 1);
            $this->db->endTransaction();
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
        return ['id' => $id, 'version' => $expectedVersion + 1, 'archived' => $archived ?? false];
    }

    /** Смена часового пояса относится только к будущим выдачам нарядов. */
    public function changeTimeZone(int $id, int $version, int $actor, mixed $zone): array
    {
        if ($id < 1 || $version < 1) {
            throw new InvalidArgumentException('Неверный идентификатор или версия');
        }
        $zone = WorkPermitLocalDay::validate($zone);
        $this->db->beginTransaction();
        try {
            $changed = $this->db->execute(
                'UPDATE work_permit_organizations SET time_zone=:zone,version=version+1
                 WHERE id=:id AND version=:version AND is_archived=0',
                [':zone' => $zone, ':id' => $id, ':version' => $version]
            );
            if ($changed !== 1) {
                throw new DomainException('Организация изменена или архивирована', 409);
            }
            $this->record('organizations', $id, $actor, 'time_zone_changed', $version + 1);
            $this->db->endTransaction();
        } catch (Throwable $error) {
            $this->db->endTransaction(false);
            throw $error;
        }
        return ['id' => $id, 'version' => $version + 1, 'time_zone' => $zone];
    }

    /**
     * Ссылки сверяются на сервере. Названия для документа берутся из БД,
     * чтобы клиент не подменил объект или подразделение.
     *
     * @param array<string,mixed> $input
     * @return array{organization:string,subdivision:string,facility:string,organization_id:int,department_id:?int,facility_id:int}
     */
    public function resolveDraft(array $input): array
    {
        $orgId = $this->positiveId($input['organization_id'] ?? null);
        $facilityId = $this->positiveId($input['facility_id'] ?? null);
        $deptId = $this->optionalId($input['department_id'] ?? null);
        if ($orgId === null || $facilityId === null) {
            throw new InvalidArgumentException('Выберите организацию и объект');
        }
        $org = $this->active('organizations', $orgId);
        $facility = $this->active('facilities', $facilityId);
        if ((int) $facility['organization_id'] !== $orgId) {
            throw new DomainException('Объект относится к другой организации', 409);
        }
        $deptName = '';
        if ($deptId !== null) {
            $department = $this->active('departments', $deptId);
            if ((int) $department['organization_id'] !== $orgId) {
                throw new DomainException('Подразделение относится к другой организации', 409);
            }
            $deptName = (string) $department['name'];
        }
        return [
            'organization' => (string) $org['name'],
            'subdivision' => $deptName,
            'facility' => (string) $facility['name'],
            'organization_id' => $orgId,
            'department_id' => $deptId,
            'facility_id' => $facilityId,
        ];
    }

    /** @return array<string,mixed> */
    private function active(string $kind, int $id): array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->table($kind) . ' WHERE id=:id AND is_archived=0 LIMIT 1',
            [':id' => $id]
        );
        if ($row === null) {
            throw new DomainException('Запись справочника не найдена или архивирована', 409);
        }
        return $row;
    }

    private function record(string $kind, int $id, int $actor, string $action, int $version): void
    {
        $this->db->execute(
            'INSERT INTO work_permit_reference_history
                (reference_kind,reference_id,actor_user_id,action,version)
             VALUES (:kind,:id,:actor,:action,:version)',
            [':kind' => $kind, ':id' => $id, ':actor' => $actor, ':action' => $action, ':version' => $version]
        );
    }

    private function table(string $kind): string
    {
        return self::TABLES[$kind] ?? throw new InvalidArgumentException('Неизвестный справочник');
    }

    private function validatedName(mixed $value, string $kind): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Укажите название');
        }
        $name = trim($value);
        $limit = in_array($kind, ['facilities', 'employees'], true) ? 180 : 160;
        if ($name === '' || mb_strlen($name) > $limit
            || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            throw new InvalidArgumentException('Недопустимое название справочника');
        }
        return $name;
    }

    private function group(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['', 'I', 'II', 'III', 'IV', 'V'], true)) {
            throw new InvalidArgumentException('Группа электробезопасности должна быть от I до V');
        }
        return $value;
    }

    private function positiveId(mixed $value): ?int
    {
        if (!is_int($value) && (!is_string($value) || !preg_match('/^[1-9]\d{0,14}$/D', $value))) {
            return null;
        }
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function optionalId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->positiveId($value)
            ?? throw new InvalidArgumentException('Неверный идентификатор справочника');
    }
}
