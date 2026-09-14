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

        $service = new StorageQuotaService();
        $locked = false;
        try {
            $service->acquireUploadLock($userId);
            $locked = true;
            $service->assertCanStore($userId, $size);
            register_shutdown_function(static function () use ($service, $userId): void {
                try {
                    $service->releaseUploadLock($userId);
                } catch (Throwable $e) {
                    error_log('Storage quota lock release failed: ' . $e->getMessage());
                }
            });
            return true;
        } catch (Throwable $e) {
            if ($locked) {
                try {
                    $service->releaseUploadLock($userId);
                } catch (Throwable $releaseError) {
                    error_log('Storage quota lock release failed: ' . $releaseError->getMessage());
                }
            }
            $status = (int) $e->getCode();
            if ($status < 400 || $status > 599) {
                $status = 500;
            }
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => in_array($status, [413, 503], true) ? $e->getMessage() : 'Не удалось проверить доступное место',
            ], JSON_UNESCAPED_UNICODE);
            return false;
        }
    }
}
