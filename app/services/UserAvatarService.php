<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use Core\Images;
use InvalidArgumentException;
use RuntimeException;

final class UserAvatarService
{
    private const DEFAULT_MAX_SIZE = 2 * 1024 * 1024;

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @param array<string,mixed> $file */
    public function upload(int $userId, string $userUid, array $file): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($this->uploadErrorMessage($error));
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('Некорректная загрузка аватара');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $this->maxSize()) {
            throw new InvalidArgumentException('Аватар пустой или превышает допустимый размер');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new InvalidArgumentException('Допустимы только JPEG, PNG и WebP');
        }

        $imageInfo = @getimagesize($tmp);
        if (!is_array($imageInfo) || !in_array((int) ($imageInfo[2] ?? 0), [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new InvalidArgumentException('Файл не является поддерживаемым изображением');
        }

        $directory = $this->avatarDirectory($userId);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось подготовить защищённое хранилище аватара');
        }

        $tempPath = $directory . DIRECTORY_SEPARATOR . 'avatar-' . bin2hex(random_bytes(12)) . '.jpg';
        $finalPath = $directory . DIRECTORY_SEPARATOR . 'avatar.jpg';

        try {
            Images::loadImage($tmp)
                ->processImage(256, 256)
                ->saveImage($tempPath, IMAGETYPE_JPEG, 85, 0600);

            if (!is_file($tempPath) || filesize($tempPath) === 0) {
                throw new RuntimeException('Не удалось обработать изображение аватара');
            }

            if (!rename($tempPath, $finalPath)) {
                throw new RuntimeException('Не удалось сохранить аватар');
            }
            @chmod($finalPath, 0600);
        } catch (\Throwable $e) {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
            throw $e;
        }

        return '/profile/avatar/' . rawurlencode($userUid) . '?v=' . bin2hex(random_bytes(8));
    }

    public function stream(string $userUid): void
    {
        $user = $this->db->fetchOne(
            'SELECT id,uid,avatar,is_active FROM users WHERE uid = :uid LIMIT 1',
            [':uid' => $userUid]
        );
        if (!$user || (int) $user['is_active'] !== 1 || empty($user['avatar'])) {
            $this->notFound();
            return;
        }

        $stored = (string) $user['avatar'];
        $path = str_starts_with($stored, '/profile/avatar/')
            ? $this->resolvePrivatePath(
                $this->avatarDirectory((int) $user['id']) . DIRECTORY_SEPARATOR . 'avatar.jpg',
                (int) $user['id']
            )
            : $this->resolveLegacyPath($stored, (int) $user['id']);

        if ($path === null || !is_file($path)) {
            $this->notFound();
            return;
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $this->notFound();
            return;
        }

        $size = filesize($path);
        if ($size === false) {
            $this->notFound();
            return;
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');
        header('Content-Disposition: inline; filename="avatar.jpg"');
        readfile($path);
    }

    /** @param array<string,mixed>|object $user */
    public function removeStoredAvatar(array|object $user): void
    {
        $data = is_array($user) ? $user : get_object_vars($user);
        $userId = (int) ($data['id'] ?? 0);
        $stored = (string) ($data['avatar'] ?? '');
        if ($userId <= 0 || $stored === '') {
            return;
        }

        $path = str_starts_with($stored, '/profile/avatar/')
            ? $this->resolvePrivatePath(
                $this->avatarDirectory($userId) . DIRECTORY_SEPARATOR . 'avatar.jpg',
                $userId
            )
            : $this->resolveLegacyPath($stored, $userId);

        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    private function avatarDirectory(int $userId): string
    {
        return $this->privateRoot()
            . DIRECTORY_SEPARATOR . 'users'
            . DIRECTORY_SEPARATOR . $userId
            . DIRECTORY_SEPARATOR . 'avatar';
    }

    private function privateRoot(): string
    {
        $configured = getenv('PRIVATE_STORAGE_PATH');
        $root = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : dirname(SITEPATH) . DIRECTORY_SEPARATOR . 'notes-private-storage';
        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    private function resolvePrivatePath(string $candidate, int $userId): ?string
    {
        $real = realpath($candidate);
        $root = realpath($this->avatarDirectory($userId));
        if ($real === false || $root === false) {
            return null;
        }

        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($real, $rootPrefix) ? $real : null;
    }

    private function resolveLegacyPath(string $stored, int $userId): ?string
    {
        $normalized = '/' . ltrim((string) (parse_url($stored, PHP_URL_PATH) ?: ''), '/');
        $expectedPrefix = '/uploads/users/' . $userId . '/avatars/';
        if (!str_starts_with($normalized, $expectedPrefix)) {
            return null;
        }

        $candidate = SITEPATH . $normalized;
        $real = realpath($candidate);
        $root = realpath(SITEPATH . $expectedPrefix);
        if ($real === false || $root === false) {
            return null;
        }

        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($real, $rootPrefix) ? $real : null;
    }

    private function maxSize(): int
    {
        $configured = getenv('PROFILE_AVATAR_MAX_SIZE');
        $value = is_string($configured) && ctype_digit($configured) ? (int) $configured : 0;
        return $value > 0 ? $value : self::DEFAULT_MAX_SIZE;
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Аватар превышает допустимый размер',
            UPLOAD_ERR_PARTIAL => 'Аватар загружен не полностью',
            UPLOAD_ERR_NO_FILE => 'Файл аватара не выбран',
            default => 'Ошибка загрузки аватара',
        };
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo 'Avatar not found';
    }
}
