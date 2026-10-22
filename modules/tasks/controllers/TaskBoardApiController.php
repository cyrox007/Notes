<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PermissionService;
use App\Services\TaskBoardService;
use App\Services\Tasks\TaskBoardVersionMutationService;
use Core\DatabaseManager;
use Core\Request;
use DomainException;
use InvalidArgumentException;
use Modules\Tasks\TaskApiTokenService;
use Modules\Tasks\TaskBoardApiCreateService;
use Modules\Tasks\TaskBoardMemberCommandService;
use Modules\Tasks\TaskBoardAcceptanceService;
use Modules\Tasks\TaskApiTrafficService;
use RuntimeException;

/**
 * Внешний API общих досок использует тот же ACL и версионные команды, что и интерфейс.
 * Сессионная авторизация здесь намеренно не применяется.
 */
final class TaskBoardApiController
{
    /** @var array{owner:int,token:int,action:string}|null */
    private ?array $principal = null;

    public function boards(Request $request): void
    {
        $owner = $this->authorize($request, TaskApiTokenService::SCOPE_READ, 'read');
        if ($owner === null) {
            return;
        }

        $boards = (new TaskBoardService(DatabaseManager::getInstance()))->listBoards($owner);
        $items = array_map(static fn (array $board): array => [
            'uid' => (string) $board['uid'],
            'name' => (string) $board['name'],
            'audience' => (string) $board['audience'],
            'access_role' => (string) $board['access_role'],
            'member_count' => (int) $board['member_count'],
            'task_count' => (int) $board['task_count'],
            'updated_at' => (string) $board['updated_at'],
        ], $boards);
        $this->respond(['api_version' => 'v1', 'items' => $items], 200);
    }

    public function tasks(Request $request, string $boardUid): void
    {
        $owner = $this->authorize($request, TaskApiTokenService::SCOPE_READ, 'read');
        if ($owner === null) {
            return;
        }
        try {
            $rawLimit = $request->get('limit', '30');
            $rawAfter = $request->get('after_id', '0');
            if (!is_string($rawLimit) || !preg_match('/^[1-9][0-9]{0,2}$/D', $rawLimit)
                || (int) $rawLimit > 100 || !is_string($rawAfter)
                || preg_match('/^(0|[1-9][0-9]{0,17})$/D', $rawAfter) !== 1) {
                throw new InvalidArgumentException('Некорректные параметры списка');
            }
            $service = new TaskBoardService(DatabaseManager::getInstance());
            $board = $service->boardForUser($owner, $boardUid);
            $limit = (int) $rawLimit;
            $rows = DatabaseManager::getInstance()->fetchAll(
                'SELECT id,uid,title,description,status,priority,due_date,completed_at,
                        archived_at,version,updated_at
                 FROM task_board_items
                 WHERE board_id=:board_id AND is_deleted=0 AND id>:after_id
                 ORDER BY id ASC LIMIT ' . ($limit + 1),
                [':board_id' => (int) $board['id'], ':after_id' => (int) $rawAfter]
            );
            $hasMore = count($rows) > $limit;
            $page = array_slice($rows, 0, $limit);
            $cursor = $hasMore ? (int) $page[array_key_last($page)]['id'] : null;
            $items = array_map(static function (array $row): array {
                unset($row['id']);
                $row['version'] = (int) $row['version'];
                return $row;
            }, $page);
            $this->respond([
                'api_version' => 'v1',
                'board_uid' => (string) $board['uid'],
                'access_role' => (string) $board['access_role'],
                'items' => $items,
                'has_more' => $hasMore,
                'next_after_id' => $cursor,
                'limit' => $limit,
            ], 200);
        } catch (InvalidArgumentException $error) {
            $this->respond(['error' => 'invalid_parameter', 'message' => $error->getMessage()], 422);
        } catch (DomainException $error) {
            $this->respond(['error' => 'not_found'], 404);
        }
    }

    public function changeMember(Request $request, string $boardUid, int $userId): void
    {
        $owner = $this->authorize($request, TaskApiTokenService::SCOPE_WRITE, 'update');
        if ($owner === null) {
            return;
        }
        try {
            $input = $request->rawJson();
            if (!is_array($input) || array_is_list($input)
                || array_diff(array_keys($input), ['command_id', 'expected_role', 'role']) !== []
                || !array_key_exists('expected_role', $input)
                || !array_key_exists('role', $input)) {
                throw new InvalidArgumentException('Некорректная команда изменения участника');
            }
            $command = $input['command_id'] ?? null;
            if (!is_string($command)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/D', $command) !== 1) {
                throw new InvalidArgumentException('Некорректный command_id');
            }
            $result = (new TaskBoardMemberCommandService(DatabaseManager::getInstance()))
                ->change($owner, $boardUid, $userId, $command, $input['expected_role'], $input['role']);
            $this->respond($result, ($result['success'] ?? false) ? 200 : 409);
        } catch (InvalidArgumentException $error) {
            $this->respond(['error' => 'invalid_command', 'message' => $error->getMessage()], 422);
        } catch (DomainException $error) {
            $code = (int) $error->getCode() === 403 ? 403 : 404;
            $this->respond(['error' => $code === 403 ? 'forbidden' : 'not_found'], $code);
        }
    }

    public function create(Request $request, string $boardUid): void
    {
        $owner = $this->authorize($request, TaskApiTokenService::SCOPE_WRITE, 'create');
        if ($owner === null) {
            return;
        }
        try {
            $input = $request->rawJson();
            if (!is_array($input) || array_is_list($input)) {
                throw new InvalidArgumentException('Ожидается объект JSON');
            }
            $command = $input['command_id'] ?? null;
            if (!is_string($command)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/D', $command) !== 1) {
                throw new InvalidArgumentException('Некорректный command_id');
            }
            unset($input['command_id']);
            $result = (new TaskBoardApiCreateService(DatabaseManager::getInstance()))
                ->create($owner, $boardUid, $command, $input);
            $this->respond($result, !empty($result['replayed']) ? 200 : 201);
        } catch (InvalidArgumentException $error) {
            $this->respond(['error' => 'invalid_command', 'message' => $error->getMessage()], 422);
        } catch (DomainException $error) {
            $status = (int) $error->getCode() === 403 ? 403 : 404;
            $this->respond(['error' => $status === 403 ? 'forbidden' : 'not_found'], $status);
        }
    }

    public function accept(Request $request, string $taskUid): void
    {
        $owner = $this->authorize($request, TaskApiTokenService::SCOPE_WRITE, 'update');
        if ($owner === null) {
            return;
        }
        try {
            $input = $request->rawJson();
            if (!is_array($input) || array_is_list($input)
                || array_diff(array_keys($input), ['command_id', 'expected_version']) !== []
                || !is_string($input['command_id'] ?? null)
                || !is_int($input['expected_version'] ?? null)) {
                throw new InvalidArgumentException('Требуются command_id и expected_version');
            }
            $result = (new TaskBoardAcceptanceService(DatabaseManager::getInstance()))
                ->accept($owner, $taskUid, $input['expected_version'], $input['command_id']);
            $this->respond($result, ($result['success'] ?? false) ? 200 : 409);
        } catch (InvalidArgumentException $error) {
            $this->respond(['error' => 'invalid_command', 'message' => $error->getMessage()], 422);
        } catch (DomainException $error) {
            $status = in_array((int) $error->getCode(), [403, 404, 409], true)
                ? (int) $error->getCode() : 403;
            $this->respond([
                'error' => $status === 403 ? 'forbidden' : ($status === 409 ? 'state_conflict' : 'not_found'),
            ], $status);
        }
    }

    public function update(Request $request, string $taskUid): void
    {
        $owner = $this->authorize($request, TaskApiTokenService::SCOPE_WRITE, 'update');
        if ($owner === null) {
            return;
        }

        try {
            $input = $request->rawJson();
            if (!is_array($input) || array_is_list($input)) {
                throw new InvalidArgumentException('Ожидается объект JSON');
            }
            $command = $input['command_id'] ?? null;
            $version = $input['expected_version'] ?? null;
            if (!is_string($command) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/D', $command) !== 1
                || !is_int($version) || $version < 1) {
                throw new InvalidArgumentException('Укажите command_id и expected_version');
            }
            unset($input['command_id'], $input['expected_version']);
            if ($input === []) {
                throw new InvalidArgumentException('Не переданы изменения');
            }

            $result = (new TaskBoardVersionMutationService(DatabaseManager::getInstance()))
                ->mutate($owner, $taskUid, $version, $command, 'task.api.board.update', $input);
            $this->respond($result, ($result['success'] ?? false) ? 200 : 409);
        } catch (InvalidArgumentException $error) {
            $this->respond(['error' => 'invalid_command', 'message' => $error->getMessage()], 422);
        } catch (DomainException $error) {
            $status = (int) $error->getCode() === 403 ? 403 : 404;
            $this->respond(['error' => $status === 403 ? 'forbidden' : 'not_found'], $status);
        }
    }

    private function authorize(Request $request, string $scope, string $action): ?int
    {
        $db = DatabaseManager::getInstance();
        $header = $request->server('HTTP_AUTHORIZATION', '');
        $token = is_string($header)
            ? (new TaskApiTokenService($db))->authenticate(trim($header), $scope)
            : null;
        if ($token === null) {
            $this->respond(['error' => 'invalid_token'], 401);
            return null;
        }
        $owner = (int) $token['user_id'];
        $this->principal = ['owner' => $owner, 'token' => (int) $token['token_id'], 'action' => $action];

        if (!(new PermissionService($db))->hasPermission($owner, 'tasks.use')) {
            $this->respond(['error' => 'forbidden'], 403);
            return null;
        }
        $limit = (new TaskApiTrafficService($db))->consume((int) $token['token_id'], $action);
        if (!$limit['allowed']) {
            header('Retry-After: ' . $limit['retry_after']);
            $this->respond(['error' => 'rate_limited'], 429);
            return null;
        }
        return $owner;
    }

    /** @param array<string,mixed> $data */
    private function respond(array $data, int $status): void
    {
        if ($this->principal !== null) {
            $p = $this->principal;
            (new TaskApiTrafficService(DatabaseManager::getInstance()))
                ->record($p['token'], $p['owner'], $p['action'], $status);
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
