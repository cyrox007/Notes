<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use UUID;

final class MessengerVoiceService
{
    private const DEFAULT_MAX_SIZE = 5 * 1024 * 1024;

    /** @var array<string,list<string>> */
    private const ALLOWED_VOICE_UPLOADS = [
        // Chromium/Firefox MediaRecorder commonly produces Opus inside WebM.
        // libmagic may report the audio-only container as video/webm.
        'webm' => ['audio/webm', 'video/webm'],
        'ogg' => ['audio/ogg', 'application/ogg'],
        // Safari commonly records audio/mp4. Some libmagic databases report
        // an audio-only MP4 container as video/mp4, so .m4a accepts both.
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
        'wav' => ['audio/wav', 'audio/x-wav'],
    ];

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public function upload(int $userId, string $dialogUid, array $file): array
    {
        if ($userId <= 0 || trim($dialogUid) === '') {
            throw new DomainException('Требуется авторизация и диалог');
        }

        $membership = $this->membership($userId, $dialogUid);
        $this->validateUpload($file);

        $tmpName = (string) $file['tmp_name'];
        $originalName = $this->safeOriginalName((string) ($file['name'] ?? 'voice.webm'));
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string) $finfo->file($tmpName);
        if (
            $mimeType === ''
            || !isset(self::ALLOWED_VOICE_UPLOADS[$extension])
            || !in_array($mimeType, self::ALLOWED_VOICE_UPLOADS[$extension], true)
        ) {
            throw new InvalidArgumentException('Формат голосового сообщения не соответствует содержимому файла');
        }

        $attachmentUid = UUID::v4();
        $directory = $this->storageRoot()
            . DIRECTORY_SEPARATOR . (int) $membership['dialog_id']
            . DIRECTORY_SEPARATOR . $userId;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось подготовить защищённое хранилище');
        }

        $storedName = bin2hex(random_bytes(24)) . '.' . $extension;
        $storedPath = $directory . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($tmpName, $storedPath)) {
            throw new RuntimeException('Не удалось сохранить голосовое сообщение');
        }
        @chmod($storedPath, 0600);

        $size = (int) $file['size'];
        try {
            $this->db->execute(
                'INSERT INTO messenger_attachments (
                    uid, dialog_id, uploader_user_id, message_id,
                    original_name, stored_path, mime_type, extension,
                    media_kind, size, created_at, is_deleted
                 ) VALUES (
                    :uid, :dialog_id, :uploader_user_id, NULL,
                    :original_name, :stored_path, :mime_type, :extension,
                    "voice", :size, :created_at, 0
                 )',
                [
                    ':uid' => $attachmentUid,
                    ':dialog_id' => (int) $membership['dialog_id'],
                    ':uploader_user_id' => $userId,
                    ':original_name' => $originalName,
                    ':stored_path' => $storedPath,
                    ':mime_type' => $mimeType,
                    ':extension' => $extension,
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
            'media_kind' => 'voice',
            'size' => $size,
            'media_url' => '/messenger/media/' . rawurlencode($attachmentUid),
        ];
    }

    /** @return array<string,mixed> */
    private function membership(int $userId, string $dialogUid): array
    {
        $row = $this->db->fetchOne(
            'SELECT d.id AS dialog_id
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
            throw new InvalidArgumentException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Голосовое сообщение превышает допустимый размер',
                UPLOAD_ERR_PARTIAL => 'Голосовое сообщение загружено не полностью',
                UPLOAD_ERR_NO_FILE => 'Голосовое сообщение не передано',
                default => 'Ошибка загрузки голосового сообщения',
            });
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new InvalidArgumentException('Некорректная загрузка голосового сообщения');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $this->maxSize()) {
            throw new InvalidArgumentException('Голосовое сообщение пустое или превышает допустимый размер');
        }

        $name = $this->safeOriginalName((string) ($file['name'] ?? ''));
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === '' || !isset(self::ALLOWED_VOICE_UPLOADS[$extension])) {
            throw new InvalidArgumentException('Неподдерживаемый формат голосового сообщения');
        }
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?: 'voice.webm';
        return mb_substr($name, 0, 255);
    }

    private function maxSize(): int
    {
        $configured = getenv('MESSENGER_MAX_VOICE_SIZE');
        return is_string($configured) && ctype_digit($configured) && (int) $configured > 0
            ? (int) $configured
            : self::DEFAULT_MAX_SIZE;
    }

    private function privateStorageRoot(): string
    {
        $configured = getenv('PRIVATE_STORAGE_PATH');
        $root = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : dirname(SITEPATH) . DIRECTORY_SEPARATOR . 'notes-private-storage';
        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    private function storageRoot(): string
    {
        return $this->privateStorageRoot() . DIRECTORY_SEPARATOR . 'messenger';
    }
}
