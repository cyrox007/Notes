<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\StorageQuotaService;
use Core\Request;
use Throwable;

final class StorageQuotaLimit
{
    public function handle(Request $request): bool
    {
        $userId = (int) $request->session('user_id', 0);
        $file = $_FILES['file'] ?? null;
        if ($userId <= 0 || !is_array($file)) {
            return true;
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return true;
        }

        try {
            (new StorageQuotaService())->assertCanStore($userId, $size);
            return true;
        } catch (Throwable $e) {
            $status = (int) $e->getCode();
            if ($status < 400 || $status > 599) {
                $status = 500;
            }
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => $status === 413 ? $e->getMessage() : 'Не удалось проверить доступное место',
            ], JSON_UNESCAPED_UNICODE);
            return false;
        }
    }
}
