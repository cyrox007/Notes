<?php

declare(strict_types=1);

namespace Modules\Defects\Services;

use Core\DatabaseManager;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * Административное исправление и архивирование дефектов.
 * Происхождение записи и учётные записи авторов неизменяемы.
 */
final class DefectAdministrationService
{
    private const array CORRECTABLE = [
        'object_id', 'description', 'priority', 'discovered_at',
        'due_at', 'registered_by_name', 'completed_work', 'closed_at',
    ];

    public function __construct(private readonly DatabaseManager $database)
    {
    }

    /** @param array<string,mixed> $fields
     * @return array{id:int,version:int,replayed:bool,changed:bool}
     */
    public function correct(
        int $id, int $version, int $actorId, array $fields, string $reason, string $commandId
    ): array {
        $this->assertCommand($id, $version, $actorId, $reason, $commandId);
        if ($fields === [] || count($fields) > count(self::CORRECTABLE)) {
            throw new InvalidArgumentException('Укажите поля для исправления');
        }
        $normalized = [];
        foreach ($fields as $name => $value) {
            if (!is_string($name) || !in_array($name, self::CORRECTABLE, true)) {
                throw new InvalidArgumentException('Это поле нельзя изменять административной правкой');
            }
            $normalized[$name] = $this->normalize($name, $value);
        }
        ksort($normalized, SORT_STRING);
        return $this->change($id, $version, $actorId, 'corrected', $normalized, trim($reason), $commandId);
    }

    /** @return array{id:int,version:int,replayed:bool,changed:bool} */
    public function archive(int $id, int $version, int $actorId, string $reason, string $commandId): array
    {
        $this->assertCommand($id, $version, $actorId, $reason, $commandId);
        return $this->change($id, $version, $actorId, 'archived', [], trim($reason), $commandId);
    }

    /** @return array{id:int,version:int,replayed:bool,changed:bool} */
    public function restore(int $id, int $version, int $actorId, string $reason, string $commandId): array
    {
        $this->assertCommand($id, $version, $actorId, $reason, $commandId);
        return $this->change($id, $version, $actorId, 'restored', [], trim($reason), $commandId);
    }

    /** @return array{id:int,version:int,replayed:bool,changed:bool} */
    public function reopen(int $id, int $version, int $actorId, string $reason, string $commandId): array
    {
        $this->assertCommand($id, $version, $actorId, $reason, $commandId);
        return $this->change($id, $version, $actorId, 'reopened', [], trim($reason), $commandId);
    }

    private function assertCommand(int $id, int $version, int $actorId, string $reason, string $commandId): void
    {
        if ($id < 1 || $version < 1 || $actorId < 1) {
            throw new InvalidArgumentException('Недопустимая запись, версия или автор');
        }
        if (mb_strlen(trim($reason)) < 5 || mb_strlen(trim($reason)) > 1000) {
            throw new InvalidArgumentException('Укажите причину исправления от 5 до 1000 символов');
        }
        if (preg_match('/^[A-Za-z0-9_-]{16,96}$/D', $commandId) !== 1) {
            throw new InvalidArgumentException('Недопустимый идентификатор операции');
        }
    }

    private function normalize(string $name, mixed $value): int|string
    {
        if ($name === 'object_id') {
            if (!is_int($value) || $value < 1) {
                throw new InvalidArgumentException('Неверный объект');
            }
            return $value;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Неверное значение поля ' . $name);
        }
        $value = trim($value);
        if ($name === 'priority') {
            DefectPriorityPolicy::days($value);
            return $value;
        }
        if (in_array($name, ['discovered_at', 'due_at', 'closed_at'], true)) {
            if (preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $value) !== 1) {
                throw new InvalidArgumentException('Дата должна содержать часовой пояс');
            }
            try {
                $date = new DateTimeImmutable($value);
            } catch (Throwable) {
                throw new InvalidArgumentException('Недопустимая дата');
            }
            if ($name === 'closed_at' && $date->getTimestamp() > time()) {
                throw new InvalidArgumentException('Дата закрытия не может быть в будущем');
            }
            return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $maximum = $name === 'description' || $name === 'completed_work' ? 10000 : 160;
        if ($value === '' || mb_strlen($value) > $maximum) {
            throw new InvalidArgumentException('Проверьте длину поля ' . $name);
        }
        return $value;
    }

    /** @param array<string,int|string> $fields
     * @return array{id:int,version:int,replayed:bool,changed:bool}
     */
    private function change(
        int $id, int $version, int $actorId, string $event, array $fields,
        string $reason, string $commandId
    ): array {
        $fingerprint = hash('sha256', json_encode(
            [$id, $version, $actorId, $event, $fields, $reason], JSON_THROW_ON_ERROR
        ));
        $replay = $this->replayed($id, $commandId, $fingerprint);
        if ($replay !== null) {
            return $replay;
        }

        $this->database->beginTransaction();
        try {
            $before = $this->database->fetchOne(
                'SELECT id,version,is_archived,status,object_id,description,priority,'
                . ' discovered_at,due_at,registered_by_name,completed_work,closed_at,closed_by_user_id'
                . ' FROM defects WHERE id=:id', ['id' => $id]
            );
            if ($before === null) {
                throw new DomainException('Дефект не найден', 404);
            }
            if ((int) $before['version'] !== $version) {
                throw new DomainException('Карточка изменена другим пользователем. Обновите её.', 409);
            }
            $archived = (int) $before['is_archived'] === 1;
            if (($event === 'archived' && $archived) || ($event === 'restored' && !$archived)
                || ($event === 'corrected' && $archived)
                || ($event === 'reopened' && ($archived || $before['status'] !== 'closed'))) {
                throw new DomainException('Состояние архива изменилось. Обновите карточку.', 409);
            }
            if ($event === 'corrected') {
                $this->assertCorrection($before, $fields);
            }

            $difference = [];
            foreach ($fields as $field => $value) {
                if ((string) ($before[$field] ?? '') !== (string) $value) {
                    $difference[$field] = ['before' => $before[$field], 'after' => $value];
                }
            }
            if ($event === 'corrected' && $difference === []) {
                $this->database->endTransaction();
                return ['id' => $id, 'version' => $version, 'replayed' => false, 'changed' => false];
            }

            $values = ['id' => $id, 'version' => $version];
            $assignments = [];
            if ($event === 'corrected') {
                foreach ($difference as $name => $change) {
                    $assignments[] = $name . '=:' . $name;
                    $values[$name] = $change['after'];
                }
            } elseif ($event === 'reopened') {
                // Доказательство первоначального закрытия остаётся в истории.
                // Активная карточка не должна выглядеть уже устранённой.
                $difference = [
                    'status' => ['before' => $before['status'], 'after' => 'open'],
                    'completed_work' => ['before' => $before['completed_work'], 'after' => null],
                    'closed_at' => ['before' => $before['closed_at'], 'after' => null],
                    'closed_by_user_id' => ['before' => $before['closed_by_user_id'], 'after' => null],
                ];
                $assignments[] = "status='open'";
                $assignments[] = 'completed_work=NULL';
                $assignments[] = 'closed_at=NULL';
                $assignments[] = 'closed_by_user_id=NULL';
                $assignments[] = 'close_command_id=NULL';
                $assignments[] = 'close_request_hash=NULL';
            } else {
                $assignments[] = 'is_archived=:archived';
                $assignments[] = 'archived_at=:archived_at';
                $values['archived'] = $event === 'archived' ? 1 : 0;
                $values['archived_at'] = $event === 'archived' ? gmdate('Y-m-d H:i:s') : null;
            }
            $updated = $this->database->execute(
                'UPDATE defects SET ' . implode(',', $assignments) . ',version=version+1'
                . ' WHERE id=:id AND version=:version AND is_archived=' . ($archived ? '1' : '0'),
                $values
            );
            if ($updated !== 1) {
                throw new DomainException('Карточка изменилась. Обновите её.', 409);
            }
            (new DefectHistoryService($this->database))->record(
                'defect', $id, $event, $actorId, [
                    'reason' => $reason,
                    'changes' => $difference,
                    'version_before' => $version,
                    'version_after' => $version + 1,
                    'request_hash' => $fingerprint,
                ], $commandId
            );
            $this->database->endTransaction();
            return ['id' => $id, 'version' => $version + 1, 'replayed' => false, 'changed' => true];
        } catch (Throwable $error) {
            $this->database->endTransaction(false);
            $replay = $this->replayed($id, $commandId, $fingerprint);
            if ($replay !== null) {
                return $replay;
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $before @param array<string,int|string> $fields */
    private function assertCorrection(array $before, array $fields): void
    {
        if (isset($fields['object_id'])) {
            $object = $this->database->fetchOne(
                'SELECT o.id FROM defect_objects o '
                . 'JOIN defect_object_categories c ON c.id=o.category_id '
                . 'JOIN defect_areas a ON a.id=c.area_id '
                . 'WHERE o.id=:id AND o.is_archived=0 AND c.is_archived=0 AND a.is_archived=0',
                ['id' => $fields['object_id']]
            );
            if ($object === null) {
                throw new InvalidArgumentException('Объект или его подразделение недоступны');
            }
        }
        if ($before['status'] !== 'closed'
            && (array_key_exists('completed_work', $fields) || array_key_exists('closed_at', $fields))) {
            throw new DomainException('Работы и дату закрытия можно корректировать только у закрытого дефекта', 409);
        }
        if (isset($fields['discovered_at'])
            && $fields['discovered_at'] > gmdate('Y-m-d H:i:s')) {
            throw new InvalidArgumentException('Обнаружение не может быть в будущем');
        }
    }

    /** @return array{id:int,version:int,replayed:bool,changed:bool}|null */
    private function replayed(int $id, string $commandId, string $fingerprint): ?array
    {
        $records = $this->database->fetchAll(
            "SELECT event_type,payload_json FROM defect_history"
            . " WHERE entity_type='defect' AND entity_id=:id AND command_id=:command_id",
            ['id' => $id, 'command_id' => $commandId]
        );
        if ($records === []) {
            return null;
        }
        $payload = json_decode((string) $records[0]['payload_json'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload)
            || !hash_equals((string) ($payload['request_hash'] ?? ''), $fingerprint)) {
            throw new DomainException('Идентификатор операции использован для других данных', 409);
        }
        return [
            'id' => $id, 'version' => (int) $payload['version_after'],
            'replayed' => true, 'changed' => false,
        ];
    }
}
