<?php

declare(strict_types=1);

namespace Modules\Defects\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * Атомарное назначение ответственных служб и их исполнителей.
 * Версия карточки защищает от параллельного перезаписывания решений.
 */
final class DefectServiceAssignmentService
{
    public function __construct(private readonly DatabaseManager $database)
    {
    }

    /**
     * @param list<array{service_id:int,executor_name:?string}> $assignments
     * @return array{id:int,version:int,replayed:bool}
     */
    public function replace(int $id, int $version, int $actorId, array $assignments, string $commandId): array
    {
        if ($id < 1 || $version < 1 || $actorId < 1) {
            throw new InvalidArgumentException('Неверный дефект, версия или пользователь');
        }
        if (preg_match('/^[A-Za-z0-9_-]{16,96}$/D', $commandId) !== 1) {
            throw new InvalidArgumentException('Недопустимый идентификатор операции');
        }

        $requested = $this->normalize($assignments);
        $fingerprint = hash('sha256', json_encode(
            [$id, $version, $actorId, $requested], JSON_THROW_ON_ERROR
        ));
        $previous = $this->replayedCommand($id, $commandId, $fingerprint);
        if ($previous !== null) {
            return $previous;
        }

        $this->database->beginTransaction();
        try {
            $current = $this->database->fetchOne(
                'SELECT id,version,status FROM defects WHERE id=:id', ['id' => $id]
            );
            if ($current === null) {
                throw new DomainException('Дефект не найден', 404);
            }
            if ($current['status'] !== 'open' || (int) $current['version'] !== $version) {
                throw new DomainException('Карточка изменена или закрыта. Обновите её.', 409);
            }

            $this->assertActiveServices($requested);
            $previousServices = $this->database->fetchAll(
                'SELECT service_id,executor_name FROM defect_responsible_services'
                . ' WHERE defect_id=:id ORDER BY service_id', ['id' => $id]
            );
            $changed = $this->database->execute(
                "UPDATE defects SET version=version+1 WHERE id=:id AND version=:version AND status='open'",
                ['id' => $id, 'version' => $version]
            );
            if ($changed !== 1) {
                throw new DomainException('Карточка изменена другим пользователем. Обновите её.', 409);
            }

            $this->database->execute(
                'DELETE FROM defect_responsible_services WHERE defect_id=:id', ['id' => $id]
            );
            foreach ($requested as $item) {
                $this->database->execute(
                    'INSERT INTO defect_responsible_services (defect_id,service_id,executor_name)'
                    . ' VALUES (:defect_id,:service_id,:executor_name)',
                    [
                        'defect_id' => $id,
                        'service_id' => $item['service_id'],
                        'executor_name' => $item['executor_name'],
                    ]
                );
            }

            (new DefectHistoryService($this->database))->record(
                'defect', $id, 'updated', $actorId, [
                    'action' => 'responsible_services',
                    'version_before' => $version,
                    'version_after' => $version + 1,
                    'before' => $previousServices,
                    'after' => $requested,
                    'request_hash' => $fingerprint,
                ], $commandId
            );
            $this->database->endTransaction();
            return ['id' => $id, 'version' => $version + 1, 'replayed' => false];
        } catch (Throwable $error) {
            $this->database->endTransaction(false);
            $previous = $this->replayedCommand($id, $commandId, $fingerprint);
            if ($previous !== null) {
                return $previous;
            }
            throw $error;
        }
    }

    /**
     * @param list<array{service_id:int,executor_name:?string}> $assignments
     * @return list<array{service_id:int,executor_name:?string}>
     */
    private function normalize(array $assignments): array
    {
        if ($assignments === [] || count($assignments) > 20) {
            throw new InvalidArgumentException('Укажите от одной до двадцати служб');
        }

        $normalized = [];
        foreach ($assignments as $item) {
            $id = $item['service_id'] ?? null;
            $name = $item['executor_name'] ?? null;
            if (!is_int($id) || $id < 1 || ($name !== null && !is_string($name))) {
                throw new InvalidArgumentException('Неверная служба или исполнитель');
            }
            $name = is_string($name) ? trim($name) : null;
            if ($name === '') {
                $name = null;
            }
            if ($name !== null && mb_strlen($name) > 160) {
                throw new InvalidArgumentException('ФИО исполнителя длиннее 160 символов');
            }
            if (isset($normalized[$id])) {
                throw new InvalidArgumentException('Служба указана дважды');
            }
            $normalized[$id] = ['service_id' => $id, 'executor_name' => $name];
        }
        ksort($normalized, SORT_NUMERIC);
        return array_values($normalized);
    }

    /** @param list<array{service_id:int,executor_name:?string}> $assignments */
    private function assertActiveServices(array $assignments): void
    {
        foreach ($assignments as $item) {
            $service = $this->database->fetchOne(
                'SELECT id FROM defect_services WHERE id=:id AND is_archived=0',
                ['id' => $item['service_id']]
            );
            if ($service === null) {
                throw new InvalidArgumentException('Ответственная служба отсутствует или архивирована');
            }
        }
    }

    /** @return array{id:int,version:int,replayed:bool}|null */
    private function replayedCommand(int $id, string $commandId, string $fingerprint): ?array
    {
        $row = $this->database->fetchOne(
            "SELECT payload_json FROM defect_history WHERE entity_type='defect'"
            . " AND entity_id=:id AND event_type='updated' AND command_id=:command_id",
            ['id' => $id, 'command_id' => $commandId]
        );
        if ($row === null) {
            return null;
        }
        $details = json_decode((string) $row['payload_json'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($details) || !hash_equals((string) ($details['request_hash'] ?? ''), $fingerprint)) {
            throw new DomainException('Идентификатор операции уже применён к другим данным', 409);
        }
        return ['id' => $id, 'version' => (int) $details['version_after'], 'replayed' => true];
    }
}
