<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\StorageQuotaService;
use Core\Request;
use Throwable;

/**
 * Serialize File Manager structural mutations with uploads and quota changes.
 *
 * Uploads already hold the same per-user advisory lock through StorageQuotaLimit.
 * Folder creation and rename also need that lock so they cannot race a recursive
 * delete and leave an active child under a deleted parent.
 */
final class StorageMutationLock
{
    public function handle(Request $request): bool
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            return true;
        }

        $service = new StorageQuotaService();
        $locked = false;
        try {
            $service->acquireUploadLock($userId);
            $locked = true;
            register_shutdown_function(static function () use ($service, $userId): void {
                try {
                    $service->releaseUploadLock($userId);
                } catch (Throwable $e) {
                    error_log('Storage mutation lock release failed: ' . $e->getMessage());
                }
            });
            return true;
        } catch (Throwable $e) {
            if ($locked) {
                try {
                    $service->releaseUploadLock($userId);
                } catch (Throwable $releaseError) {
                    error_log('Storage mutation lock release failed: ' . $releaseError->getMessage());
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
                'message' => $status === 503 ? $e->getMessage() : 'Не удалось заблокировать изменение хранилища',
            ], JSON_UNESCAPED_UNICODE);
            return false;
        }
    }
}
