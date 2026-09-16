<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\RolePolicyService;
use Core\DatabaseManager;
use Core\Request;
use Throwable;

final class EnforceNoteAttachmentPolicy
{
    public function handle(Request $request): bool
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            return false;
        }

        $file = $_FILES['attachment'] ?? null;
        if (!is_array($file)) {
            return true;
        }

        try {
            $policies = new RolePolicyService();
            $size = max(0, (int) ($file['size'] ?? 0));
            $maxBytes = (int) $policies->effectiveValue($userId, 'notes', 'max_attachment_bytes');
            if ($maxBytes > 0 && $size > $maxBytes) {
                return $this->reject('Размер вложения превышает лимит роли', 413);
            }

            $extensions = $policies->effectiveValue($userId, 'notes', 'allowed_attachment_extensions');
            if (is_array($extensions) && $extensions !== []) {
                $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
                if ($extension === '' || !in_array($extension, $extensions, true)) {
                    return $this->reject('Этот тип вложения запрещён политикой роли', 415);
                }
            }

            $maxAttachments = (int) $policies->effectiveValue($userId, 'notes', 'max_attachments_per_note');
            if ($maxAttachments > 0) {
                $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
                if (preg_match('~/notes/upload/([A-Za-z0-9_-]+)/?$~', $path, $match) === 1) {
                    $db = DatabaseManager::getInstance();
                    $note = $db->fetchOne(
                        'SELECT id FROM notes WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0 LIMIT 1',
                        [':uid' => $match[1], ':user_id' => $userId]
                    );
                    if ($note) {
                        $count = (int) $db->fetchValue(
                            'SELECT COUNT(*) FROM note_attachments WHERE note_id = :note_id AND is_deleted = 0',
                            [':note_id' => (int) $note['id']]
                        );
                        if ($count >= $maxAttachments) {
                            return $this->reject('Достигнут лимит вложений для заметки по политике роли', 403);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Note attachment policy evaluation failed: ' . $e->getMessage());
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
