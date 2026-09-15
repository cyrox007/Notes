<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\RolePolicyService;
use Core\Request;
use Throwable;

final class EnforceMessengerUploadPolicy
{
    public function handle(Request $request): bool
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            return false;
        }

        $isVoice = isset($_FILES['voice']);
        $file = $isVoice ? ($_FILES['voice'] ?? null) : ($_FILES['file'] ?? null);
        if (!is_array($file)) {
            return true;
        }

        try {
            $policies = new RolePolicyService();
            if ($isVoice && !(bool) $policies->effectiveValue($userId, 'messenger', 'can_send_voice')) {
                return $this->reject('Голосовые сообщения отключены для вашей роли', 403);
            }

            $size = max(0, (int) ($file['size'] ?? 0));
            $maxBytes = (int) $policies->effectiveValue($userId, 'messenger', 'max_attachment_bytes');
            if ($maxBytes > 0 && $size > $maxBytes) {
                return $this->reject('Размер вложения превышает лимит роли', 413);
            }

            $extensions = $policies->effectiveValue($userId, 'messenger', 'allowed_attachment_extensions');
            if (is_array($extensions) && $extensions !== []) {
                $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
                if ($extension === '' || !in_array($extension, $extensions, true)) {
                    return $this->reject('Этот тип вложения запрещён политикой роли', 415);
                }
            }
        } catch (Throwable $e) {
            error_log('Messenger upload policy evaluation failed: ' . $e->getMessage());
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
