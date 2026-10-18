<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Tasks\TaskRelationMutationService;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Core\Router;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * HTTP-граница защищённых команд над подзадачами и категориями.
 * Версии и права проверяются исключительно в сервисе внутри транзакции.
 */
final class TaskRelationController extends Controller
{
    public function addSubtask(Request $request, string $taskUid): void
    {
        $this->executeTask($request, $taskUid, 'task.subtask.create', [
            'title' => $request->post('title', ''),
        ]);
    }

    public function toggleSubtask(Request $request, int $subtaskId): void
    {
        $this->executeTask($request, (string) $request->post('task_uid', ''), 'task.subtask.complete', [
            'subtask_id' => $subtaskId,
            'is_completed' => $request->post('is_completed', ''),
        ]);
    }

    public function deleteSubtask(Request $request, int $subtaskId): void
    {
        $this->executeTask($request, (string) $request->post('task_uid', ''), 'task.subtask.delete', [
            'subtask_id' => $subtaskId,
        ]);
    }

    public function attachCategory(Request $request, string $taskUid, int $categoryId): void
    {
        $this->executeTask($request, $taskUid, 'task.category.attach', [
            'category_id' => $categoryId,
        ]);
    }

    public function detachCategory(Request $request, string $taskUid, int $categoryId): void
    {
        $this->executeTask($request, $taskUid, 'task.category.detach', [
            'category_id' => $categoryId,
        ]);
    }

    public function createCategory(Request $request): void
    {
        try {
            $result = $this->service()->createCategory(
                $this->userId($request),
                $this->commandId($request),
                (string) $request->post('name', ''),
                (string) $request->post('color', '#3498db'),
                (string) $request->post('icon', 'fa-folder')
            );
            if ($this->expectsJson($request)) {
                $this->json($result);
                return;
            }
            Router::getInstance()->redirect('tasks', 'name');
        } catch (InvalidArgumentException|DomainException $error) {
            $this->failure($request, $error->getMessage(), $this->status($error, 422));
        } catch (Throwable $error) {
            error_log('Не удалось создать категорию Tasks: ' . $error->getMessage());
            $this->failure($request, 'Не удалось создать категорию', 500);
        }
    }

    /** @param array<string,mixed> $data */
    private function executeTask(Request $request, string $uid, string $action, array $data): void
    {
        try {
            $result = $this->service()->mutateTask(
                $this->userId($request),
                $uid,
                $this->expectedVersion($request),
                $this->commandId($request),
                $action,
                $data
            );
            $status = ($result['error'] ?? '') === 'version_conflict' ? 409 : 200;
            $this->json($result, $status);
        } catch (InvalidArgumentException|DomainException $error) {
            $this->failure($request, $error->getMessage(), $this->status($error, 422));
        } catch (Throwable $error) {
            error_log('Не удалось изменить связанные объекты Tasks: ' . $error->getMessage());
            $this->failure($request, 'Не удалось сохранить изменения', 500);
        }
    }

    private function service(): TaskRelationMutationService
    {
        return new TaskRelationMutationService(DatabaseManager::getInstance()->getPdo());
    }

    private function userId(Request $request): int
    {
        $id = (int) $request->session('user_id', 0);
        if ($id < 1) {
            throw new DomainException('Требуется авторизация', 401);
        }
        return $id;
    }

    private function expectedVersion(Request $request): int
    {
        $raw = trim((string) $request->post('expected_version', ''));
        if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1) {
            throw new InvalidArgumentException('Требуется актуальная expected_version');
        }
        return (int) $raw;
    }

    private function commandId(Request $request): string
    {
        $id = trim((string) $request->post('command_id', ''));
        if ($id === '') {
            throw new InvalidArgumentException('Для действия требуется command_id');
        }
        return $id;
    }

    private function status(Throwable $error, int $fallback): int
    {
        $code = (int) $error->getCode();
        return $code >= 400 && $code <= 599 ? $code : $fallback;
    }

    private function expectsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->server('HTTP_ACCEPT', ''));
        $requested = strtolower((string) $request->server('HTTP_X_REQUESTED_WITH', ''));
        return str_contains($accept, 'application/json') || $requested === 'xmlhttprequest';
    }

    private function failure(Request $request, string $message, int $status): void
    {
        if ($this->expectsJson($request)) {
            $this->json(['success' => false, 'error' => 'request_error', 'message' => $message], $status);
            return;
        }
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
