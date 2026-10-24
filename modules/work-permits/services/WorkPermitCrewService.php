<?php

declare(strict_types=1);

namespace Modules\WorkPermits\Services;

use Core\DatabaseManager;
use InvalidArgumentException;
use DomainException;

/**
 * Состав бригады хранится отдельными строками, допускает совмещение ролей.
 * Снимок ФИО и группы сохраняется в документе на момент изменения.
 */
final class WorkPermitCrewService
{
    private const SINGLE_ROLES = [
        'issuer', 'responsible_manager', 'admitter', 'producer', 'observer',
    ];

    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array{role:string,employee_id:int,employee_name:string,electrical_group:string}>
     */
    public function prepare(int $organizationId, array $payload): array
    {
        if ($organizationId < 1) {
            throw new InvalidArgumentException('Для выбора бригады нужна организация');
        }
        $roles = $payload['roles'] ?? null;
        $members = $payload['member_ids'] ?? null;
        if (!is_array($roles) || array_is_list($roles)
            || !is_array($members) || !array_is_list($members)) {
            throw new InvalidArgumentException('Укажите роли и список членов бригады');
        }
        foreach (array_keys($roles) as $role) {
            if (!in_array($role, self::SINGLE_ROLES, true)) {
                throw new InvalidArgumentException('Недопустимая роль в бригаде');
            }
        }
        if (count($members) > 30) {
            throw new InvalidArgumentException('В бригаде не более 30 участников');
        }

        $selected = [];
        foreach (self::SINGLE_ROLES as $role) {
            $value = $roles[$role] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $selected[$role] = [$this->id($value)];
        }
        $memberIds = array_map($this->id(...), $members);
        if (count(array_unique($memberIds)) !== count($memberIds)) {
            throw new InvalidArgumentException('Участник бригады выбран повторно');
        }
        sort($memberIds, SORT_NUMERIC);
        $selected['member'] = $memberIds;

        $result = [];
        foreach ($selected as $role => $employeeIds) {
            foreach ($employeeIds as $employeeId) {
                $employee = $this->db->fetchOne(
                    'SELECT id,name,electrical_group FROM work_permit_employees
                     WHERE id=:id AND organization_id=:organization AND is_archived=0 LIMIT 1',
                    [':id' => $employeeId, ':organization' => $organizationId]
                );
                if ($employee === null) {
                    throw new DomainException('Сотрудник отсутствует, архивирован или принадлежит другой организации', 409);
                }
                $result[] = [
                    'role' => $role,
                    'employee_id' => $employeeId,
                    'employee_name' => (string) $employee['name'],
                    'electrical_group' => (string) $employee['electrical_group'],
                ];
            }
        }
        return $result;
    }

    /**
     * Вызывается внутри транзакции изменения черновика.
     *
     * @param list<array{role:string,employee_id:int,employee_name:string,electrical_group:string}> $rows
     */
    public function replace(int $documentId, array $rows): void
    {
        $this->db->execute('DELETE FROM work_permit_crew WHERE document_id=:id', [':id' => $documentId]);
        foreach ($rows as $row) {
            $this->db->execute(
                'INSERT INTO work_permit_crew
                    (document_id,role,employee_id,employee_name,electrical_group)
                 VALUES (:document,:role,:employee,:name,:group)',
                [
                    ':document' => $documentId, ':role' => $row['role'],
                    ':employee' => $row['employee_id'], ':name' => $row['employee_name'],
                    ':group' => $row['electrical_group'],
                ]
            );
        }
    }

    /** @return list<array<string,mixed>> */
    public function forDocument(int $documentId): array
    {
        return $this->db->fetchAll(
            'SELECT role,employee_id,employee_name,electrical_group
             FROM work_permit_crew WHERE document_id=:id
             ORDER BY FIELD(role,\'issuer\',\'responsible_manager\',\'admitter\',\'producer\',\'observer\',\'member\'),employee_id',
            [':id' => $documentId]
        );
    }

    private function id(mixed $value): int
    {
        if ((!is_int($value) && (!is_string($value) || preg_match('/^[1-9]\d{0,14}$/D', $value) !== 1))
            || (int) $value < 1) {
            throw new InvalidArgumentException('Некорректный идентификатор сотрудника');
        }
        return (int) $value;
    }
}
