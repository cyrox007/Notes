<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\RolePolicyService;
use Core\Request;
use Throwable;

final class EnforceFileFolderPolicy
{
    public function handle(Request $request): bool
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            return false;
        }

        try {
            $allowed = (bool) (new RolePolicyService())->effectiveValue($userId, 'files', 'can_create_folders');
            if ($allowed) {
                return true;
            }
        } catch (Throwable $e) {
            error_log('File folder policy evaluation failed: ' . $e->getMessage());
            return $this->reject('Не удалось проверить ограничения роли', 500);
        }

        return $this->reject('Создание папок запрещено политикой роли', 403);
    }

    private function reject(string $message, int $status): bool
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        return false;
    }
}
