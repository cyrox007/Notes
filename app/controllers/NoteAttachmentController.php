<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

final class NoteAttachmentController extends Controller
{
    private const DEFAULT_MAX_UPLOAD_SIZE = 10 * 1024 * 1024;

    /** @var array<string,list<string>> */
    private const ALLOWED_UPLOADS = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'wav' => ['audio/wav', 'audio/x-wav'],
        'ogg' => ['audio/ogg', 'application/ogg'],
        'flac' => ['audio/flac', 'audio/x-flac'],
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
        'mp4' => ['video/mp4'],
        'webm' => ['video/webm', 'audio/webm'],
        'mov' => ['video/quicktime'],
        'mkv' => ['video/x-matroska', 'application/octet-stream'],
    ];

    public function upload(Request $request, string $uid): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $userId = $this->sessionUserId($request);
            $db = DatabaseManager::getInstance();
            $note = $db->fetchOne(
                'SELECT id, uid FROM notes WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0 LIMIT 1',
                [':uid' => $uid, ':user_id' => $userId]
            );
            if (!$note) {
                throw new DomainException('Заметка не найдена или недоступна');
            }

            $file = $_FILES['attachment'] ?? null;
            if (!is_array($file)) {
                throw new InvalidArgumentException('Файл не выбран');
            }
            $this->validateUpload($file);

            $maxAttachments = $this->maxAttachments();
            $count = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM note_attachments WHERE note_id = :note_id AND is_deleted = 0',
                [':note_id' => (int) $note['id']]
            );
            if ($count >= $maxAttachments) {
                throw new InvalidArgumentException('Достигнут лимит вложений для заметки');
            }

            $tmpName = (string) $file['tmp_name'];
            $originalName = $this->safeName((string) ($file['name'] ?? 'file'));
            $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
            $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmpName);
            if ($mimeType === '' || !in_array($mimeType, self::ALLOWED_UPLOADS[$extension] ?? [], true)) {
                throw new InvalidArgumentException('Расширение файла не соответствует его содержимому');
            }

            $voice = $request->post('is_voice') === 'true';
            $fileType = $this->fileType($mimeType, $extension, $voice);
            if ($voice && $fileType !== 'voice') {
                throw new InvalidArgumentException('Файл не распознан как голосовая запись');
            }

            $fileUid = bin2hex(random_bytes(16));
            $directory = $this->storageRoot()
                . DIRECTORY_SEPARATOR . (int) $note['id']
                . DIRECTORY_SEPARATOR . $userId;
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Не удалось подготовить защищённое хранилище');
            }
            $path = $directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(24)) . '.' . $extension;

            if (!move_uploaded_file($tmpName, $path)) {
                throw new RuntimeException('Не удалось сохранить загруженный файл');
            }
            @chmod($path, 0600);

            try {
                $db->execute(
                    'INSERT INTO note_attachments (
                        note_id,file_uid,file_name,file_path,file_type,mime_type,file_size,duration,
                        is_encrypted,encryption_key_ref,uploaded_at,is_deleted
                     ) VALUES (
                        :note_id,:file_uid,:file_name,:file_path,:file_type,:mime_type,:file_size,NULL,
                        0,NULL,:uploaded_at,0
                     )',
                    [
                        ':note_id' => (int) $note['id'],
                        ':file_uid' => $fileUid,
                        ':file_name' => $originalName,
                        ':file_path' => $path,
                        ':file_type' => $fileType,
                        ':mime_type' => $mimeType,
                        ':file_size' => (int) $file['size'],
                        ':uploaded_at' => date('Y-m-d H:i:s'),
                    ]
                );
            } catch (\Throwable $e) {
                @unlink($path);
                throw $e;
            }

            echo json_encode([
                'success' => true,
                'attachment' => [
                    'file_uid' => $fileUid,
                    'file_name' => $originalName,
                    'file_type' => $fileType,
                    'mime_type' => $mimeType,
                    'file_size' => (int) $file['size'],
                    'file_url' => '/notes/attachment/' . rawurlencode($fileUid),
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422);
        } catch (DomainException $e) {
            $this->jsonError($e->getMessage(), 403);
        } catch (\Throwable $e) {
            error_log('Note attachment upload failed: ' . $e->getMessage());
            $this->jsonError('Не удалось загрузить вложение', 500);
        }
    }

    public function delete(Request $request, int $attachmentId): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $userId = $this->sessionUserId($request);
            $db = DatabaseManager::getInstance();
            $row = $db->fetchOne(
                'SELECT a.id
                 FROM note_attachments a
                 INNER JOIN notes n ON n.id = a.note_id
                 WHERE a.id = :id AND a.is_deleted = 0
                   AND n.user_id = :user_id AND n.is_deleted = 0
                 LIMIT 1',
                [':id' => $attachmentId, ':user_id' => $userId]
            );
            if (!$row) {
                throw new DomainException('Вложение не найдено или недоступно');
            }
            $db->execute('UPDATE note_attachments SET is_deleted = 1 WHERE id = :id', [':id' => $attachmentId]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        } catch (DomainException $e) {
            $this->jsonError($e->getMessage(), 404);
        } catch (\Throwable $e) {
            error_log('Note attachment delete failed: ' . $e->getMessage());
            $this->jsonError('Не удалось удалить вложение', 500);
        }
    }

    public function download(Request $request, string $fileUid): void
    {
        try {
            $userId = $this->sessionUserId($request);
            $db = DatabaseManager::getInstance();
            $row = $db->fetchOne(
                'SELECT a.*
                 FROM note_attachments a
                 INNER JOIN notes n ON n.id = a.note_id
                 WHERE a.file_uid = :file_uid AND a.is_deleted = 0
                   AND n.user_id = :user_id AND n.is_deleted = 0
                 LIMIT 1',
                [':file_uid' => $fileUid, ':user_id' => $userId]
            );
            if (!$row) {
                $this->notFound();
                return;
            }
            $this->stream($row);
        } catch (DomainException) {
            http_response_code(403);
            echo 'Forbidden';
        }
    }

    public function sharedDownload(Request $request, string $token, string $fileUid): void
    {
        $db = DatabaseManager::getInstance();
        $row = $db->fetchOne(
            'SELECT a.*
             FROM shared_notes s
             INNER JOIN notes n ON n.id = s.note_id AND n.is_deleted = 0
             INNER JOIN note_attachments a ON a.note_id = n.id AND a.is_deleted = 0
             WHERE s.share_token = :token
               AND s.is_active = 1
               AND (s.expires_at IS NULL OR s.expires_at >= :now)
               AND a.file_uid = :file_uid
             LIMIT 1',
            [':token' => $token, ':file_uid' => $fileUid, ':now' => date('Y-m-d H:i:s')]
        );
        if (!$row) {
            $this->notFound();
            return;
        }
        $this->stream($row);
    }

    /** @param array<string,mixed> $file */
    private function validateUpload(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($this->uploadErrorMessage($error));
        }
        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new InvalidArgumentException('Некорректная загрузка файла');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $this->maxUploadSize()) {
            throw new InvalidArgumentException('Файл пустой или превышает допустимый размер');
        }
        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extension === '' || !isset(self::ALLOWED_UPLOADS[$extension])) {
            throw new InvalidArgumentException('Файлы данного типа запрещены');
        }
    }

    private function fileType(string $mime, string $extension, bool $voice): string
    {
        if ($voice) {
            $voiceMime = str_starts_with($mime, 'audio/') || ($extension === 'webm' && $mime === 'video/webm');
            return $voiceMime ? 'voice' : 'document';
        }
        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'audio/')) return 'audio';
        if (str_starts_with($mime, 'video/')) return 'video';
        return 'document';
    }

    /** @param array<string,mixed> $row */
    private function stream(array $row): void
    {
        $path = $this->resolvePath((string) ($row['file_path'] ?? ''));
        if ($path === null || !is_file($path)) {
            $this->notFound();
            return;
        }

        $size = filesize($path);
        if ($size === false) {
            $this->notFound();
            return;
        }

        $mime = (string) ($row['mime_type'] ?? 'application/octet-stream');
        $kind = (string) ($row['file_type'] ?? 'document');
        $inline = in_array($kind, ['image', 'audio', 'video', 'voice'], true);
        $name = $this->safeHeaderName((string) ($row['file_name'] ?? 'file'));

        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('Accept-Ranges: bytes');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));

        $start = 0;
        $end = $size - 1;
        $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
        if ($range !== '') {
            if (preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) !== 1 || ($matches[1] === '' && $matches[2] === '')) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return;
            }
            if ($matches[1] === '') {
                $suffix = (int) $matches[2];
                if ($suffix <= 0) {
                    http_response_code(416);
                    header('Content-Range: bytes */' . $size);
                    return;
                }
                $start = max(0, $size - $suffix);
            } else {
                $start = (int) $matches[1];
                $end = $matches[2] === '' ? $end : (int) $matches[2];
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return;
            }
            $end = min($end, $size - 1);
            http_response_code(206);
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }

        $length = $end - $start + 1;
        header('Content-Length: ' . $length);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->notFound();
            return;
        }
        fseek($handle, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(8192, $remaining));
            if ($chunk === false || $chunk === '') break;
            echo $chunk;
            $remaining -= strlen($chunk);
            if (connection_aborted()) break;
        }
        fclose($handle);
    }

    private function sessionUserId(Request $request): int
    {
        $id = (int) $request->session('user_id');
        if ($id <= 0) {
            throw new DomainException('Требуется авторизация');
        }
        return $id;
    }

    private function storageRoot(): string
    {
        $configured = getenv('PRIVATE_STORAGE_PATH');
        $root = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : dirname(SITEPATH) . DIRECTORY_SEPARATOR . 'notes-private-storage';
        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'notes';
    }

    private function resolvePath(string $stored): ?string
    {
        $real = realpath($stored);
        $root = realpath($this->storageRoot());
        if ($real === false || $root === false) return null;
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($real, $prefix) ? $real : null;
    }

    private function maxUploadSize(): int
    {
        $raw = getenv('NOTES_MAX_UPLOAD_SIZE');
        if (!is_string($raw) || !ctype_digit($raw)) $raw = (string) getenv('MAX_UPLOAD_SIZE');
        $value = ctype_digit($raw) ? (int) $raw : self::DEFAULT_MAX_UPLOAD_SIZE;
        return max(1024, min(100 * 1024 * 1024, $value));
    }

    private function maxAttachments(): int
    {
        $raw = getenv('MAX_NOTE_ATTACHMENTS');
        $value = is_string($raw) && ctype_digit($raw) ? (int) $raw : 10;
        return max(1, min(100, $value));
    }

    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?: 'file';
        return mb_substr($name, 0, 255);
    }

    private function safeHeaderName(string $name): string
    {
        return preg_replace('/[\r\n"\\]+/', '_', $this->safeName($name)) ?: 'file';
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл превышает допустимый размер',
            UPLOAD_ERR_PARTIAL => 'Файл загружен не полностью',
            UPLOAD_ERR_NO_FILE => 'Файл не выбран',
            default => 'Ошибка загрузки файла',
        };
    }

    private function jsonError(string $message, int $status): void
    {
        http_response_code($status);
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    }

    private function notFound(): void
    {
        http_response_code(404);
        echo 'File not found';
    }
}
