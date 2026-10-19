<?php

declare(strict_types=1);

namespace Core;

use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;

/**
 * Платформенный журнал идемпотентных изменяющих команд.
 *
 * Запись receipt и прикладное изменение должны выполняться в одной транзакции.
 * Тогда потерянный HTTP-ответ можно безопасно повторить с тем же command_id,
 * не выполняя действие второй раз.
 */
final class MutationCommandStore
{
    public const string STATUS_PROCESSING = 'processing';
    public const string STATUS_APPLIED = 'applied';
    public const string STATUS_CONFLICT = 'conflict';

    /**
     * @return array{owner:bool,token:string,record:array<string,mixed>}
     */
    public function acquire(
        PDO|DatabaseManager $pdo,
        string $actorKey,
        string $commandId,
        string $scope,
        string $action,
        string $entityType,
        string $entityUid,
        string $requestHash
    ): array {
        $actorKey = $this->identifier($actorKey, 96, 'actor_key');
        $commandId = $this->commandId($commandId);
        $scope = $this->identifier($scope, 64, 'scope');
        $action = $this->identifier($action, 96, 'action');
        $entityType = $this->identifier($entityType, 64, 'entity_type');
        $entityUid = $this->identifier($entityUid, 128, 'entity_uid');
        if (preg_match('/^[a-f0-9]{64}$/D', $requestHash) !== 1) {
            throw new InvalidArgumentException('Некорректный fingerprint изменяющей команды');
        }

        $token = bin2hex(random_bytes(16));
        $statement = $pdo->prepare(
            'INSERT INTO mutation_commands (
                actor_key,command_id,scope,action,entity_type,entity_uid,
                request_hash,operation_token,status,created_at
             ) VALUES (
                :actor_key,:command_id,:scope,:action,:entity_type,:entity_uid,
                :request_hash,:operation_token,:status,CURRENT_TIMESTAMP(6)
             )
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $statement->execute([
            ':actor_key' => $actorKey,
            ':command_id' => $commandId,
            ':scope' => $scope,
            ':action' => $action,
            ':entity_type' => $entityType,
            ':entity_uid' => $entityUid,
            ':request_hash' => $requestHash,
            ':operation_token' => $token,
            ':status' => self::STATUS_PROCESSING,
        ]);

        $record = $this->lockedRecord($pdo, $actorKey, $commandId);
        if ($record === null) {
            throw new RuntimeException('Не удалось зафиксировать изменяющую команду');
        }

        foreach ([
            'scope' => $scope,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_uid' => $entityUid,
            'request_hash' => $requestHash,
        ] as $field => $expected) {
            if ((string) ($record[$field] ?? '') !== $expected) {
                throw new InvalidArgumentException(
                    'command_id уже использован для другой изменяющей команды'
                );
            }
        }

        $owner = (string) ($record['operation_token'] ?? '') === $token
            && (string) ($record['status'] ?? '') === self::STATUS_PROCESSING;
        if (!$owner && (string) ($record['status'] ?? '') === self::STATUS_PROCESSING) {
            throw new RuntimeException(
                'Предыдущая попытка этой команды не завершена; автоматический повтор запрещён'
            );
        }

        return ['owner' => $owner, 'token' => $token, 'record' => $record];
    }

    /**
     * @param array<string,mixed> $response
     */
    public function complete(
        PDO|DatabaseManager $pdo,
        int $recordId,
        string $operationToken,
        string $status,
        array $response,
        ?int $resultVersion
    ): void {
        if (!in_array($status, [self::STATUS_APPLIED, self::STATUS_CONFLICT], true)) {
            throw new InvalidArgumentException('Некорректный итог изменяющей команды');
        }

        try {
            $responseJson = json_encode(
                $response,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Не удалось сохранить результат изменяющей команды', 0, $exception);
        }

        $statement = $pdo->prepare(
            'UPDATE mutation_commands
             SET status=:status,response_json=:response_json,result_version=:result_version,
                 completed_at=CURRENT_TIMESTAMP(6)
             WHERE id=:id AND operation_token=:operation_token AND status=:processing'
        );
        $statement->execute([
            ':status' => $status,
            ':response_json' => $responseJson,
            ':result_version' => $resultVersion,
            ':id' => $recordId,
            ':operation_token' => $operationToken,
            ':processing' => self::STATUS_PROCESSING,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Изменяющая команда потеряла владение транзакцией');
        }
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    public function storedResponse(array $record): array
    {
        $json = (string) ($record['response_json'] ?? '');
        if ($json === '') {
            throw new RuntimeException('У завершённой команды отсутствует сохранённый результат');
        }

        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Сохранённый результат команды повреждён', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Сохранённый результат команды имеет неверный формат');
        }

        $decoded['replayed'] = true;
        return $decoded;
    }

    /** @param array<string,mixed> $payload */
    public function requestHash(array $payload): string
    {
        $canonical = $this->canonicalize($payload);
        try {
            return hash(
                'sha256',
                json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Команда содержит неподдерживаемые данные', 0, $exception);
        }
    }

    /** @return array<string,mixed>|null */
    private function lockedRecord(PDO|DatabaseManager $pdo, string $actorKey, string $commandId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id,actor_key,command_id,scope,action,entity_type,entity_uid,
                    request_hash,operation_token,status,response_json,result_version
             FROM mutation_commands
             WHERE actor_key=:actor_key AND command_id=:command_id
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute([':actor_key' => $actorKey, ':command_id' => $commandId]);
        $record = $statement->fetch(PDO::FETCH_ASSOC);
        return $record === false ? null : $record;
    }

    private function commandId(string $value): string
    {
        $value = trim($value);
        if (strlen($value) < 8 || strlen($value) > 96
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException('Некорректный command_id');
        }
        return $value;
    }

    private function identifier(string $value, int $maxLength, string $label): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException('Некорректный ' . $label);
        }
        return $value;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
