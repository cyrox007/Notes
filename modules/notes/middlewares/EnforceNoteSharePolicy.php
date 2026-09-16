<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\RolePolicyService;
use Core\Request;
use Throwable;

final class EnforceNoteSharePolicy
{
    public function handle(Request $request): bool
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            return false;
        }

        try {
            if ((bool) (new RolePolicyService())->effectiveValue($userId, 'notes', 'can_share')) {
                return true;
            }
        } catch (Throwable $e) {
            error_log('Note share policy evaluation failed: ' . $e->getMessage());
            return $this->reject('Не удалось проверить ограничения роли', 500);
        }

        return $this->reject('Публичные ссылки на заметки отключены для вашей роли', 403);
    }

    private function reject(string $message, int $status): bool
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        return false;
    }
}
