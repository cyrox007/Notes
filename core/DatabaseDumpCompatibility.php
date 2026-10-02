<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Throwable;

/**
 * Исправляет известную ошибку старых phpMyAdmin при экспорте descending-индексов MySQL 8.x.
 *
 * Проблемный экспорт теряет имя последней колонки и оставляет bare DESC.
 * Исправление ограничено тремя известными индексами Notes 1.0.14 и не меняет данные.
 */
final class DatabaseDumpCompatibility
{
    /** @return array{replacements:int,bytes:int} */
    public static function repairPhpMyAdminDump(string $inputPath, string $outputPath): array
    {
        $inputPath = trim($inputPath);
        $outputPath = trim($outputPath);
        if ($inputPath === '' || $outputPath === '') {
            throw new RuntimeException('Укажите входной и выходной SQL-файл');
        }
        if (!is_file($inputPath) || is_link($inputPath) || !is_readable($inputPath)) {
            throw new RuntimeException('Входной SQL-файл недоступен для чтения');
        }
        if (file_exists($outputPath) || is_link($outputPath)) {
            throw new RuntimeException('Выходной файл уже существует; исходный дамп не перезаписывается');
        }

        $inputReal = realpath($inputPath);
        $outputDirectoryReal = realpath(dirname($outputPath));
        if (!is_string($inputReal) || !is_string($outputDirectoryReal) || !is_dir($outputDirectoryReal)) {
            throw new RuntimeException('Не удалось безопасно определить пути дампа');
        }

        $candidateOutput = rtrim(str_replace('\\\\', '/', $outputDirectoryReal), '/') . '/' . basename($outputPath);
        if (self::samePath($inputReal, $candidateOutput)) {
            throw new RuntimeException('Исходный дамп нельзя перезаписывать на месте');
        }

        $input = @fopen($inputReal, 'rb');
        if ($input === false) {
            throw new RuntimeException('Не удалось открыть входной SQL-файл');
        }

        $oldUmask = umask(0077);
        $output = @fopen($candidateOutput, 'xb');
        umask($oldUmask);
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('Не удалось создать исправленный SQL-файл');
        }

        $replacements = 0;
        $bytes = 0;

        try {
            while (($line = fgets($input)) !== false) {
                $line = self::repairLine($line, $replacements);
                $length = strlen($line);
                if ($length > 0 && fwrite($output, $line) !== $length) {
                    throw new RuntimeException('Не удалось полностью записать исправленный SQL-файл');
                }
                $bytes += $length;
            }
            if (!feof($input)) {
                throw new RuntimeException('Ошибка чтения исходного SQL-файла');
            }
            if (!fflush($output)) {
                throw new RuntimeException('Не удалось завершить запись исправленного SQL-файла');
            }
            @chmod($candidateOutput, 0600);
        } catch (Throwable $e) {
            fclose($input);
            fclose($output);
            @unlink($candidateOutput);
            throw $e;
        }

        fclose($input);
        fclose($output);

        return ['replacements' => $replacements, 'bytes' => $bytes];
    }

    public static function repairSql(string $sql): string
    {
        $replacements = 0;
        return self::repairLine($sql, $replacements);
    }

    private static function repairLine(string $line, int &$replacements): string
    {
        $pairs = [
            'ADD KEY `idx_user_notes` (`user_id`, `is_deleted`, DESC)'
                => 'ADD KEY `idx_user_notes` (`user_id`, `is_deleted`, `created_note`)',
            'ADD KEY `idx_updated_notes` (`user_id`, DESC)'
                => 'ADD KEY `idx_updated_notes` (`user_id`, `updated_note`)',
            'ADD KEY `idx_notes_profile_public` (`user_id`, `is_profile_public`, `is_deleted`, DESC)'
                => 'ADD KEY `idx_notes_profile_public` (`user_id`, `is_profile_public`, `is_deleted`, `updated_note`)',
        ];

        foreach ($pairs as $broken => $fixed) {
            $line = str_replace($broken, $fixed, $line, $count);
            $replacements += $count;
        }

        return $line;
    }

    private static function samePath(string $left, string $right): bool
    {
        $left = str_replace('\\\\', '/', $left);
        $right = str_replace('\\\\', '/', $right);
        if (PHP_OS_FAMILY === 'Windows') {
            $left = strtolower($left);
            $right = strtolower($right);
        }
        return rtrim($left, '/') === rtrim($right, '/');
    }
}
