<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use App\Services\FileLifecycleService;
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

        try {
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
