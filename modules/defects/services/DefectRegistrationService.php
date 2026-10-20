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
 * Регистрация дефектов: одна транзакция для карточки и ответственных служб.
 *
 * Права проверяет платформенный входной маршрут. Соединения, запросы
 * и транзакции предоставляет исключительно DatabaseManager ядра.
 */
final class DefectRegistrationService
{
    public function __construct(private readonly DatabaseManager $database)
    {
    }

    /**
     * @param list<int> $serviceIds
     * @return array{id:int,due_at:string,replayed:bool}
     */
    public function register(
        int $objectId,
        string $description,
        string $priority,
        DateTimeImmutable $discoveredAt,
        int $userId,
        string $registrarName,
        array $serviceIds,
        string $commandId
    ): array {
        $this->assertInputs($objectId, $description, $userId, $registrarName, $commandId);
        $serviceIds = $this->normalizeServiceIds($serviceIds);
        $description = trim($description);
        $registrarName = trim($registrarName);
        $discoveredUtc = $discoveredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $dueUtc = DefectPriorityPolicy::deadline($discoveredAt, $priority)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $fingerprint = hash('sha256', json_encode([
            $objectId, $description, $priority, $discoveredUtc,
            $userId, $registrarName, $serviceIds,
        ], JSON_THROW_ON_ERROR));

        $existing = $this->existingCommand($commandId, $fingerprint);
        if ($existing !== null) {
            return $existing;
        }

        $this->database->beginTransaction();
        try {
            $this->assertActiveReferences($objectId, $serviceIds);
            $this->database->execute(
                'INSERT INTO defects
                    (object_id, description, priority, discovered_at, due_at,
                     registered_by_name, registered_by_user_id, creation_command_id, creation_request_hash)
                 VALUES
                    (:object_id, :description, :priority, :discovered_at, :due_at,
                     :registered_by_name, :registered_by_user_id, :command_id, :request_hash)',
                [
                    'object_id' => $objectId,
                    'description' => $description,
                    'priority' => $priority,
                    'discovered_at' => $discoveredUtc,
                    'due_at' => $dueUtc,
                    'registered_by_name' => $registrarName,
                    'registered_by_user_id' => $userId,
                    'command_id' => $commandId,
                    'request_hash' => $fingerprint,
                ]
            );

            $defectId = (int) $this->database->lastInsertId();
            if ($defectId < 1) {
                throw new DomainException('Не удалось получить идентификатор дефекта');
            }

            foreach ($serviceIds as $serviceId) {
                $this->database->execute(
                    'INSERT INTO defect_responsible_services (defect_id, service_id)
                     VALUES (:defect_id, :service_id)',
                    ['defect_id' => $defectId, 'service_id' => $serviceId]
                );
            }

            (new DefectHistoryService($this->database))->record('defect', $defectId, 'created', $userId, [
                'object_id' => $objectId,
                'priority' => $priority,
                'discovered_at' => $discoveredUtc,
                'due_at' => $dueUtc,
                'description' => $description,
                'registered_by_name' => $registrarName,
                'service_ids' => $serviceIds,
            ], $commandId);

            $this->database->endTransaction();
            return ['id' => $defectId, 'due_at' => $dueUtc, 'replayed' => false];
        } catch (Throwable $exception) {
            $this->database->endTransaction(false);

            // После конкурентной вставки с тем же идентификатором команды
            // проверяем сохранённое содержимое, а не создаём вторую карточку.
            $existing = $this->existingCommand($commandId, $fingerprint);
            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function assertInputs(
        int $objectId,
        string $description,
        int $userId,
        string $registrarName,
        string $commandId
    ): void {
        if ($objectId < 1 || $userId < 1) {
            throw new InvalidArgumentException('Неверный объект или пользователь');
        }
        if (trim($description) === '' || mb_strlen(trim($description)) > 10000) {
            throw new InvalidArgumentException('Описание дефекта должно содержать от 1 до 10000 символов');
        }
        if (trim($registrarName) === '' || mb_strlen(trim($registrarName)) > 160) {
            throw new InvalidArgumentException('Необходимо указать ФИО регистрирующего');
        }
        if (preg_match('/^[A-Za-z0-9_-]{16,96}$/D', $commandId) !== 1) {
            throw new InvalidArgumentException('Недопустимый идентификатор команды');
        }
    }

    /** @param list<int> $serviceIds
     * @return list<int>
     */
    private function normalizeServiceIds(array $serviceIds): array
    {
        if ($serviceIds === [] || count($serviceIds) > 20) {
            throw new InvalidArgumentException('Укажите от одной до двадцати ответственных служб');
        }

        foreach ($serviceIds as $serviceId) {
            if (!is_int($serviceId) || $serviceId < 1) {
                throw new InvalidArgumentException('Неверный идентификатор ответственной службы');
            }
        }

        $unique = array_values(array_unique($serviceIds));
        sort($unique, SORT_NUMERIC);
        return $unique;
    }

    /** @param list<int> $serviceIds */
    private function assertActiveReferences(int $objectId, array $serviceIds): void
    {
        $object = $this->database->fetchOne(
            'SELECT o.id FROM defect_objects o
             JOIN defect_object_categories c ON c.id = o.category_id
             JOIN defect_areas a ON a.id = c.area_id
             WHERE o.id = :object_id
                AND o.is_archived = 0 AND c.is_archived = 0 AND a.is_archived = 0',
            ['object_id' => $objectId]
        );
        if ($object === null) {
            throw new InvalidArgumentException('Объект или его подразделение недоступны');
        }

        $parameters = [];
        $placeholders = [];
        foreach ($serviceIds as $index => $serviceId) {
            $name = 'service_' . $index;
            $parameters[$name] = $serviceId;
            $placeholders[] = ':' . $name;
        }

        $available = $this->database->fetchAll(
            'SELECT id FROM defect_services WHERE is_archived = 0 AND id IN ('
                . implode(', ', $placeholders) . ')',
            $parameters
        );
        if (count($available) !== count($serviceIds)) {
            throw new InvalidArgumentException('Одна из ответственных служб недоступна');
        }
    }

    /** @return array{id:int,due_at:string,replayed:bool}|null */
    private function existingCommand(string $commandId, string $fingerprint): ?array
    {
        $record = $this->database->fetchOne(
            'SELECT id, due_at, creation_request_hash FROM defects
             WHERE creation_command_id = :command_id',
            ['command_id' => $commandId]
        );
        if ($record === null) {
            return null;
        }
        if (!hash_equals((string) $record['creation_request_hash'], $fingerprint)) {
            throw new DomainException('Идентификатор команды уже использован для других данных');
        }

        return [
            'id' => (int) $record['id'],
            'due_at' => (string) $record['due_at'],
            'replayed' => true,
        ];
    }
}
