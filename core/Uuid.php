<?php

declare(strict_types=1);

namespace Core;

use InvalidArgumentException;

/**
 * Генератор UUID версии 4 по RFC 4122.
 */
final class Uuid
{
    /**
     * Создать UUID v4. Необязательные 16 байт позволяют получать
     * детерминированное значение в проверках без подмены random_bytes().
     */
    public static function v4(?string $data = null): string
    {
        $data = $data ?? random_bytes(16);

        if (strlen($data) !== 16) {
            throw new InvalidArgumentException('Данные UUID должны содержать ровно 16 байт.');
        }

        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Совместимое имя метода для кода, который ещё не переведён на v4().
     */
    public static function guidv4(?string $data = null): string
    {
        return self::v4($data);
    }
}
