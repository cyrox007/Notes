<?php

declare(strict_types=1);

namespace Modules\WorkPermits\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * Первый функциональный этап: регистрация и личный журнал черновиков.
 * Выдача и закрытие не разрешаются до реализации проверок жизненного цикла.
 */
final class WorkPermitDraftService
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /**
     * @param array<string,mixed> $input
     * @return array{id:int,number:int,replayed:bool,state:string}
     */
    public function create(int $userId, array $input): array
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Укажите пользователя');
        }
        $kind = $this->kind($input['kind'] ?? null);
        $usesReferences = array_key_exists('organization_id', $input)
            || array_key_exists('facility_id', $input);
        $organization = $usesReferences ? '' : $this->field($input, 'organization', 160);
        $subdivision = $usesReferences ? '' : $this->field($input, 'subdivision', 160, false);
        $facility = $usesReferences ? '' : $this->field($input, 'facility', 180);
        $description = $this->field($input, 'work_description', 10000);
        $command = $this->field($input, 'command_id', 96);
        if (preg_match('/^[a-f0-9]{32}$/D', $command) !== 1) {
            throw new InvalidArgumentException('Для записи нужен новый идентификатор команды');
        }

        // Повтор команды не зависит от переименования справочника.
        $referenceIds = $usesReferences ? [
            $input['organization_id'] ?? null,
            $input['department_id'] ?? null,
            $input['facility_id'] ?? null,
        ] : null;
        $fingerprint = hash('sha256', json_encode([
            $userId, $kind,
            $usesReferences ? $referenceIds : [$organization, $subdivision, $facility],
            $description,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $existing = $this->existing($command, $userId, $fingerprint);
        if ($existing !== null) {
            return $existing;
        }

        $this->db->beginTransaction();
        try {
            $references = $usesReferences
                ? (new WorkPermitReferenceService($this->db))->resolveDraft($input)
                : [
                    'organization' => $organization, 'subdivision' => $subdivision,
                    'facility' => $facility, 'organization_id' => null,
                    'department_id' => null, 'facility_id' => null,
                ];
            $this->db->execute(
                'INSERT INTO work_permit_documents
                 (kind,state,organization,subdivision,facility,organization_id,department_id,facility_id,
                  work_description,created_by_user_id,creation_command_id,creation_request_hash)
                 VALUES (:kind,\'draft\',:org,:unit,:facility,:org_id,:dept_id,:facility_id,
                         :description,:user,:command,:hash)',
                [
                    ':kind' => $kind,
                    ':org' => $references['organization'],
                    ':unit' => $references['subdivision'],
                    ':facility' => $references['facility'],
                    ':org_id' => $references['organization_id'],
                    ':dept_id' => $references['department_id'],
                    ':facility_id' => $references['facility_id'],
                    ':description' => $description,
                    ':user' => $userId,
                    ':command' => $command,
                    ':hash' => $fingerprint,
                ]
            );
            $id = (int) $this->db->lastInsertId();
            if ($id < 1) {
                throw new DomainException('Не удалось получить номер документа', 409);
            }
            $this->db->execute(
                'INSERT INTO work_permit_history (document_id,actor_user_id,event_type,version)
                 VALUES (:id,:actor,\'draft_created\',1)',
                [':id' => $id, ':actor' => $userId]
            );
            $this->db->endTransaction();
            return ['id' => $id, 'number' => $id, 'replayed' => false, 'state' => 'draft'];
        } catch (Throwable $error) {
            $this->db->endTransaction(false);
            // Повтор после конкурентной вставки не создаёт второй документ.
            $existing = $this->existing($command, $userId, $fingerprint);
            if ($existing !== null) {
                return $existing;
            }
            throw $error;
        }
    }

    /**
     * Пагинация по монотонному идентификатору: не читаем журнал целиком.
     *
     * @return array{items:list<array<string,mixed>>,next_cursor:?int}
     */
    public function listMine(int $userId, int $limit = 20, int $beforeId = 0): array
    {
        if ($userId < 1 || $limit < 1 || $limit > 50 || $beforeId < 0) {
            throw new InvalidArgumentException('Недопустимые параметры журнала');
        }
        $params = [':user' => $userId];
        $cursorSql = '';
        if ($beforeId > 0) {
            $cursorSql = ' AND id < :cursor';
            $params[':cursor'] = $beforeId;
        }
        $rows = $this->db->fetchAll(
            'SELECT id,kind,state,organization,subdivision,facility,organization_id,department_id,facility_id,
                    work_description,version,created_at
             FROM work_permit_documents
             WHERE created_by_user_id = :user AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 MONTH'
             . $cursorSql . ' ORDER BY id DESC LIMIT ' . ($limit + 1),
            $params
        );
        $hasNext = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return [
            'items' => $rows,
            'next_cursor' => $hasNext ? (int) $rows[array_key_last($rows)]['id'] : null,
        ];
    }

    /** @return array<string,mixed>|null */
    public function detail(int $documentId, int $userId): ?array
    {
        if ($documentId < 1 || $userId < 1) {
            return null;
        }
        return $this->db->fetchOne(
            'SELECT id,kind,state,organization,subdivision,facility,organization_id,department_id,facility_id,
                    work_description,version,created_at,updated_at
             FROM work_permit_documents
             WHERE id=:id AND created_by_user_id=:user LIMIT 1',
            [':id' => $documentId, ':user' => $userId]
        );
    }

    /** @return array{id:int,number:int,replayed:bool,state:string}|null */
    private function existing(string $command, int $userId, string $fingerprint): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id,created_by_user_id,creation_request_hash,state
             FROM work_permit_documents WHERE creation_command_id=:command LIMIT 1',
            [':command' => $command]
        );
        if ($row === null) {
            return null;
        }
        if ((int) $row['created_by_user_id'] !== $userId
            || !hash_equals((string) $row['creation_request_hash'], $fingerprint)) {
            throw new DomainException('Команда уже использована с другим содержимым', 409);
        }
        return [
            'id' => (int) $row['id'],
            'number' => (int) $row['id'],
            'replayed' => true,
            'state' => (string) $row['state'],
        ];
    }

    private function kind(mixed $value): string
    {
        if (!in_array($value, ['permit', 'order'], true)) {
            throw new InvalidArgumentException('Выберите наряд или распоряжение');
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private function field(array $data, string $name, int $max, bool $required = true): string
    {
        $value = $data[$name] ?? ($required ? null : '');
        if (!is_string($value)) {
            throw new InvalidArgumentException('Некорректное поле: ' . $name);
        }
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if (($required && $value === '') || $length > $max
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException('Недопустимое значение поля: ' . $name);
        }
        return $value;
    }
}
