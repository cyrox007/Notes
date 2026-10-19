<?php

declare(strict_types=1);

namespace Modules\Defects\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * Реестр и жизненный цикл дефектов. Транзакции и запросы принадлежат ядру.
 */
final class DefectRegistryService
{
    public function __construct(private readonly DatabaseManager $database)
    {
    }

    /** @return list<array<string,mixed>> */
    public function list(int $limit = 40, int $afterId = 0, string $status = 'open', ?int $objectId = null): array
    {
        if ($limit < 1 || $limit > 100 || $afterId < 0) {
            throw new InvalidArgumentException('Недопустимые параметры страницы');
        }
        if (!in_array($status, ['open', 'closed', 'all'], true)) {
            throw new InvalidArgumentException('Недопустимое состояние дефекта');
        }
        if ($objectId !== null && $objectId < 1) {
            throw new InvalidArgumentException('Недопустимый объект');
        }

        $where = ['d.id > :after_id'];
        $params = ['after_id' => $afterId];
        if ($status !== 'all') {
            $where[] = 'd.status = :status';
            $params['status'] = $status;
        }
        if ($objectId !== null) {
            $where[] = 'd.object_id = :object_id';
            $params['object_id'] = $objectId;
        }

        return $this->database->fetchAll(
            'SELECT d.id,d.object_id,d.description,d.priority,d.discovered_at,d.due_at,
                    d.registered_by_name,d.status,d.version,d.closed_at,
                    o.name AS object_name,c.name AS category_name,a.name AS area_name
             FROM defects d
             JOIN defect_objects o ON o.id=d.object_id
             JOIN defect_object_categories c ON c.id=o.category_id
             JOIN defect_areas a ON a.id=c.area_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY d.id ASC LIMIT ' . $limit,
            $params
        );
    }

    /** @return array<string,mixed>|null */
    public function detail(int $id): ?array
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Неверный идентификатор дефекта');
        }
        $defect = $this->database->fetchOne(
            'SELECT id,object_id,description,priority,discovered_at,due_at,registered_by_name,
                    registered_by_user_id,status,completed_work,closed_at,closed_by_user_id,version
             FROM defects WHERE id=:id', ['id' => $id]
        );
        if ($defect === null) {
            return null;
        }
        $defect['services'] = $this->database->fetchAll(
            'SELECT s.id,s.name FROM defect_services s
             JOIN defect_responsible_services ds ON ds.service_id=s.id
             WHERE ds.defect_id=:id ORDER BY s.name,s.id',
            ['id' => $id]
        );
        return $defect;
    }

    /** @return array{id:int,version:int,replayed:bool} */
    public function close(int $id, int $expectedVersion, int $userId, string $work, string $commandId): array
    {
        if ($id < 1 || $expectedVersion < 1 || $userId < 1) {
            throw new InvalidArgumentException('Неверная запись, версия или пользователь');
        }
        $work = trim($work);
        if ($work === '' || mb_strlen($work) > 10000) {
            throw new InvalidArgumentException('Укажите выполненные работы');
        }
        if (preg_match('/^[A-Za-z0-9_-]{16,96}$/D', $commandId) !== 1) {
            throw new InvalidArgumentException('Недопустимый идентификатор команды');
        }
        $fingerprint = hash('sha256', json_encode(
            [$id, $expectedVersion, $userId, $work], JSON_THROW_ON_ERROR
        ));
        $existing = $this->closedCommand($commandId, $fingerprint);
        if ($existing !== null) {
            return $existing;
        }

        $this->database->beginTransaction();
        try {
            $affected = $this->database->execute(
                "UPDATE defects SET status='closed',completed_work=:work,
                    closed_at=UTC_TIMESTAMP(),closed_by_user_id=:user_id,
                    version=version+1,close_command_id=:command_id,close_request_hash=:hash
                 WHERE id=:id AND version=:version AND status='open'",
                [
                    'work' => $work, 'user_id' => $userId,
                    'command_id' => $commandId, 'hash' => $fingerprint,
                    'id' => $id, 'version' => $expectedVersion,
                ]
            );
            if ($affected !== 1) {
                throw new DomainException('Запись изменилась или уже закрыта', 409);
            }
            $this->database->endTransaction();
            return ['id' => $id, 'version' => $expectedVersion + 1, 'replayed' => false];
        } catch (Throwable $error) {
            $this->database->endTransaction(false);
            $existing = $this->closedCommand($commandId, $fingerprint);
            if ($existing !== null) {
                return $existing;
            }
            throw $error;
        }
    }

    /** @return array{id:int,version:int,replayed:bool}|null */
    private function closedCommand(string $commandId, string $hash): ?array
    {
        $row = $this->database->fetchOne(
            'SELECT id,version,close_request_hash FROM defects WHERE close_command_id=:command_id',
            ['command_id' => $commandId]
        );
        if ($row === null) {
            return null;
        }
        if (!hash_equals((string) $row['close_request_hash'], $hash)) {
            throw new DomainException('Идентификатор команды уже использован для других данных', 409);
        }
        return ['id' => (int) $row['id'], 'version' => (int) $row['version'], 'replayed' => true];
    }
}
