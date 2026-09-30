<?php

declare(strict_types=1);

namespace App\Services;

require_once dirname(__DIR__, 3) . '/core/HostingCompatibility.php';

use Core\HostingCompatibility;
use GdImage;
use RuntimeException;

/**
 * Обработка изображения аватара принадлежит модулю Profile, а не Core.
 */
final class AvatarImageProcessor
{
    private const DEFAULT_MAX_SOURCE_PIXELS = 8_000_000;
    private const DECODE_BYTES_PER_PIXEL = 6;
    private const MEMORY_SAFETY_BYTES = 16 * 1024 * 1024;

    public function writeSquareJpeg(
        string $source,
        string $target,
        int $size = 256,
        int $quality = 85
    ): void {
        if ($size <= 0 || $quality < 0 || $quality > 100) {
            throw new RuntimeException('Некорректные параметры обработки аватара');
        }

        $info = @getimagesize($source);
        if (!is_array($info)) {
            throw new RuntimeException('Не удалось прочитать изображение аватара');
        }

        $this->assertSourceBudget($info, $size);
        $image = $this->load($source, (int) ($info[2] ?? 0));
        $resized = imagecreatetruecolor($size, $size);
        if (!$resized instanceof GdImage) {
            imagedestroy($image);
            throw new RuntimeException('Не удалось подготовить изображение аватара');
        }

        try {
            $copied = imagecopyresampled(
                $resized,
                $image,
                0,
                0,
                0,
                0,
                $size,
                $size,
                imagesx($image),
                imagesy($image)
            );
            if (!$copied || !imagejpeg($resized, $target, $quality)) {
                throw new RuntimeException('Не удалось сохранить обработанный аватар');
            }

            @chmod($target, 0600);
        } finally {
            imagedestroy($resized);
            imagedestroy($image);
        }
    }

    /** @param array<int|string,mixed> $info */
    private function assertSourceBudget(array $info, int $targetSize): void
    {
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width <= 0 || $height <= 0) {
            throw new RuntimeException('Некорректные размеры изображения аватара');
        }

        $maxPixelsRaw = trim((string) (getenv('PROFILE_AVATAR_MAX_PIXELS') ?: ''));
        $maxPixels = ctype_digit($maxPixelsRaw) && (int) $maxPixelsRaw > 0
            ? (int) $maxPixelsRaw
            : self::DEFAULT_MAX_SOURCE_PIXELS;

        if ($width > intdiv($maxPixels, max(1, $height))) {
            throw new RuntimeException(
                sprintf(
                    'Изображение аватара слишком велико для обработки: %dx%d пикселей, лимит %d пикселей',
                    $width,
                    $height,
                    $maxPixels
                )
            );
        }

        $memoryLimit = HostingCompatibility::memoryLimitBytes();
        if ($memoryLimit === null) {
            return;
        }

        $sourcePixels = $width * $height;
        $targetPixels = $targetSize * $targetSize;
        $estimated = ($sourcePixels + $targetPixels) * self::DECODE_BYTES_PER_PIXEL
            + self::MEMORY_SAFETY_BYTES;
        $available = max(0, $memoryLimit - memory_get_usage(true));

        if ($estimated > $available) {
            throw new RuntimeException(
                'Недостаточно memory_limit для безопасного декодирования этого изображения аватара'
            );
        }
    }

    private function load(string $source, int $type): GdImage
    {
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp')
                ? @imagecreatefromwebp($source)
                : false,
            default => false,
        };

        if (!$image instanceof GdImage) {
            throw new RuntimeException('Не удалось декодировать изображение аватара');
        }

        return $image;
    }
}
