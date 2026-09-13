<?php

declare(strict_types=1);

/**
 * Генератор UUID версии 4 согласно RFC 4122.
 */
class UUID
{
    /**
     * Каноническое имя метода, используемое новыми модулями.
     */
    public static function v4(?string $data = null): string
    {
        return self::guidv4($data);
    }

    /**
     * Старое имя оставлено для обратной совместимости.
     *
     * @throws \InvalidArgumentException если передано не 16 байт данных.
     */
    public static function guidv4(?string $data = null): string
    {
        $data = $data ?? random_bytes(16);

        if (strlen($data) !== 16) {
            throw new \InvalidArgumentException('Data must be exactly 16 bytes');
        }

        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
