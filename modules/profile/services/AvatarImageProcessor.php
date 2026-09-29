<?php

declare(strict_types=1);

namespace App\Services;

use GdImage;
use RuntimeException;

/**
 * Обработка изображения аватара принадлежит модулю Profile, а не Core.
 */
final class AvatarImageProcessor
{
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
