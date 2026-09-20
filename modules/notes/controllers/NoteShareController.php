<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\CryptMethods;
use Core\Config;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use DomainException;
use InvalidArgumentException;

final class NoteShareController extends Controller
{
    public function create(Request $request, string $uid): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $userId = $this->sessionUserId($request);
            $db = DatabaseManager::getInstance();
            $note = $db->fetchOne(
                'SELECT id FROM notes WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0 LIMIT 1',
                [':uid' => $uid, ':user_id' => $userId]
            );
            if (!$note) {
                throw new DomainException('Заметка не найдена или недоступна');
            }

            $accessType = strtolower(trim((string) $request->post('access_type', 'view')));
            if ($accessType !== 'view') {
                throw new InvalidArgumentException('Публичное редактирование заметки пока не поддерживается');
            }

            $expiresIn = (int) $request->post('expires_in', 0);
            if ($expiresIn < 0 || $expiresIn > 8760) {
                throw new InvalidArgumentException('Срок действия ссылки должен быть от 0 до 8760 часов');
            }

            $token = bin2hex(random_bytes(32));
            $now = date('Y-m-d H:i:s');
            $expiresAt = $expiresIn > 0 ? date('Y-m-d H:i:s', time() + $expiresIn * 3600) : null;

            $db->beginTransaction();
            try {
                $db->execute(
                    'UPDATE shared_notes
                     SET is_active = 0
                     WHERE note_id = :note_id AND owner_id = :owner_id
                       AND shared_with_user_id IS NULL AND is_active = 1',
                    [':note_id' => (int) $note['id'], ':owner_id' => $userId]
                );
                $db->execute(
                    'INSERT INTO shared_notes (
                        note_id,owner_id,shared_with_user_id,share_token,access_type,expires_at,shared_at,is_active
                     ) VALUES (
                        :note_id,:owner_id,NULL,:share_token,"view",:expires_at,:shared_at,1
                     )',
                    [
                        ':note_id' => (int) $note['id'],
                        ':owner_id' => $userId,
                        ':share_token' => $token,
                        ':expires_at' => $expiresAt,
                        ':shared_at' => $now,
                    ]
                );
                $db->endTransaction(true);
            } catch (\Throwable $e) {
                $db->endTransaction(false);
                throw $e;
            }

            echo json_encode([
                'success' => true,
                'share_url' => $this->absoluteAppUrl('/notes/shared/' . rawurlencode($token)),
                'access_type' => 'view',
                'expires_at' => $expiresAt,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422);
        } catch (DomainException $e) {
            $this->jsonError($e->getMessage(), 403);
        } catch (\Throwable $e) {
            error_log('Note share create failed: ' . $e->getMessage());
            $this->jsonError('Не удалось создать ссылку', 500);
        }
    }

    public function unshare(Request $request, string $uid): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $userId = $this->sessionUserId($request);
            $db = DatabaseManager::getInstance();
            $note = $db->fetchOne(
                'SELECT id FROM notes WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0 LIMIT 1',
                [':uid' => $uid, ':user_id' => $userId]
            );
            if (!$note) {
                throw new DomainException('Заметка не найдена или недоступна');
            }

            $db->execute(
                'UPDATE shared_notes
                 SET is_active = 0
                 WHERE note_id = :note_id AND owner_id = :owner_id
                   AND shared_with_user_id IS NULL AND is_active = 1',
                [':note_id' => (int) $note['id'], ':owner_id' => $userId]
            );
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        } catch (DomainException $e) {
            $this->jsonError($e->getMessage(), 403);
        } catch (\Throwable $e) {
            error_log('Note unshare failed: ' . $e->getMessage());
            $this->jsonError('Не удалось деактивировать ссылку', 500);
        }
    }

    public function view(Request $request, string $token): void
    {
        // The bearer token lives in the URL. Prevent browsers/proxies from caching
        // the shared page or leaking the token through a Referer header.
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');

        $db = DatabaseManager::getInstance();
        $share = $db->fetchOne(
            'SELECT
                s.note_id,s.access_type,s.expires_at,
                n.uid,n.notename,n.content,n.content_type,n.is_encrypted,n.created_note,n.updated_note,
                u.username AS owner_username
             FROM shared_notes s
             INNER JOIN notes n ON n.id = s.note_id AND n.is_deleted = 0
             INNER JOIN users u ON u.id = n.user_id AND u.is_active = 1
             WHERE s.share_token = :token
               AND s.shared_with_user_id IS NULL
               AND s.is_active = 1
               AND (s.expires_at IS NULL OR s.expires_at >= :now)
             LIMIT 1',
            [':token' => $token, ':now' => date('Y-m-d H:i:s')]
        );

        if (!$share) {
            http_response_code(404);
            echo 'Заметка не найдена, ссылка отключена или срок её действия истёк';
            return;
        }

        $content = (string) ($share['content'] ?? '');
        if ((int) ($share['is_encrypted'] ?? 0) === 1 && $content !== '') {
            try {
                $content = CryptMethods::decrypt($content, (string) $share['uid']);
            } catch (\Throwable $e) {
                error_log('Shared note decrypt failed: ' . $e->getMessage());
                http_response_code(500);
                echo 'Содержимое заметки временно недоступно';
                return;
            }
        }

        $attachments = $db->fetchAll(
            'SELECT id,file_uid,file_name,file_type,mime_type,file_size,duration,uploaded_at
             FROM note_attachments
             WHERE note_id = :note_id AND is_deleted = 0
             ORDER BY id ASC',
            [':note_id' => (int) $share['note_id']]
        );
        foreach ($attachments as &$attachment) {
            $attachment['formatted_size'] = $this->formatBytes((int) ($attachment['file_size'] ?? 0));
            $attachment['file_url'] = $this->appPath(
                '/notes/shared/' . rawurlencode($token)
                . '/attachment/' . rawurlencode((string) $attachment['file_uid'])
            );
        }
        unset($attachment);

        $this->render_template('@notes/shared_view', [
            'note' => [
                'uid' => (string) $share['uid'],
                'notename' => (string) ($share['notename'] ?? ''),
                'content' => $content,
                'content_type' => (string) ($share['content_type'] ?? 'text'),
                'created_note' => (string) ($share['created_note'] ?? ''),
                'updated_note' => (string) ($share['updated_note'] ?? ''),
                'owner' => (string) ($share['owner_username'] ?? ''),
            ],
            'attachments' => $attachments,
            'shareToken' => $token,
            'canEdit' => false,
            'shareExpired' => false,
        ]);
    }

    private function sessionUserId(Request $request): int
    {
        $id = (int) $request->session('user_id');
        if ($id <= 0) {
            throw new DomainException('Требуется авторизация');
        }
        return $id;
    }

    private function appPath(string $path): string
    {
        $baseSegment = trim((string) getenv('BASE_PATH'), '/');
        $basePath = $baseSegment !== '' ? '/' . $baseSegment : '';
        return $basePath . '/' . ltrim($path, '/');
    }

    private function absoluteAppUrl(string $path): string
    {
        $configuredUrl = getenv('SITEURL');
        $siteUrl = is_string($configuredUrl) && trim($configuredUrl) !== ''
            ? rtrim(trim($configuredUrl), '/')
            : rtrim((string) Config::get('SITEURL'), '/');
        return $siteUrl . $this->appPath($path);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = max(0, $bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        return round($value, 2) . ' ' . $units[$unit];
    }

    private function jsonError(string $message, int $status): void
    {
        http_response_code($status);
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    }
}
