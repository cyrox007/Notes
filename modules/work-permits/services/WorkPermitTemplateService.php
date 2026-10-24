<?php

declare(strict_types=1);

namespace Modules\WorkPermits\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use PDOException;
use Throwable;

/**
 * Шаблоны принадлежат организации; выбор подставляет данные в форму,
 * не создаёт наряд и не изменяет хранимый шаблон.
 */
final class WorkPermitTemplateService
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /** @return array{items:list<array<string,mixed>>,next_cursor:?int} */
    public function listing(
        int $organizationId,
        string $kind,
        bool $archived = false,
        int $limit = 50,
        int $beforeId = 0
    ): array {
        $this->kind($kind);
        if ($organizationId < 1 || $limit < 1 || $limit > 100 || $beforeId < 0) {
            throw new InvalidArgumentException('Некорректные параметры списка шаблонов');
        }
        $where = $archived ? '' : ' AND is_archived=0';
        $params = [':org' => $organizationId, ':kind' => $kind];
        if ($beforeId > 0) {
            $where .= ' AND id<:cursor';
            $params[':cursor'] = $beforeId;
        }
        $items = $this->db->fetchAll(
            'SELECT id,organization_id,kind,name,is_archived,version,created_at,updated_at
             FROM work_permit_templates
             WHERE organization_id=:org AND kind=:kind' . $where
            . ' ORDER BY id DESC LIMIT ' . ($limit + 1),
            $params
        );
        $hasMore = count($items) > $limit;
        $items = array_slice($items, 0, $limit);
        return [
            'items' => $items,
            'next_cursor' => $hasMore ? (int) $items[array_key_last($items)]['id'] : null,
        ];
    }

    /** @return array<string,mixed> */
    public function detail(int $id, int $organizationId, bool $includeArchived = false): array
    {
        if ($id < 1 || $organizationId < 1) {
            throw new InvalidArgumentException('Укажите шаблон и организацию');
        }
        $row = $this->db->fetchOne(
            'SELECT id,organization_id,kind,name,work_description,is_archived,version
             FROM work_permit_templates
             WHERE id=:id AND organization_id=:organization LIMIT 1',
            [':id' => $id, ':organization' => $organizationId]
        );
        if ($row === null || (!$includeArchived && (int) $row['is_archived'] !== 0)) {
            throw new DomainException('Шаблон не найден или недоступен', 404);
        }
        if ($row['kind'] === 'job') {
            return $row;
        }
        $rows = $this->db->fetchAll(
            'SELECT role,employee_id FROM work_permit_template_crew
             WHERE template_id=:id ORDER BY role,employee_id',
            [':id' => $id]
        );
        $roles = [];
        $members = [];
        foreach ($rows as $member) {
            $key = (string) $member['role'];
            $value = (int) $member['employee_id'];
            if ($key === 'member') {
                $members[] = $value;
            } else {
                $roles[$key] = $value;
            }
        }
        if (!$includeArchived) {
            // Изъятого из справочника работника не следует незаметно терять.
            // Выбор такого шаблона блокируем до исправления его управляющим.
            (new WorkPermitCrewService($this->db))->prepare($organizationId, [
                'roles' => $roles,
                'member_ids' => $members,
            ]);
        }
        $row['roles'] = $roles;
        $row['member_ids'] = $members;
        return $row;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{id:int,version:int}
     */
    public function create(int $actor, array $data): array
    {
        $this->actor($actor);
        $organization = $this->number($data['organization_id'] ?? null, 'organization_id');
        $kind = $this->kind($data['kind'] ?? null);
        $name = $this->name($data['name'] ?? null);
        $this->activeOrganization($organization);
        [$description, $crew] = $this->contents($kind, $organization, $data);

        $this->db->beginTransaction();
        try {
            $this->db->execute(
                'INSERT INTO work_permit_templates
                 (organization_id,kind,name,work_description,created_by_user_id)
                 VALUES (:org,:kind,:name,:description,:actor)',
                [
                    ':org' => $organization, ':kind' => $kind, ':name' => $name,
                    ':description' => $description, ':actor' => $actor,
                ]
            );
            $id = (int) $this->db->lastInsertId();
            $this->writeCrew($id, $crew);
            $this->record($id, $actor, 'created', 1);
            $this->db->endTransaction();
        } catch (Throwable $error) {
            $this->db->endTransaction(false);
            $this->rethrow($error);
        }
        return ['id' => $id, 'version' => 1];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{id:int,version:int}
     */
    public function change(int $actor, int $id, array $data): array
    {
        $this->actor($actor);
        $version = $this->number($data['version'] ?? null, 'version');
        $action = $data['action'] ?? null;
        if (!in_array($action, ['rename', 'replace', 'archive', 'restore'], true)) {
            throw new InvalidArgumentException('Недопустимое действие с шаблоном');
        }

        $this->db->beginTransaction();
        try {
            $row = $this->db->fetchOne(
                'SELECT id,organization_id,kind,name,is_archived,version
                 FROM work_permit_templates WHERE id=:id FOR UPDATE',
                [':id' => $id]
            );
            if ($row === null) {
                throw new DomainException('Шаблон не найден', 404);
            }
            if ((int) $row['version'] !== $version) {
                throw new DomainException('Шаблон изменён другим пользователем', 409);
            }
            if ($action === 'replace' && (int) $row['is_archived'] !== 0) {
                throw new DomainException('Сначала восстановите шаблон из архива', 409);
            }

            $parameters = [':id' => $id, ':version' => $version];
            $set = match ($action) {
                'archive' => 'is_archived=1',
                'restore' => 'is_archived=0',
                'rename' => 'name=:name',
                'replace' => 'work_description=:description',
            };
            $crew = [];
            if ($action === 'rename') {
                $parameters[':name'] = $this->name($data['name'] ?? null);
            }
            if ($action === 'replace') {
                [$description, $crew] = $this->contents(
                    (string) $row['kind'], (int) $row['organization_id'], $data
                );
                $parameters[':description'] = $description;
            }
            $changed = $this->db->execute(
                'UPDATE work_permit_templates SET ' . $set . ',version=version+1
                 WHERE id=:id AND version=:version',
                $parameters
            );
            if ($changed !== 1) {
                throw new DomainException('Изменение шаблона не удалось', 409);
            }
            if ($action === 'replace') {
                $this->db->execute('DELETE FROM work_permit_template_crew WHERE template_id=:id', [':id' => $id]);
                $this->writeCrew($id, $crew);
            }
            $this->record($id, $actor, $action, $version + 1);
            $this->db->endTransaction();
        } catch (Throwable $error) {
            $this->db->endTransaction(false);
            $this->rethrow($error);
        }
        return ['id' => $id, 'version' => $version + 1];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{0:?string,1:list<array<string,mixed>>}
     */
    private function contents(string $kind, int $org, array $data): array
    {
        if ($kind === 'crew') {
            $crew = (new WorkPermitCrewService($this->db))->prepare($org, $data);
            if ($crew === []) {
                throw new InvalidArgumentException('В шаблоне бригады выберите хотя бы одного работника');
            }
            return [null, $crew];
        }
        $value = $data['work_description'] ?? null;
        if (!is_string($value) || trim($value) === ''
            || mb_strlen(trim($value)) > 10000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException('Задание шаблона должно содержать от 1 до 10000 символов');
        }
        return [trim($value), []];
    }

    /** @param list<array<string,mixed>> $rows */
    private function writeCrew(int $id, array $rows): void
    {
        foreach ($rows as $row) {
            $this->db->execute(
                'INSERT INTO work_permit_template_crew (template_id,role,employee_id)
                 VALUES (:template,:role,:employee)',
                [
                    ':template' => $id,
                    ':role' => $row['role'],
                    ':employee' => $row['employee_id'],
                ]
            );
        }
    }

    private function record(int $id, int $actor, string $action, int $version): void
    {
        $this->db->execute(
            'INSERT INTO work_permit_template_history
             (template_id,actor_user_id,action,version) VALUES (:id,:actor,:action,:version)',
            [':id' => $id, ':actor' => $actor, ':action' => $action, ':version' => $version]
        );
    }

    private function activeOrganization(int $id): void
    {
        $found = $this->db->fetchOne(
            'SELECT id FROM work_permit_organizations WHERE id=:id AND is_archived=0 LIMIT 1',
            [':id' => $id]
        );
        if ($found === null) {
            throw new DomainException('Организация не найдена или архивирована', 409);
        }
    }

    private function actor(int $actor): void
    {
        if ($actor < 1) {
            throw new InvalidArgumentException('Неизвестный пользователь');
        }
    }

    private function kind(mixed $kind): string
    {
        if (!in_array($kind, ['job', 'crew'], true)) {
            throw new InvalidArgumentException('Укажите шаблон задания или бригады');
        }
        return $kind;
    }

    private function name(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 160
            || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException('Название шаблона должно содержать от 1 до 160 символов');
        }
        return trim($value);
    }

    private function number(mixed $value, string $field): int
    {
        if ((!is_int($value) && (!is_string($value) || preg_match('/^[1-9]\d{0,14}$/D', $value) !== 1))
            || (int) $value < 1) {
            throw new InvalidArgumentException('Некорректное значение: ' . $field);
        }
        return (int) $value;
    }

    private function rethrow(Throwable $error): never
    {
        if ($error instanceof PDOException && (string) $error->getCode() === '23000') {
            throw new DomainException('Шаблон с таким названием уже существует или нарушена связь', 409, $error);
        }
        throw $error;
    }
}
