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
        $stats = ['pending' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0];
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
                    if (array_key_exists($status, $stats)) {
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

        $this->render_template('tasks_page/boards', [
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
            $post = $request->post();
            $changes = [];
            foreach (['title', 'description', 'status', 'priority', 'due_date'] as $key) {
                if (is_array($post) && array_key_exists($key, $post)) {
                    $changes[$key] = $post[$key];
                }
            }
            if (is_array($post) && array_key_exists('assignee_ids', $post)) {
                $changes['assignee_ids'] = is_array($post['assignee_ids']) ? $post['assignee_ids'] : [];
            }

            $task = (new TaskBoardService())->updateTask($this->userId($request), $uid, $changes);
            if ($this->expectsJson($request)) {
                $this->responseJson([
                    'success' => true,
                    'task_uid' => $uid,
                    'status' => (string) ($task['status'] ?? ''),
                    'board_uid' => (string) ($task['board_uid'] ?? ''),
                ]);
                return;
            }
            $this->flash($request, 'success', 'Задача обновлена');
            $this->redirectBoard((string) $task['board_uid']);
        } catch (InvalidArgumentException|DomainException $e) {
            if ($this->expectsJson($request)) {
                http_response_code($this->status($e, 422));
                $this->responseJson(['success' => false, 'message' => $e->getMessage()]);
                return;
            }
            $this->fail($request, $e, 'Не удалось обновить задачу');
        } catch (Throwable $e) {
            error_log('Shared task update failed: ' . $e->getMessage());
            if ($this->expectsJson($request)) {
                http_response_code(500);
                $this->responseJson(['success' => false, 'message' => 'Не удалось обновить задачу']);
                return;
            }
            $this->failMessage($request, 'Не удалось обновить задачу', 500);
        }
    }

    public function deleteTask(Request $request, string $uid): void
    {
        $boardUid = trim((string) $request->post('board_uid', ''));
        try {
            (new TaskBoardService())->deleteTask($this->userId($request), $uid);
            $this->flash($request, 'success', 'Задача удалена');
            $this->redirectBoard($boardUid !== '' ? $boardUid : null);
        } catch (InvalidArgumentException|DomainException $e) {
            $this->fail($request, $e, 'Не удалось удалить задачу', $boardUid !== '' ? $boardUid : null);
        } catch (Throwable $e) {
            error_log('Shared task deletion failed: ' . $e->getMessage());
            $this->failMessage($request, 'Не удалось удалить задачу', 500, $boardUid !== '' ? $boardUid : null);
        }
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
