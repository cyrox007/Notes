<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Tasks\TaskVersionMutationService;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Core\Router;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Изменяет личные задачи через единый версионный контракт 1.2.
 *
 * Любое изменение существующей задачи требует expected_version и command_id.
 * Устаревшее состояние возвращает 409 и актуальную серверную версию вместо
 * молчаливого перезаписывания данных из другой вкладки.
 */
final class TaskMutationController extends Controller
{
    private const array STATUSES = ['pending', 'studying', 'in_progress', 'review', 'completed'];
    private const array PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public function update(Request $request, string $uid): void
    {
        $userId = $this->currentUserId($request);
        $db = DatabaseManager::getInstance();
        $task = $db->fetchOne(
            'SELECT id,uid,status,archived_at,is_deleted,version
             FROM tasks
             WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0
             LIMIT 1',
            [':uid' => $uid, ':user_id' => $userId]
        );
        if (!$task) {
            $this->respondNotFound($request, 'Задача не найдена');
            return;
        }

        try {
            [$expectedVersion, $commandId] = $this->mutationIdentity($request);
            $post = $request->post();
            if (!is_array($post)) {
                throw new InvalidArgumentException('Некорректные данные задачи');
            }

            $changes = $this->updateChanges($post, $task);
            if ($changes === []) {
                $this->respondNoChanges($request, $uid, (int) $task['version']);
                return;
            }

            $action = $this->mutationAction($post);
            $result = (new TaskVersionMutationService($db->getPdo()))->mutateOwned(
                $userId,
                $uid,
                $expectedVersion,
                $commandId,
                $action,
                $changes
            );
            $this->respondMutation($request, $result);
        } catch (InvalidArgumentException $exception) {
            $this->validationFailure($request, $exception->getMessage());
        }
    }

    public function delete(Request $request, string $uid): void
    {
        $userId = $this->currentUserId($request);

        try {
            [$expectedVersion, $commandId] = $this->mutationIdentity($request);
            $now = date('Y-m-d H:i:s');
            $result = (new TaskVersionMutationService(
                DatabaseManager::getInstance()->getPdo()
            ))->mutateOwned(
                $userId,
                $uid,
                $expectedVersion,
                $commandId,
                'task.delete',
                ['is_deleted' => 1, 'deleted_at' => $now]
            );
            $this->respondMutation($request, $result);
        } catch (InvalidArgumentException $exception) {
            $this->validationFailure($request, $exception->getMessage());
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'не найдена или недоступна')) {
                $this->respondNotFound($request, 'Задача не найдена');
                return;
            }
            throw $exception;
        }
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $task
     * @return array<string,mixed>
     */
    private function updateChanges(array $post, array $task): array
    {
        $changes = [];

        if (array_key_exists('archived', $post)) {
            $archived = (string) $post['archived'];
            if (!in_array($archived, ['0', '1'], true)) {
                throw new InvalidArgumentException('Некорректное действие архива');
            }
            $changes['archived_at'] = $archived === '1'
                ? ($task['archived_at'] ?? date('Y-m-d H:i:s'))
                : null;
        }

        if (!empty($task['archived_at']) && array_key_exists('status', $post)) {
            throw new InvalidArgumentException('Сначала восстановите задачу из архива');
        }
        if (array_key_exists('title', $post)) {
            $changes['title'] = $this->taskTitle((string) $post['title']);
        }
        if (array_key_exists('description', $post)) {
            $changes['description'] = $this->description((string) $post['description']);
        }
        if (array_key_exists('priority', $post)) {
            $changes['priority'] = $this->priority((string) $post['priority']);
        }
        if (array_key_exists('due_date', $post)) {
            $changes['due_date'] = $this->dateTimeOrNull((string) $post['due_date']);
        }
        if (array_key_exists('status', $post)) {
            $status = $this->status((string) $post['status']);
            $changes['status'] = $status;
            if ($status === 'completed' && (string) $task['status'] !== 'completed') {
                $changes['completed_at'] = date('Y-m-d H:i:s');
            } elseif ($status !== 'completed') {
                $changes['completed_at'] = null;
            }
        }

        return $changes;
    }

    /** @param array<string,mixed> $post */
    private function mutationAction(array $post): string
    {
        if (array_key_exists('archived', $post)) {
            return (string) $post['archived'] === '1' ? 'task.archive' : 'task.restore';
        }
        if (count($post) <= 3 && array_key_exists('status', $post)) {
            return 'task.status';
        }
        return 'task.update';
    }

    /** @return array{0:int,1:string} */
    private function mutationIdentity(Request $request): array
    {
        $versionRaw = trim((string) $request->post('expected_version', ''));
        if ($versionRaw === '' || preg_match('/^[1-9][0-9]*$/D', $versionRaw) !== 1) {
            throw new InvalidArgumentException('Для изменения задачи требуется актуальная expected_version');
        }

        $commandId = trim((string) $request->post('command_id', ''));
        if ($commandId === '') {
            throw new InvalidArgumentException('Для изменения задачи требуется command_id');
        }

        return [(int) $versionRaw, $commandId];
    }

    private function currentUserId(Request $request): int
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Требуется авторизация');
        }
        return $userId;
    }

    private function taskTitle(string $title): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title === '' || mb_strlen($title) > 255) {
            throw new InvalidArgumentException('Название задачи должно содержать от 1 до 255 символов');
        }
        return $title;
    }

    private function description(string $description): ?string
    {
        $description = trim($description);
        if (mb_strlen($description) > 10000) {
            throw new InvalidArgumentException('Описание задачи слишком длинное');
        }
        return $description === '' ? null : $description;
    }

    private function status(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Некорректный статус задачи');
        }
        return $status;
    }

    private function priority(string $priority): string
    {
        $priority = strtolower(trim($priority));
        if (!in_array($priority, self::PRIORITIES, true)) {
            throw new InvalidArgumentException('Некорректный приоритет задачи');
        }
        return $priority;
    }

    private function dateTimeOrNull(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d\\TH:i', 'Y-m-d\\TH:i:s', 'Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof DateTimeImmutable && $date->format($format) === $value) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        throw new InvalidArgumentException('Некорректная дата выполнения');
    }

    /** @param array<string,mixed> $result */
    private function respondMutation(Request $request, array $result): void
    {
        if (($result['success'] ?? false) !== true) {
            if (($result['error'] ?? '') === 'version_conflict') {
                if ($this->expectsJson($request)) {
                    $this->json($result, 409);
                    return;
                }
                http_response_code(409);
                header('Content-Type: text/plain; charset=utf-8');
                echo (string) ($result['message'] ?? 'Задача уже была изменена. Обновите страницу.');
                return;
            }
            throw new RuntimeException('Изменяющая команда Tasks завершилась неизвестным состоянием');
        }

        if ($this->expectsJson($request)) {
            $this->json($result);
            return;
        }
        Router::getInstance()->redirect('tasks', 'name');
    }

    private function respondNoChanges(Request $request, string $uid, int $version): void
    {
        if ($this->expectsJson($request)) {
            $this->json([
                'success' => true,
                'task_uid' => $uid,
                'version' => $version,
                'replayed' => false,
            ]);
            return;
        }
        Router::getInstance()->redirect('tasks', 'name');
    }

    private function expectsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->server('HTTP_ACCEPT', ''));
        $requestedWith = strtolower((string) $request->server('HTTP_X_REQUESTED_WITH', ''));
        return str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest';
    }

    private function respondNotFound(Request $request, string $message): void
    {
        if ($this->expectsJson($request)) {
            $this->json(['success' => false, 'error' => 'not_found', 'message' => $message], 404);
            return;
        }
        Router::getInstance()->redirect('tasks', 'name');
    }

    private function validationFailure(Request $request, string $message): void
    {
        if ($this->expectsJson($request)) {
            $this->json(['success' => false, 'error' => 'validation_error', 'message' => $message], 422);
            return;
        }
        http_response_code(422);
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
