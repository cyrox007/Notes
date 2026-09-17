<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\MessengerCrypto;
use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use UUID;

final class MessengerMediaService
{
    private const DEFAULT_MAX_UPLOAD_SIZE = 10 * 1024 * 1024;
    private const MAX_CAPTION_LENGTH = 4096;

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
        'm4a' => ['audio/mp4', 'audio/x-m4a'],
        'mp4' => ['video/mp4'],
        'webm' => ['video/webm'],
        'mov' => ['video/quicktime'],
        'mkv' => ['video/x-matroska', 'application/octet-stream'],
    ];

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public function upload(int $userId, string $dialogUid, array $file, bool $voice = false): array
    {
        if ($userId <= 0 || trim($dialogUid) === '') {
            throw new DomainException('Требуется авторизация и диалог');
        }

        $membership = $this->membershipByUserId($userId, $dialogUid);
        $this->validateUpload($file);

        $tmpName = (string) $file['tmp_name'];
        $originalName = $this->safeOriginalName((string) ($file['name'] ?? 'file'));
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string) $finfo->file($tmpName);
        if ($mimeType === '' || !in_array($mimeType, self::ALLOWED_UPLOADS[$extension], true)) {
            throw new InvalidArgumentException('Расширение файла не соответствует его содержимому');
        }

        $mediaKind = $this->mediaKind($mimeType, $voice);
        $size = (int) $file['size'];
        $attachmentUid = UUID::v4();
        $directory = $this->messengerStorageRoot()
            . DIRECTORY_SEPARATOR . (int) $membership['dialog_id']
            . DIRECTORY_SEPARATOR . $userId;

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось подготовить защищённое хранилище');
        }

        $storedName = bin2hex(random_bytes(24)) . '.' . $extension;
        $storedPath = $directory . DIRECTORY_SEPARATOR . $storedName;

        if (!move_uploaded_file($tmpName, $storedPath)) {
            throw new RuntimeException('Не удалось сохранить загруженный файл');
        }
        @chmod($storedPath, 0600);

        try {
            $this->db->execute(
                'INSERT INTO messenger_attachments (
                    uid, dialog_id, uploader_user_id, message_id,
                    original_name, stored_path, mime_type, extension,
                    media_kind, size, created_at, is_deleted
                 ) VALUES (
                    :uid, :dialog_id, :uploader_user_id, NULL,
                    :original_name, :stored_path, :mime_type, :extension,
                    :media_kind, :size, :created_at, 0
                 )',
                [
                    ':uid' => $attachmentUid,
                    ':dialog_id' => (int) $membership['dialog_id'],
                    ':uploader_user_id' => $userId,
                    ':original_name' => $originalName,
                    ':stored_path' => $storedPath,
                    ':mime_type' => $mimeType,
                    ':extension' => $extension,
                    ':media_kind' => $mediaKind,
                    ':size' => $size,
                    ':created_at' => date('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable $e) {
            @unlink($storedPath);
            throw $e;
        }

        return [
            'uid' => $attachmentUid,
            'dialog_uid' => $dialogUid,
            'name' => $originalName,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'media_kind' => $mediaKind,
            'size' => $size,
            'media_url' => $this->mediaUrl($attachmentUid),
        ];
    }

    /** @return array<string,mixed> */
    public function send(
        string $userUid,
        string $attachmentUid,
        string $caption = '',
        ?string $replyToUid = null
    ): array {
        $caption = trim($caption);
        if (mb_strlen($caption) > self::MAX_CAPTION_LENGTH) {
            throw new InvalidArgumentException('Подпись к файлу слишком длинная');
        }

        $user = $this->db->fetchOne(
            'SELECT id, uid, username, firstname, lastname, avatar
             FROM users
             WHERE uid = :uid AND is_active = 1
             LIMIT 1',
            [':uid' => $userUid]
        );
        if (!$user) {
            throw new DomainException('Пользователь не найден или заблокирован');
        }

        $this->db->beginTransaction();
        try {
            $attachment = $this->db->fetchOne(
                'SELECT
                    a.id, a.uid, a.dialog_id, a.uploader_user_id, a.message_id,
                    a.original_name, a.stored_path, a.mime_type, a.extension,
                    a.media_kind, a.size, d.uid AS dialog_uid
                 FROM messenger_attachments a
                 INNER JOIN dialogs d ON d.id = a.dialog_id
                 INNER JOIN user_to_dialogs utd
                    ON utd.dialog_id = a.dialog_id
                   AND utd.user_id = :user_id
                   AND utd.is_deleted = 0
                 WHERE a.uid = :attachment_uid
                   AND a.uploader_user_id = :uploader_user_id
                   AND a.is_deleted = 0
                 LIMIT 1
                 FOR UPDATE',
                [
                    ':user_id' => (int) $user['id'],
                    ':attachment_uid' => $attachmentUid,
                    ':uploader_user_id' => (int) $user['id'],
                ]
            );

            if (!$attachment) {
                throw new DomainException('Вложение не найдено или недоступно');
            }
            if (!empty($attachment['message_id'])) {
                throw new InvalidArgumentException('Вложение уже отправлено');
            }
            if ($this->resolveStoredPath((string) $attachment['stored_path']) === null) {
                throw new RuntimeException('Файл вложения отсутствует в защищённом хранилище');
            }

            $replyToId = null;
            $replyPayload = null;
            $replyToUid = $replyToUid !== null ? trim($replyToUid) : null;
            if ($replyToUid !== null && $replyToUid !== '') {
                $reply = $this->db->fetchOne(
                    'SELECT
                        m.id, m.uid, m.message, m.message_type,
                        u.uid AS user_uid, u.firstname, u.lastname
                     FROM messages m
                     INNER JOIN users u ON u.id = m.from_user_id
                     WHERE m.uid = :reply_uid
                       AND m.dialog_id = :dialog_id
                       AND m.is_deleted = 0
                     LIMIT 1',
                    [
                        ':reply_uid' => $replyToUid,
                        ':dialog_id' => (int) $attachment['dialog_id'],
                    ]
                );
                if (!$reply) {
                    throw new InvalidArgumentException('Сообщение для ответа не найдено в этом диалоге');
                }

                $replyToId = (int) $reply['id'];
                $replyText = $this->mediaLabel((string) ($reply['message_type'] ?? 'text'));
                if (($reply['message_type'] ?? 'text') === 'text') {
                    try {
                        $replyText = MessengerCrypto::decrypt((string) $reply['message'], (string) $reply['uid']);
                    } catch (\Throwable) {
                        $replyText = '[Сообщение недоступно]';
                    }
                }
                $replyPayload = [
                    'uid' => (string) $reply['uid'],
                    'message' => $replyText,
                    'user_uid' => (string) ($reply['user_uid'] ?? ''),
                    'user_name' => trim(
                        (string) ($reply['firstname'] ?? '') . ' ' .
                        (string) ($reply['lastname'] ?? '')
                    ),
                ];
            }

            $messageUid = UUID::v4();
            $now = date('Y-m-d H:i:s');
            $metadata = [
                'attachment_uid' => (string) $attachment['uid'],
                'name' => (string) $attachment['original_name'],
                'mime_type' => (string) $attachment['mime_type'],
                'extension' => (string) $attachment['extension'],
                'size' => (int) $attachment['size'],
            ];

            $this->db->execute(
                'INSERT INTO messages (
                    uid, dialog_id, from_user_id, reply_to_message_id,
                    message, message_type, media_url, meta_data,
                    message_status, is_deleted, created_at, updated_at
                 ) VALUES (
                    :uid, :dialog_id, :from_user_id, :reply_to_message_id,
                    :message, :message_type, :media_url, :meta_data,
                    :message_status, 0, :created_at, :updated_at
                 )',
                [
                    ':uid' => $messageUid,
                    ':dialog_id' => (int) $attachment['dialog_id'],
                    ':from_user_id' => (int) $user['id'],
                    ':reply_to_message_id' => $replyToId,
                    ':message' => MessengerCrypto::encrypt($caption, $messageUid),
                    ':message_type' => (string) $attachment['media_kind'],
                    ':media_url' => $this->mediaUrl((string) $attachment['uid']),
                    ':meta_data' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ':message_status' => 'sent',
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]
            );
            $messageId = (int) $this->db->getPdo()->lastInsertId();

            $this->db->execute(
                'UPDATE messenger_attachments
                 SET message_id = :message_id
                 WHERE id = :id AND message_id IS NULL',
                [':message_id' => $messageId, ':id' => (int) $attachment['id']]
            );
            $this->db->execute(
                'UPDATE dialogs SET updated_at = :updated_at WHERE id = :dialog_id',
                [':updated_at' => $now, ':dialog_id' => (int) $attachment['dialog_id']]
            );

            $this->db->endTransaction(true);

            return [
                'id' => $messageId,
                'uid' => $messageUid,
                'dialog_id' => (int) $attachment['dialog_id'],
                'from_user_id' => (int) $user['id'],
                'message' => $caption,
                'message_type' => (string) $attachment['media_kind'],
                'media_url' => $this->mediaUrl((string) $attachment['uid']),
                'meta_data' => $metadata,
                'message_status' => 'sent',
                'edited_at' => null,
                'created_at' => $now,
                'decrypt_error' => false,
                'user' => [
                    'uid' => (string) $user['uid'],
                    'username' => (string) ($user['username'] ?? ''),
                    'firstname' => (string) ($user['firstname'] ?? ''),
                    'lastname' => (string) ($user['lastname'] ?? ''),
                    'avatar' => $user['avatar'] ?? null,
                ],
                'reply' => $replyPayload,
                'dialog_uid' => (string) $attachment['dialog_uid'],
            ];
        } catch (\Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function download(int $userId, string $attachmentUid): array
    {
        $attachment = $this->db->fetchOne(
            'SELECT
                a.uid, a.dialog_id, a.uploader_user_id, a.message_id,
                a.original_name, a.stored_path, a.mime_type, a.extension,
                a.media_kind, a.size
             FROM messenger_attachments a
             INNER JOIN users u ON u.id = :user_id AND u.is_active = 1
             INNER JOIN user_to_dialogs utd
                ON utd.dialog_id = a.dialog_id
               AND utd.user_id = u.id
               AND utd.is_deleted = 0
             LEFT JOIN messages m ON m.id = a.message_id
             WHERE a.uid = :attachment_uid
               AND a.is_deleted = 0
               AND (
                    (a.message_id IS NULL AND a.uploader_user_id = u.id)
                    OR (
                        a.message_id IS NOT NULL
                        AND m.is_deleted = 0
                        AND NOT EXISTS (
                            SELECT 1
                            FROM message_user_deletions mud
                            WHERE mud.message_id = a.message_id
                              AND mud.user_id = u.id
                        )
                    )
               )
             LIMIT 1',
            [':user_id' => $userId, ':attachment_uid' => $attachmentUid]
        );

        if (!$attachment) {
            throw new DomainException('Файл не найден или недоступен');
        }

        $path = $this->resolveStoredPath((string) $attachment['stored_path']);
        if ($path === null || !is_file($path)) {
            throw new RuntimeException('Файл отсутствует в защищённом хранилище');
        }

        $attachment['path'] = $path;
        unset($attachment['stored_path']);
        return $attachment;
    }

    /** @return array<string,mixed> */
    private function membershipByUserId(int $userId, string $dialogUid): array
    {
        $row = $this->db->fetchOne(
            'SELECT d.id AS dialog_id, d.uid AS dialog_uid
             FROM users u
             INNER JOIN user_to_dialogs utd
                ON utd.user_id = u.id AND utd.is_deleted = 0
             INNER JOIN dialogs d ON d.id = utd.dialog_id
             WHERE u.id = :user_id
               AND u.is_active = 1
               AND d.uid = :dialog_uid
             LIMIT 1',
            [':user_id' => $userId, ':dialog_uid' => $dialogUid]
        );
        if (!$row) {
            throw new DomainException('Нет доступа к диалогу');
        }
        return $row;
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

        $originalName = $this->safeOriginalName((string) ($file['name'] ?? ''));
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === '' || !isset(self::ALLOWED_UPLOADS[$extension])) {
            throw new InvalidArgumentException('Файлы данного типа запрещены');
        }
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?: 'file';
        return mb_substr($name, 0, 255);
    }

    private function mediaKind(string $mimeType, bool $voice): string
    {
        if (str_starts_with($mimeType, 'image/')) return 'image';
        if (str_starts_with($mimeType, 'audio/')) return $voice ? 'voice' : 'audio';
        if (str_starts_with($mimeType, 'video/')) return 'video';
        return 'file';
    }

    private function mediaLabel(string $type): string
    {
        return match ($type) {
            'image' => '🖼 Изображение',
            'audio' => '🎵 Аудио',
            'video' => '🎬 Видео',
            'voice' => '🎙 Голосовое сообщение',
            'file' => '📎 Файл',
            'service' => 'Системное сообщение',
            default => 'Сообщение',
        };
    }

    private function mediaUrl(string $attachmentUid): string
    {
        return '/messenger/media/' . rawurlencode($attachmentUid);
    }

    private function privateStorageRoot(): string
    {
        $configured = getenv('PRIVATE_STORAGE_PATH');
        $root = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : dirname(SITEPATH) . DIRECTORY_SEPARATOR . 'notes-private-storage';
        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    private function messengerStorageRoot(): string
    {
        return $this->privateStorageRoot() . DIRECTORY_SEPARATOR . 'messenger';
    }

    private function resolveStoredPath(string $storedPath): ?string
    {
        $realPath = realpath($storedPath);
        $root = realpath($this->messengerStorageRoot());
        if ($realPath === false || $root === false) {
            return null;
        }

        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $candidate = rtrim($realPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($candidate, $rootPrefix)) {
            error_log('Blocked messenger media path outside private storage: ' . $storedPath);
            return null;
        }

        return $realPath;
    }

    private function maxUploadSize(): int
    {
        $messengerLimit = getenv('MESSENGER_MAX_UPLOAD_SIZE');
        if (is_string($messengerLimit) && ctype_digit($messengerLimit) && (int) $messengerLimit > 0) {
            return (int) $messengerLimit;
        }

        $genericLimit = getenv('MAX_UPLOAD_SIZE');
        return is_string($genericLimit) && ctype_digit($genericLimit) && (int) $genericLimit > 0
            ? (int) $genericLimit
            : self::DEFAULT_MAX_UPLOAD_SIZE;
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл превышает допустимый размер',
            UPLOAD_ERR_PARTIAL => 'Файл загружен не полностью',
            UPLOAD_ERR_NO_FILE => 'Файл не выбран',
            UPLOAD_ERR_NO_TMP_DIR => 'На сервере отсутствует временная директория',
            UPLOAD_ERR_CANT_WRITE => 'Сервер не смог записать файл',
            UPLOAD_ERR_EXTENSION => 'Загрузка остановлена расширением PHP',
            default => 'Ошибка загрузки файла',
        };
    }
}
