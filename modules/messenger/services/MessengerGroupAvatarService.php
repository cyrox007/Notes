<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

final class MessengerGroupAvatarService
{
    private const DEFAULT_MAX_SIZE = 2 * 1024 * 1024;

    /** @var array<string,list<string>> */
    private const ALLOWED = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ];

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @param array<string,mixed> $file @return array{dialog_uid:string,avatar:string,avatar_url:string} */
    public function upload(int $userId, string $dialogUid, array $file): array
    {
        $context = $this->managerContext($userId, $dialogUid);
        $this->validateUpload($file);

        $tmpName = (string) $file['tmp_name'];
        $originalName = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'avatar')));
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmpName);
        if ($mime === '' || !in_array($mime, self::ALLOWED[$extension], true)) {
            throw new InvalidArgumentException('Расширение изображения не соответствует его содержимому');
        }

        $directory = $this->avatarDirectory((int) $context['dialog_id']);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось подготовить хранилище аватара');
        }

        $token = bin2hex(random_bytes(24)) . '.' . $extension;
        $path = $directory . DIRECTORY_SEPARATOR . $token;
        if (!move_uploaded_file($tmpName, $path)) {
            throw new RuntimeException('Не удалось сохранить аватар группы');
        }
        @chmod($path, 0600);

        $oldToken = $this->validToken((string) ($context['avatar'] ?? ''))
            ? (string) $context['avatar']
            : null;

        try {
            $this->db->execute(
                'UPDATE dialogs SET avatar = :avatar, updated_at = :updated_at WHERE id = :dialog_id',
                [
                    ':avatar' => $token,
                    ':updated_at' => date('Y-m-d H:i:s'),
                    ':dialog_id' => (int) $context['dialog_id'],
                ]
            );
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }

        if ($oldToken !== null && $oldToken !== $token) {
            $oldPath = $this->storedPath((int) $context['dialog_id'], $oldToken);
            if ($oldPath !== null && is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        return [
            'dialog_uid' => $dialogUid,
            'avatar' => $token,
            'avatar_url' => $this->publicUrl($dialogUid),
        ];
    }

    /** @return array{dialog_uid:string,avatar:null,avatar_url:null} */
    public function remove(int $userId, string $dialogUid): array
    {
        $context = $this->managerContext($userId, $dialogUid);
        $oldToken = $this->validToken((string) ($context['avatar'] ?? ''))
            ? (string) $context['avatar']
            : null;

        $this->db->execute(
            'UPDATE dialogs SET avatar = NULL, updated_at = :updated_at WHERE id = :dialog_id',
            [
                ':updated_at' => date('Y-m-d H:i:s'),
                ':dialog_id' => (int) $context['dialog_id'],
            ]
        );

        if ($oldToken !== null) {
            $oldPath = $this->storedPath((int) $context['dialog_id'], $oldToken);
            if ($oldPath !== null && is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        return ['dialog_uid' => $dialogUid, 'avatar' => null, 'avatar_url' => null];
    }

    /** @return array{path:string,mime_type:string} */
    public function download(int $userId, string $dialogUid): array
    {
        $row = $this->db->fetchOne(
            'SELECT d.id AS dialog_id, d.avatar
             FROM users u
             INNER JOIN user_to_dialogs utd
                ON utd.user_id = u.id AND utd.is_deleted = 0
             INNER JOIN dialogs d ON d.id = utd.dialog_id AND d.type = "group"
             WHERE u.id = :user_id
               AND u.is_active = 1
               AND d.uid = :dialog_uid
             LIMIT 1',
            [':user_id' => $userId, ':dialog_uid' => $dialogUid]
        );
        if (!$row) {
            throw new DomainException('Группа недоступна');
        }

        $token = (string) ($row['avatar'] ?? '');
        if (!$this->validToken($token)) {
            throw new DomainException('У группы нет аватара');
        }

        $path = $this->storedPath((int) $row['dialog_id'], $token);
        if ($path === null || !is_file($path)) {
            throw new DomainException('Аватар группы не найден');
        }

        $extension = strtolower((string) pathinfo($token, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => throw new DomainException('Некорректный формат аватара'),
        };

        return ['path' => $path, 'mime_type' => $mime];
    }

    public function publicUrl(string $dialogUid): string
    {
        return '/messenger/group-avatar/' . rawurlencode($dialogUid);
    }

    /** @return array<string,mixed> */
    private function managerContext(int $userId, string $dialogUid): array
    {
        if ($userId <= 0 || trim($dialogUid) === '') {
            throw new DomainException('Требуется авторизация и группа');
        }

        $row = $this->db->fetchOne(
            'SELECT d.id AS dialog_id, d.avatar, utd.role
             FROM users u
             INNER JOIN user_to_dialogs utd
                ON utd.user_id = u.id AND utd.is_deleted = 0
             INNER JOIN dialogs d ON d.id = utd.dialog_id AND d.type = "group"
             WHERE u.id = :user_id
               AND u.is_active = 1
               AND d.uid = :dialog_uid
             LIMIT 1',
            [':user_id' => $userId, ':dialog_uid' => $dialogUid]
        );
        if (!$row) {
            throw new DomainException('Группа недоступна');
        }
        if (!in_array((string) $row['role'], ['owner', 'admin'], true)) {
            throw new DomainException('Недостаточно прав для изменения аватара группы');
        }
        return $row;
    }

    /** @param array<string,mixed> $file */
    private function validateUpload(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Изображение превышает допустимый размер',
                UPLOAD_ERR_PARTIAL => 'Изображение загружено не полностью',
                UPLOAD_ERR_NO_FILE => 'Изображение не выбрано',
                default => 'Ошибка загрузки изображения',
            });
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new InvalidArgumentException('Некорректная загрузка изображения');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $this->maxSize()) {
            throw new InvalidArgumentException('Аватар пустой или превышает допустимый размер');
        }

        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$extension])) {
            throw new InvalidArgumentException('Аватар должен быть JPEG, PNG или WebP');
        }
    }

    private function maxSize(): int
    {
        $configured = getenv('MESSENGER_GROUP_AVATAR_MAX_SIZE');
        return is_string($configured) && ctype_digit($configured) && (int) $configured > 0
            ? (int) $configured
            : self::DEFAULT_MAX_SIZE;
    }

    private function avatarDirectory(int $dialogId): string
    {
        return $this->privateStorageRoot()
            . DIRECTORY_SEPARATOR . 'messenger'
            . DIRECTORY_SEPARATOR . 'group_avatars'
            . DIRECTORY_SEPARATOR . $dialogId;
    }

    private function storedPath(int $dialogId, string $token): ?string
    {
        if (!$this->validToken($token)) {
            return null;
        }

        $directory = $this->avatarDirectory($dialogId);
        $candidate = $directory . DIRECTORY_SEPARATOR . $token;
        $real = realpath($candidate);
        $root = realpath($directory);
        if ($real === false || $root === false) {
            return null;
        }

        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $realWithSeparator = rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($realWithSeparator, $rootPrefix) ? $real : null;
    }

    private function validToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{48}\.(?:jpe?g|png|webp)$/', $token) === 1;
    }

    private function privateStorageRoot(): string
    {
        $configured = getenv('PRIVATE_STORAGE_PATH');
        $root = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : dirname(SITEPATH) . DIRECTORY_SEPARATOR . 'notes-private-storage';
        return rtrim($root, DIRECTORY_SEPARATOR);
    }
}
