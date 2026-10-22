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
        $settings = $db->fetchOne(
            'SELECT enabled,repeat_interval_days FROM task_morning_digest_settings WHERE user_id=:user',
            [':user' => $userId]
        );
        $enabled = (int) ($settings['enabled'] ?? 0) === 1;
        $queue['morning_digest_enabled'] = $enabled;
        $queue['repeat_interval_days'] = $settings !== null && $settings['repeat_interval_days'] !== null
            ? (int) $settings['repeat_interval_days'] : ($enabled ? 1 : 0);
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
        $rawInterval = $request->rawPost('repeat_interval_days', '');
        if (!is_string($rawInterval) || !in_array($rawInterval, ['0', '1', '3', '7'], true)) {
            $this->respond(['error' => 'invalid_repeat_interval',
                'message' => 'Выберите допустимую частоту повторных напоминаний'], 422);
            return;
        }
        $interval = (int) $rawInterval;
        try {
            $saved = (new TaskUserTimezoneService($db))->save($userId, $name);
            $enabled = $request->rawPost('morning_digest_enabled', '') === '1';
            $db->execute(
                'INSERT INTO task_morning_digest_settings (user_id,enabled,repeat_interval_days)
                 VALUES (:user,:enabled,:interval)
                 ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),repeat_interval_days=VALUES(repeat_interval_days)',
                [':user' => $userId, ':enabled' => $enabled ? 1 : 0, ':interval' => $interval]
            );
        } catch (InvalidArgumentException $error) {
            $this->respond(['error' => 'invalid_timezone', 'message' => $error->getMessage()], 422);
            return;
        }
        $this->respond(['saved' => true, 'timezone' => $saved,
            'morning_digest_enabled' => $enabled, 'repeat_interval_days' => $interval], 200);
    }

    /** @param array<string,mixed> $data */
    private function respond(array $data, int $status): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
