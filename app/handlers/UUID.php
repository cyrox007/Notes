<?php

declare(strict_types=1);

/**
 * Генератор UUID версии 4
 * 
 * Класс для создания уникальных идентификаторов (UUID) версии 4
 * согласно RFC 4122.
 */
class UUID
{
    /**
     * Генерирует UUID версии 4
     * 
     * @param string|null $data Данные для генерации UUID (если не переданы, генерируются случайные)
     * @return string UUID формата xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     * @throws \Exception Если длина данных не равна 16 байтам
     */
    public static function guidv4(?string $data = null): string
    {
        // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
        $data = $data ?? random_bytes(16);
        
        if (strlen($data) !== 16) {
            throw new \Exception('Data must be exactly 16 bytes');
        }
    
        // Set version to 0100 (version 4)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    
        // Output the 36 character UUID.
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Алиас для guidv4() - генерирует UUID версии 4
     * 
     * @return string UUID формата xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     */
    public static function v4(): string
    {
        return self::guidv4();
    }
}