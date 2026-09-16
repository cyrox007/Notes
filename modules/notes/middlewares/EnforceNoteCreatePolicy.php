<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\RolePolicyService;
use Core\DatabaseManager;
use Core\Request;
use Throwable;

final class EnforceNoteCreatePolicy
{
    public function handle(Request $request): bool
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            return false;
        }

        try {
            $limit = (int) (new RolePolicyService())->effectiveValue($userId, 'notes', 'max_notes');
            if ($limit <= 0) {
                return true;
            }

            $count = (int) DatabaseManager::getInstance()->fetchValue(
                'SELECT COUNT(*) FROM notes WHERE user_id = :user_id AND is_deleted = 0',
                [':user_id' => $userId]
            );
            if ($count >= $limit) {
                return $this->reject('Достигнут лимит заметок для вашей роли', 403);
            }
        } catch (Throwable $e) {
            error_log('Note create policy evaluation failed: ' . $e->getMessage());
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
