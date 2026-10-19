<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use App\Services\TaskBoardService;
use Core\Controller;
use Core\Request;
use Core\Router;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class TaskBoardController extends Controller
{
    public function index(Request $request): void
    {
        $userId = (int) $request->session('user_id', 0);
        $user = $userId > 0 ? UserModel::select()->where('id', '=', $userId)->first() : null;
        if (!$user) {
            http_response_code(401);
            return;
        }

        $service = new TaskBoardService();
        $selected = null;
        $tasks = [];
        $members = [];
        $stats = ['pending' => 0, 'in_progress' => 0, 'studying' => 0, 'review' => 0, 'completed' => 0];
        $boardUid = trim((string) $request->get('board', ''));
        $flash = $request->session('task_boards_flash');
        $request->unsetSession('task_boards_flash');

        try {
            $boards = $service->listBoards($userId);
            $policies = $service->policySnapshot($userId);
            $activeUsers = $service->activeUsers();
            if ($boardUid !== '') {
                $selected = $service->boardForUser($userId, $boardUid);
                $tasks = $service->listTasks($userId, $boardUid);
                $members = $service->members($userId, $boardUid);
                foreach ($tasks as $task) {
                    $status = (string) ($task['status'] ?? '');
                    if (empty($task['archived_at']) && array_key_exists($status, $stats)) {
                        $stats[$status]++;
                    }
                }
            }
        } catch (DomainException $e) {
            if ((int) $e->getCode() === 404 && $boardUid !== '') {
                $request->setSession('task_boards_flash', ['type' => 'error', 'message' => $e->getMessage()]);
                Router::getInstance()->redirect('task_boards', 'name');
                return;
            }
            http_response_code($this->status($e, 403));
            echo $e->getMessage();
            return;
        } catch (Throwable $e) {
            error_log('Task boards page failed: ' . $e->getMessage());
            http_response_code(500);
            echo 'Не удалось открыть общие доски';
            return;
        }

        $this->render_template('@tasks/boards', [
            'user' => $user,
            'boards' => $boards,
            'selectedBoard' => $selected,
            'boardTasks' => $tasks,
            'boardMembers' => $members,
            'activeUsers' => $activeUsers,
            'boardPolicies' => $policies,
            'boardStats' => $stats,
            'task_boards_flash' => is_array($flash) ? $flash : null,
        ]);
    }

    public function createBoard(Request $request): void
    {
        try {
            $userId = $this->userId($request);
            $members = $request->post('member_ids', []);
            $uid = (new TaskBoardService())->createBoard(
                $userId,
                (string) $request->post('name', ''),
                (string) $request->post('audience', 'members'),
                is_array($members) ? $members : []
            );
            $this->flash($request, 'success', 'Общая доска создана');
            $this->redirectBoard($uid);
        } catch (InvalidArgumentException|DomainException $e) {
            $this->fail($request, $e, 'Не удалось создать доску');
        } catch (Throwable $e) {
            error_log('Task board creation failed: ' . $e->getMessage());
            $this->failMessage($request, 'Не удалось создать доску', 500);
        }
    }

    public function saveMembers(Request $request, string $uid): void
    {
        try {
            $members = $request->post('member_ids', []);
            (new TaskBoardService())->replaceMembers(
                $this->userId($request),
                $uid,
                is_array($members) ? $members : []
            );
            $this->flash($request, 'success', 'Состав доски обновлён');
            $this->redirectBoard($uid);
        } catch (InvalidArgumentException|DomainException $e) {
            $this->fail($request, $e, 'Не удалось изменить состав доски', $uid);
        } catch (Throwable $e) {
            error_log('Task board members update failed: ' . $e->getMessage());
            $this->failMessage($request, 'Не удалось изменить состав доски', 500, $uid);
        }
    }

    public function createTask(Request $request, string $uid): void
    {
        try {
            $assignees = $request->post('assignee_ids', []);
            (new TaskBoardService())->createTask(
                $this->userId($request),
                $uid,
                (string) $request->post('title', ''),
                (string) $request->post('description', ''),
                (string) $request->post('status', 'pending'),
                (string) $request->post('priority', 'medium'),
                trim((string) $request->post('due_date', '')) ?: null,
                is_array($assignees) ? $assignees : []
            );
            $this->flash($request, 'success', 'Задача добавлена на доску');
            $this->redirectBoard($uid);
        } catch (InvalidArgumentException|DomainException $e) {
            $this->fail($request, $e, 'Не удалось создать задачу', $uid);
        } catch (Throwable $e) {
            error_log('Shared task creation failed: ' . $e->getMessage());
            $this->failMessage($request, 'Не удалось создать задачу', 500, $uid);
        }
    }

    public function updateTask(Request $request, string $uid): void
    {
        try {
            [$version, $commandId] = $this->commandIdentity($request);
            $post = $request->post();
            if (!is_array($post)) {
                throw new InvalidArgumentException('Некорректные данные задачи');
            }
            $changes = [];
            foreach (['title', 'description', 'status', 'priority', 'due_date', 'archived'] as $field) {
                if (array_key_exists($field, $post)) {
                    $changes[$field] = $post[$field];
                }
            }
            if (array_key_exists('assignee_ids', $post)) {
                if (!is_array($post['assignee_ids'])) {
                    throw new InvalidArgumentException('Некорректный список исполнителей');
                }
                $changes['assignee_ids'] = $post['assignee_ids'];
            }
            $action = array_key_exists('archived', $changes)
                ? ((string) $changes['archived'] === '1' ? 'board.task.archive' : 'board.task.restore')
                : (array_keys($changes) === ['status'] ? 'board.task.status' : 'board.task.update');
            $result = (new \App\Services\Tasks\TaskBoardVersionMutationService(
                \Core\DatabaseManager::getInstance()
            ))->mutate($this->userId($request), $uid, $version, $commandId, $action, $changes);
            $this->respondBoardMutation($request, $result);
        } catch (InvalidArgumentException|DomainException $error) {
            $this->fail($request, $error, 'Не удалось обновить задачу');
        } catch (Throwable $error) {
            error_log('Не удалось обновить общую задачу: ' . $error->getMessage());
            $this->failMessage($request, 'Не удалось обновить задачу', 500);
        }
    }

    public function deleteTask(Request $request, string $uid): void
    {
        $boardUid = trim((string) $request->post('board_uid', ''));
        try {
            [$version, $commandId] = $this->commandIdentity($request);
            $result = (new \App\Services\Tasks\TaskBoardVersionMutationService(
                \Core\DatabaseManager::getInstance()
            ))->mutate(
                $this->userId($request), $uid, $version, $commandId,
                'board.task.delete', ['delete' => '1']
            );
            $this->respondBoardMutation($request, $result);
        } catch (InvalidArgumentException|DomainException $error) {
            $this->fail($request, $error, 'Не удалось удалить задачу', $boardUid ?: null);
        } catch (Throwable $error) {
            error_log('Не удалось удалить общую задачу: ' . $error->getMessage());
            $this->failMessage($request, 'Не удалось удалить задачу', 500, $boardUid ?: null);
        }
    }

    /** @return array{0:int,1:string} */
    private function commandIdentity(Request $request): array
    {
        $version = trim((string) $request->post('expected_version', ''));
        $commandId = trim((string) $request->post('command_id', ''));
        if (preg_match('/^[1-9][0-9]*$/D', $version) !== 1 || $commandId === '') {
            throw new InvalidArgumentException('Требуются expected_version и command_id');
        }
        return [(int) $version, $commandId];
    }

    /** @param array<string,mixed> $result */
    private function respondBoardMutation(Request $request, array $result): void
    {
        if (($result['error'] ?? '') === 'version_conflict') {
            http_response_code(409);
            if ($this->expectsJson($request)) {
                $this->responseJson($result);
                return;
            }
            header('Content-Type: text/plain; charset=utf-8');
            echo (string) $result['message'];
            return;
        }
        if (($result['success'] ?? false) !== true) {
            throw new \RuntimeException('Непредусмотренный результат изменения задачи');
        }
        if ($this->expectsJson($request)) {
            $this->responseJson($result);
            return;
        }
        $this->flash($request, 'success', 'Задача обновлена');
        $this->redirectBoard((string) $result['board_uid']);
    }

    private function userId(Request $request): int
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            throw new DomainException('Требуется авторизация', 401);
        }
        return $userId;
    }

    private function expectsJson(Request $request): bool
    {
        return strtolower((string) $request->server('HTTP_X_REQUESTED_WITH', '')) === 'xmlhttprequest'
            || str_contains(strtolower((string) $request->server('HTTP_ACCEPT', '')), 'application/json');
    }

    private function status(Throwable $e, int $fallback): int
    {
        $code = (int) $e->getCode();
        return $code >= 400 && $code <= 599 ? $code : $fallback;
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $request->setSession('task_boards_flash', ['type' => $type, 'message' => $message]);
    }

    private function fail(Request $request, Throwable $e, string $fallback, ?string $boardUid = null): void
    {
        $message = trim($e->getMessage()) !== '' ? $e->getMessage() : $fallback;
        $this->failMessage($request, $message, $this->status($e, 422), $boardUid);
    }

    private function failMessage(Request $request, string $message, int $status, ?string $boardUid = null): void
    {
        if ($this->expectsJson($request)) {
            http_response_code($status);
            $this->responseJson(['success' => false, 'message' => $message]);
            return;
        }
        $this->flash($request, 'error', $message);
        $this->redirectBoard($boardUid);
    }

    private function redirectBoard(?string $boardUid): never
    {
        $router = Router::getInstance();
        $url = $router->getRoute('task_boards');
        if ($boardUid !== null && trim($boardUid) !== '') {
            $url .= '?board=' . rawurlencode($boardUid);
        }
        $router->redirect($url, 'url');
    }
}
