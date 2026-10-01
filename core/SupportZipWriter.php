<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Минимальный ZIP writer без зависимости от расширения zip.
 *
 * Использует метод STORE без сжатия: диагностические JSON-файлы малы,
 * а совместимость с ограниченным shared hosting важнее коэффициента сжатия.
 */
final class SupportZipWriter
{
    private const LOCAL_FILE_HEADER = 0x04034b50;
    private const CENTRAL_DIRECTORY_HEADER = 0x02014b50;
    private const END_OF_CENTRAL_DIRECTORY = 0x06054b50;
    private const MAX_FILES = 64;
    private const MAX_FILE_BYTES = 4194304;
    private const MAX_ARCHIVE_BYTES = 8388608;

    /** @var list<array{name:string,crc:int,size:int,offset:int}> */
    private array $entries = [];
    private string $bytes = '';

    public function add(string $name, string $contents): void
    {
        if (count($this->entries) >= self::MAX_FILES) {
            throw new RuntimeException('Диагностический ZIP содержит слишком много файлов');
        }
        if (!$this->safeName($name)) {
            throw new RuntimeException('Некорректное имя файла диагностического ZIP');
        }

        $size = strlen($contents);
        if ($size > self::MAX_FILE_BYTES) {
            throw new RuntimeException('Файл диагностического ZIP превышает допустимый размер');
        }

        $crc = $this->unsignedCrc32($contents);
        $offset = strlen($this->bytes);
        $nameLength = strlen($name);

        $this->bytes .= pack(
            'VvvvvvVVVvv',
            self::LOCAL_FILE_HEADER,
            20,
            0x0800,
            0,
            0,
            0,
            $crc,
            $size,
            $size,
            $nameLength,
            0
        );
        $this->bytes .= $name . $contents;

        $this->entries[] = [
            'name' => $name,
            'crc' => $crc,
            'size' => $size,
            'offset' => $offset,
        ];

        if (strlen($this->bytes) > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('Диагностический ZIP превышает допустимый размер');
        }
    }

    public function finish(): string
    {
        $centralOffset = strlen($this->bytes);
        $central = '';

        foreach ($this->entries as $entry) {
            $name = $entry['name'];
            $nameLength = strlen($name);

            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                self::CENTRAL_DIRECTORY_HEADER,
                20,
                20,
                0x0800,
                0,
                0,
                0,
                $entry['crc'],
                $entry['size'],
                $entry['size'],
                $nameLength,
                0,
                0,
                0,
                0,
                0,
                $entry['offset']
            );
            $central .= $name;
        }

        $centralSize = strlen($central);
        $count = count($this->entries);

        $archive = $this->bytes
            . $central
            . pack(
                'VvvvvVVv',
                self::END_OF_CENTRAL_DIRECTORY,
                0,
                0,
                $count,
                $count,
                $centralSize,
                $centralOffset,
                0
            );

        if (strlen($archive) > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('Диагностический ZIP превышает допустимый размер');
        }

        return $archive;
    }

    private function safeName(string $name): bool
    {
        if ($name === '' || strlen($name) > 180 || str_contains($name, '\\')) {
            return false;
        }
        if (str_starts_with($name, '/') || str_contains($name, '../') || str_contains($name, '/..')) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9._\/-]+$/D', $name) === 1;
    }

    private function unsignedCrc32(string $bytes): int
    {
        $hex = hash('crc32b', $bytes);
        $value = hexdec($hex);

        return (int) ($value & 0xffffffff);
    }
}
