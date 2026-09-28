<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class Images
{
    private mixed $image = null;
    private ?int $imageType = null;

    public static function loadImage(string $file): self
    {
        $handler = new self();
        $handler->load($file);

        return $handler;
    }

    public function processImage(int $width, int $height): self
    {
        if ($width <= 0 || $height <= 0) {
            throw new RuntimeException('Некорректный размер изображения');
        }

        if ($height !== $this->getHeight() || $width !== $this->getWidth()) {
            $this->resize($width, $height);
        }

        return $this;
    }

    public function saveImage(
        string $filename,
        int|string $imageType = IMAGETYPE_JPEG,
        int $compression = 75,
        ?int $permissions = null
    ): void {
        $saved = match ($imageType) {
            IMAGETYPE_JPEG => imagejpeg($this->image, $filename, $compression),
            IMAGETYPE_GIF => imagegif($this->image, $filename),
            IMAGETYPE_PNG => imagepng($this->image, $filename),
            IMAGETYPE_WEBP => imagewebp($this->image, $filename, $compression),
            default => false,
        };

        if ($saved !== true) {
            throw new RuntimeException('Не удалось сохранить обработанное изображение');
        }

        if ($permissions !== null && !@chmod($filename, $permissions)) {
            throw new RuntimeException('Не удалось применить права к изображению');
        }
    }

    private function load(string $filename): void
    {
        if (!is_file($filename) || !is_readable($filename)) {
            throw new RuntimeException('Файл изображения отсутствует или недоступен для чтения');
        }

        $imageInfo = @getimagesize($filename);
        if (!is_array($imageInfo) || !isset($imageInfo[2])) {
            throw new RuntimeException('Не удалось определить тип изображения');
        }

        $this->imageType = (int) $imageInfo[2];
        $this->image = match ($this->imageType) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($filename),
            IMAGETYPE_GIF => @imagecreatefromgif($filename),
            IMAGETYPE_PNG => @imagecreatefrompng($filename),
            IMAGETYPE_WEBP => @imagecreatefromwebp($filename),
            default => false,
        };

        if ($this->image === false) {
            throw new RuntimeException('Не удалось загрузить поддерживаемое изображение');
        }
    }

    private function getWidth(): int
    {
        $width = imagesx($this->image);
        if (!is_int($width) || $width <= 0) {
            throw new RuntimeException('Не удалось определить ширину изображения');
        }

        return $width;
    }

    private function getHeight(): int
    {
        $height = imagesy($this->image);
        if (!is_int($height) || $height <= 0) {
            throw new RuntimeException('Не удалось определить высоту изображения');
        }

        return $height;
    }

    private function resize(int $width, int $height): void
    {
        $newImage = imagecreatetruecolor($width, $height);
        if ($newImage === false) {
            throw new RuntimeException('Не удалось создать буфер изображения');
        }

        $copied = imagecopyresampled(
            $newImage,
            $this->image,
            0,
            0,
            0,
            0,
            $width,
            $height,
            $this->getWidth(),
            $this->getHeight()
        );

        if ($copied !== true) {
            imagedestroy($newImage);
            throw new RuntimeException('Не удалось изменить размер изображения');
        }

        if ($this->image !== null) {
            @imagedestroy($this->image);
        }
        $this->image = $newImage;
    }
}
