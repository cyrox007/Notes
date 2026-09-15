<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\RolePolicyService;
use Core\DatabaseManager;
use Core\Request;
use Throwable;

/**
 * Enforces role-level File Manager upload limits before controller storage work.
 * Platform upload allow-lists and the existing per-user/system quota middleware
 * still apply afterwards, so this layer can only make access more restrictive.
 */
final class EnforceFileUploadPolicy
{
    public function handle(Request $request): bool
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            return false;
        }

        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            return true;
        }

        try {
            $policies = new RolePolicyService();
            $size = max(0, (int) ($file['size'] ?? 0));

            $maxFileBytes = (int) $policies->effectiveValue($userId, 'files', 'max_file_bytes');
            if ($maxFileBytes > 0 && $size > $maxFileBytes) {
                return $this->reject('Размер файла превышает лимит роли', 413);
            }

            $allowedExtensions = $policies->effectiveValue($userId, 'files', 'allowed_extensions');
            if (is_array($allowedExtensions) && $allowedExtensions !== []) {
                $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
                if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
                    return $this->reject('Этот тип файла запрещён политикой роли', 415);
                }
            }

            $maxStorageBytes = (int) $policies->effectiveValue($userId, 'files', 'max_storage_bytes');
            if ($maxStorageBytes > 0 && $size > 0) {
                $used = (int) DatabaseManager::getInstance()->fetchValue(
                    "SELECT COALESCE(SUM(size),0) FROM user_files "
                    . "WHERE user_id = :user_id AND is_deleted = 0 AND type <> 'folder'",
                    [':user_id' => $userId]
                );
                if ($used + $size > $maxStorageBytes) {
                    return $this->reject('Загрузка превысит квоту файлового хранилища для роли', 413);
                }
            }
        } catch (Throwable $e) {
            error_log('File upload policy evaluation failed: ' . $e->getMessage());
            return $this->reject('Не удалось проверить ограничения роли', 500);
        }

        return true;
    }

    private function reject(string $message, int $status): bool
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        return false;
    }
}
