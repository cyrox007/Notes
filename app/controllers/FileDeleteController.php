<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use App\Services\FileLifecycleService;
use App\Services\StorageQuotaService;
use Core\Controller;
use Core\Request;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class FileDeleteController extends Controller
{
    public function delete(Request $request): void
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0 || !UserModel::select()->where('id', '=', $userId)->first()) {
            $this->jsonError('Unauthorized', 401);
            return;
        }

        $fileId = (int) $request->post('id', 0);
        if ($fileId <= 0) {
            $this->jsonError('Неверный ID', 422);
            return;
        }

        $storageGuard = new StorageQuotaService();
        $locked = false;
        try {
            // Share the same per-user advisory lock used by uploads/quota updates.
            // This prevents a parallel upload from inserting a new child after the
            // delete subtree has been collected but before its metadata is committed.
            $storageGuard->acquireUploadLock($userId);
            $locked = true;

            $result = (new FileLifecycleService())->softDeleteTree($userId, $fileId);
            $payload = [
                'message' => $result['deleted_records'] > 1 ? 'Папка и содержимое удалены' : 'Удалено успешно',
                'deleted_records' => $result['deleted_records'],
            ];
            if ($result['cleanup_failures'] > 0) {
                $payload['cleanup_pending'] = $result['cleanup_failures'];
            }
            $this->jsonSuccess($payload);
        } catch (InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422);
        } catch (DomainException $e) {
            $status = (int) $e->getCode();
            $this->jsonError($e->getMessage(), $status >= 400 && $status <= 599 ? $status : 404);
        } catch (Throwable $e) {
            error_log('Error deleting File Manager item: ' . $e->getMessage());
            $this->jsonError('Ошибка при удалении', 500);
        } finally {
            if ($locked) {
                try {
                    $storageGuard->releaseUploadLock($userId);
                } catch (Throwable $releaseError) {
                    error_log('File Manager delete lock release failed: ' . $releaseError->getMessage());
                }
            }
        }
    }

    /** @param array<string,mixed> $payload */
    private function jsonSuccess(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true] + $payload, JSON_UNESCAPED_UNICODE);
    }

    private function jsonError(string $message, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    }
}
