<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\RolePolicyService;
use Core\DatabaseManager;
use Core\Request;
use Throwable;

final class EnforceTaskCreatePolicy
{
    public function handle(Request $request): bool
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            return false;
        }

        try {
            $limit = (int) (new RolePolicyService())->effectiveValue($userId, 'tasks', 'max_personal_tasks');
            if ($limit <= 0) {
                return true;
            }

            $count = (int) DatabaseManager::getInstance()->fetchValue(
                'SELECT COUNT(*) FROM tasks WHERE user_id = :user_id AND is_deleted = 0',
                [':user_id' => $userId]
            );
            if ($count >= $limit) {
                return $this->reject('Достигнут лимит личных задач для вашей роли', 403);
            }
        } catch (Throwable $e) {
            error_log('Task create policy evaluation failed: ' . $e->getMessage());
            return $this->reject('Не удалось проверить ограничения роли', 500);
        }

        return true;
    }

    private function reject(string $message, int $status): bool
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        return false;
    }
}
