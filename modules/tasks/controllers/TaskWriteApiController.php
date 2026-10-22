<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PermissionService;
use App\Services\Tasks\TaskVersionMutationService;
use Core\DatabaseManager;
use Core\Request;
use DomainException;
use InvalidArgumentException;
use Modules\Tasks\TaskApiCreateService;
use Modules\Tasks\TaskApiTokenService;
use RuntimeException;

/**
 * Изменяющий внешний API требует отдельный ключ tasks.write.
 * Сессионные запросы сюда не допускаются, чтобы не обходить CSRF.
 */
final class TaskWriteApiController
{
    public function create(Request $request): void
    {
        $owner = $this->authenticate($request);
        if ($owner === null) {
            return;
        }
        $data = $request->rawJson();
        if (!is_array($data) || array_is_list($data)) {
            $this->send(['error' => 'invalid_payload'], 422);
            return;
        }
        try {
            $command = $this->commandId($data);
            unset($data['command_id']);
            $result = (new TaskApiCreateService(DatabaseManager::getInstance()))
                ->create($owner, $command, $data);
            $this->send($result, !empty($result['replayed']) ? 200 : 201);
        } catch (InvalidArgumentException $error) {
            $this->send(['error' => 'invalid_command', 'message' => $error->getMessage()], 422);
        } catch (DomainException $error) {
            $this->send(['error' => 'policy_denied', 'message' => $error->getMessage()], 403);
        }
    }

    public function update(Request $request, string $uid): void
    {
        $owner = $this->authenticate($request);
        if ($owner === null) {
            return;
        }
        $data = $request->rawJson();
        if (!is_array($data) || array_is_list($data)) {
            $this->send(['error' => 'invalid_payload'], 422);
            return;
        }
        try {
            $command = $this->commandId($data);
            $version = $data['expected_version'] ?? null;
            if (!is_int($version) || $version < 1) {
                throw new InvalidArgumentException('Требуется положительная expected_version');
            }
            unset($data['command_id'], $data['expected_version']);
            $allowed = ['title', 'description', 'priority', 'status', 'due_date'];
            if ($data === [] || array_diff(array_keys($data), $allowed) !== []) {
                throw new InvalidArgumentException('Недопустимые изменяемые поля');
            }
            $changes = $this->changes($data);
            $result = (new TaskVersionMutationService(DatabaseManager::getInstance()))
                ->mutateOwned($owner, $uid, $version, $command, 'task.api.update', $changes);
            $this->send($result, ($result['success'] ?? false) ? 200 : 409);
        } catch (InvalidArgumentException $error) {
            $this->send(['error' => 'invalid_command', 'message' => $error->getMessage()], 422);
        } catch (RuntimeException $error) {
            if (str_contains($error->getMessage(), 'не найдена или недоступна')) {
                $this->send(['error' => 'not_found'], 404);
                return;
            }
            throw $error;
        }
    }

    private function authenticate(Request $request): ?int
    {
        $authorization = $request->server('HTTP_AUTHORIZATION', '');
        $db = DatabaseManager::getInstance();
        $principal = is_string($authorization)
            ? (new TaskApiTokenService($db))->authenticate(trim($authorization), TaskApiTokenService::SCOPE_WRITE)
            : null;
        if ($principal === null) {
            $this->send(['error' => 'invalid_token'], 401);
            return null;
        }
        $owner = (int) $principal['user_id'];
        if (!(new PermissionService($db))->hasPermission($owner, 'tasks.use')) {
            $this->send(['error' => 'forbidden'], 403);
            return null;
        }
        return $owner;
    }

    /** @param array<string,mixed> $data */
    private function commandId(array $data): string
    {
        $value = $data['command_id'] ?? null;
        if (!is_string($value) || strlen($value) < 8 || strlen($value) > 96
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException('Некорректный command_id');
        }
        return $value;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function changes(array $input): array
    {
        $changes = [];
        foreach ($input as $key => $value) {
            if ($key === 'title' && (!is_string($value) || trim($value) === '' || mb_strlen($value) > 255)) {
                throw new InvalidArgumentException('Некорректное название');
            }
            if ($key === 'description' && ($value !== null && (!is_string($value) || mb_strlen($value) > 10000))) {
                throw new InvalidArgumentException('Некорректное описание');
            }
            if ($key === 'priority' && !in_array($value, ['low', 'medium', 'high', 'urgent'], true)) {
                throw new InvalidArgumentException('Некорректный приоритет');
            }
            if ($key === 'status' && !in_array($value, ['pending','studying','in_progress','review','completed'], true)) {
                throw new InvalidArgumentException('Некорректный статус');
            }
            if ($key === 'due_date' && $value !== null
                && (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1)) {
                throw new InvalidArgumentException('Некорректный срок');
            }
            $changes[$key] = $key === 'title' ? trim($value) : $value;
        }
        if (isset($changes['status'])) {
            $changes['completed_at'] = $changes['status'] === 'completed' ? gmdate('Y-m-d H:i:s') : null;
        }
        return $changes;
    }

    /** @param array<string,mixed> $body */
    private function send(array $body, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
