<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\StorageQuotaService;
use Core\Controller;
use Core\Request;
use Throwable;

final class FileQuotaController extends Controller
{
    public function usage(Request $request): void
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            $this->respond(['success' => false, 'message' => 'Unauthorized'], 401);
            return;
        }

        try {
            $usage = (new StorageQuotaService())->usage($userId);
            $this->respond(['success' => true, 'storage' => $usage]);
        } catch (Throwable $e) {
            error_log('File Manager quota usage failed: ' . $e->getMessage());
            $this->respond(['success' => false, 'message' => 'Не удалось получить данные хранилища'], 500);
        }
    }

    /** @param array<string,mixed> $payload */
    private function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
