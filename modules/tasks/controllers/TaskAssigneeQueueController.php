<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PermissionService;
use Core\DatabaseManager;
use Core\Request;
use DateTimeImmutable;
use Modules\Tasks\TaskAssigneeQueueService;
use Modules\Tasks\TaskUserTimezoneService;
use InvalidArgumentException;

/** AJAX-выдача рабочей очереди текущего пользователя. */
final class TaskAssigneeQueueController
{
    public function index(Request $request): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');

        $userId = (int) $request->session('user_id', 0);
        if (!$request->session('auth') || $userId < 1) {
            $this->respond(['error' => 'unauthorized'], 401);
            return;
        }
        $db = DatabaseManager::getInstance();
        if (!(new PermissionService($db))->hasPermission($userId, 'tasks.use')) {
            $this->respond(['error' => 'forbidden'], 403);
            return;
        }
        $queue = (new TaskAssigneeQueueService($db))->forUser(
            $userId, (new TaskUserTimezoneService($db))->nowFor($userId)
        );
        $this->respond($queue, 200);
    }

    public function timezone(Request $request): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        $userId = (int) $request->session('user_id', 0);
        if (!$request->session('auth') || $userId < 1) {
            $this->respond(['error' => 'unauthorized'], 401);
            return;
        }
        $db = DatabaseManager::getInstance();
        if (!(new PermissionService($db))->hasPermission($userId, 'tasks.use')) {
            $this->respond(['error' => 'forbidden'], 403);
            return;
        }
        $name = $request->rawPost('timezone', '');
        if (!is_string($name)) {
            $this->respond(['error' => 'invalid_timezone'], 422);
            return;
        }
        try {
            $saved = (new TaskUserTimezoneService($db))->save($userId, $name);
        } catch (InvalidArgumentException $error) {
            $this->respond(['error' => 'invalid_timezone', 'message' => $error->getMessage()], 422);
            return;
        }
        $this->respond(['saved' => true, 'timezone' => $saved], 200);
    }

    /** @param array<string,mixed> $data */
    private function respond(array $data, int $status): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
