<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Класс для многоуровневого шифрования данных
 * 
 * Реализует концепцию "двойной криптографии":
 * 1. Первое шифрование основным алгоритмом (AES-256-GCM или bcrypt для паролей)
 * 2. Второе шифрование другим алгоритмом с уникальным ключом
 * 
 * Используется для:
 * - Шифрования заметок пользователей перед сохранением в БД
 * - Шифрования сообщений между пользователями
 * - Многоуровневого хеширования паролей
 * 
 * @package App\Helpers
 */
class CryptMethods
{
    /**
     * @var string Уникальный ключ шифрования из переменных окружения
     */
    private static string $uniqueKey = '';
    
    /**
     * @var string Второй ключ для двойного шифрования
     */
    private static string $secondaryKey = '';
    
    /**
     * Инициализация ключей шифрования
     * 
     * @return void
     * @throws \RuntimeException Если ключи не установлены в переменных окружения
     */
    private static function initKeys(): void
    {
        if (self::$uniqueKey === '') {
            self::$uniqueKey = getenv('UNIQUE_KEY');
            if (self::$uniqueKey === false || self::$uniqueKey === '') {
                throw new \RuntimeException('UNIQUE_KEY environment variable is not set');
            }
        }
        
        if (self::$secondaryKey === '') {
            self::$secondaryKey = getenv('SECONDARY_KEY');
            if (self::$secondaryKey === false || self::$secondaryKey === '') {
                // Генерируем вторичный ключ на основе UNIQUE_KEY если он не установлен
                self::$secondaryKey = hash('sha256', self::$uniqueKey . '_secondary_salt', true);
            }
        }
    }
    
    /**
     * Создает многоуровневый хеш из пароля пользователя
     * 
     * Алгоритм:
     * 1. bcrypt для базового хеширования
     * 2. SHA256 с уникальным ключом и солью
     * 3. 1000 раундов SHA256 для дополнительной защиты
     * 
     * @param string $password Исходный пароль
     * @return string Закодированный хеш в формате base64(bcrypt:salt:finalHash)
     * @throws \RuntimeException Если переменная окружения UNIQUE_KEY не установлена
     */
    public static function createHashFromPassword(string $password): string
    {
        self::initKeys();
        
        // Создаем случайную соль
        $salt = bin2hex(random_bytes(16));
        
        // Первый уровень: bcrypt
        $bcryptHash = password_hash($password, PASSWORD_BCRYPT);
        
        // Комбинируем bcrypt хеш, уникальный ключ и соль
        $combined = $bcryptHash . self::$uniqueKey . $salt;
        
        // Второй уровень: SHA256 с уникальным ключом
        $hash = hash('sha256', $combined);
        
        // Третий уровень: 1000 раундов SHA256
        for ($i = 0; $i < 1000; $i++) {
            $hash = hash('sha256', $hash);
        }
        
        // Кодируем все компоненты вместе
        return base64_encode($bcryptHash . ':' . $salt . ':' . $hash);
    }

    /**
     * Проверяет соответствие пароля сохраненному хешу
     * 
     * @param string $password Введенный пароль для проверки
     * @param string $storedHash Сохраненный хеш из базы данных
     * @return bool true если пароль совпадает, false иначе
     * @throws \RuntimeException Если переменная окружения UNIQUE_KEY не установлена
     */
    public static function verifyPassword(string $password, string $storedHash): bool
    {
        self::initKeys();
        
        // Декодируем сохраненный хеш
        $decodedHash = base64_decode($storedHash, true);
        
        if ($decodedHash === false) {
            return false;
        }
        
        $parts = explode(':', $decodedHash);
        
        if (count($parts) !== 3) {
            return false;
        }
        
        [$storedBcryptHash, $salt, $storedFinalHash] = $parts;
    
        // Проверяем первый уровень (bcrypt)
        if (!password_verify($password, $storedBcryptHash)) {
            return false;
        }
        
        // Воспроизводим второй и третий уровни
        $combined = $storedBcryptHash . self::$uniqueKey . $salt;
        $inputHash = hash('sha256', $combined);
        
        for ($i = 0; $i < 1000; $i++) {
            $inputHash = hash('sha256', $inputHash);
        }
    
        // Сравниваем финальные хеши
        return hash_equals($inputHash, $storedFinalHash);
    }
    
    /**
     * Двойное шифрование данных для заметок и сообщений
     * 
     * Алгоритм:
     * 1. Первое шифрование: AES-256-GCM с уникальным ключом и вектором инициализации
     * 2. Второе шифрование: ChaCha20-Poly1305 (или AES-256-CBC) с вторичным ключом
     * 
     * @param string $data Исходные данные для шифрования
     * @param string|null $context Дополнительный контекст для генерации уникального IV (например, UID записи)
     * @return string Зашифрованные данные в формате base64(iv1:tag1:encryptedData:iv2:tag2:doublyEncryptedData)
     * @throws \RuntimeException Если не удалось выполнить шифрование
     * @throws \LengthException Если данные слишком большие для шифрования
     */
    public static function doubleEncrypt(string $data, ?string $context = null): string
    {
        self::initKeys();
        
        // Генерируем уникальный IV на основе контекста или случайно
        $iv1 = $context !== null 
            ? substr(hash('sha256', self::$uniqueKey . $context, true), 0, 12)
            : random_bytes(12);
        
        // Первый уровень шифрования: AES-256-GCM
        $encrypted1 = openssl_encrypt(
            $data,
            'aes-256-gcm',
            self::$uniqueKey,
            OPENSSL_RAW_DATA,
            $iv1,
            $tag1
        );
        
        if ($encrypted1 === false) {
            throw new \RuntimeException('First level encryption failed: ' . openssl_error_string());
        }
        
        // Второй уровень: используем другой алгоритм (AES-256-CBC с вторичным ключом)
        $iv2 = random_bytes(16);
        $encrypted2 = openssl_encrypt(
            $encrypted1,
            'aes-256-cbc',
            self::$secondaryKey,
            OPENSSL_RAW_DATA,
            $iv2
        );
        
        if ($encrypted2 === false) {
            throw new \RuntimeException('Second level encryption failed: ' . openssl_error_string());
        }
        
        // Формируем итоговый пакет: iv1:tag1:encrypted1:iv2:encrypted2
        // Кодируем каждый компонент отдельно для безопасного хранения
        return base64_encode(
            json_encode([
                'iv1' => base64_encode($iv1),
                'tag1' => base64_encode($tag1 ?? ''),
                'enc1' => base64_encode($encrypted1),
                'iv2' => base64_encode($iv2),
                'enc2' => base64_encode($encrypted2)
            ])
        );
    }
    
    /**
     * Двойная расшифровка данных
     * 
     * Выполняет расшифровку в обратном порядке:
     * 1. Расшифровка второго уровня (AES-256-CBC)
     * 2. Расшифровка первого уровня (AES-256-GCM)
     * 
     * @param string $encryptedData Зашифрованные данные из базы данных
     * @param string|null $context Контекст, использованный при шифровании (для восстановления IV1)
     * @return string Расшифрованные исходные данные
     * @throws \RuntimeException Если не удалось выполнить расшифровку
     * @throws \InvalidArgumentException Если формат данных неверен
     */
    public static function doubleDecrypt(string $encryptedData, ?string $context = null): string
    {
        self::initKeys();
        
        // Декодируем и разбираем пакет
        $jsonEncoded = base64_decode($encryptedData);
        
        if ($jsonEncoded === false) {
            throw new \InvalidArgumentException('Invalid encrypted data format: base64 decode failed');
        }
        
        $components = json_decode($jsonEncoded, true);
        
        if (!is_array($components) || !isset($components['iv1'], $components['tag1'], $components['enc1'], $components['iv2'], $components['enc2'])) {
            throw new \InvalidArgumentException('Invalid encrypted data format: missing components');
        }
        
        // Декодируем компоненты
        $iv1 = base64_decode($components['iv1']);
        $tag1 = base64_decode($components['tag1']);
        $encrypted1 = base64_decode($components['enc1']);
        $iv2 = base64_decode($components['iv2']);
        $encrypted2 = base64_decode($components['enc2']);
        
        if ($iv1 === false || $encrypted1 === false || $iv2 === false || $encrypted2 === false) {
            throw new \InvalidArgumentException('Invalid encrypted data format: component decode failed');
        }
        
        // Второй уровень расшифровки (обратный порядок)
        $decrypted1 = openssl_decrypt(
            $encrypted2,
            'aes-256-cbc',
            self::$secondaryKey,
            OPENSSL_RAW_DATA,
            $iv2
        );
        
        if ($decrypted1 === false) {
            throw new \RuntimeException('Second level decryption failed: ' . openssl_error_string());
        }
        
        // Первый уровень расшифровки
        $decrypted = openssl_decrypt(
            $decrypted1,
            'aes-256-gcm',
            self::$uniqueKey,
            OPENSSL_RAW_DATA,
            $iv1,
            $tag1
        );
        
        if ($decrypted === false) {
            throw new \RuntimeException('First level decryption failed: ' . openssl_error_string());
        }
        
        return $decrypted;
    }
    
    /**
     * Быстрое одноуровневое шифрование для временных данных
     * 
     * @param string $data Данные для шифрования
     * @return string Зашифрованные данные в формате base64(iv:tag:ciphertext)
     */
    public static function quickEncrypt(string $data): string
    {
        self::initKeys();
        
        $iv = random_bytes(12);
        
        $encrypted = openssl_encrypt(
            $data,
            'aes-256-gcm',
            self::$uniqueKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        if ($encrypted === false) {
            throw new \RuntimeException('Quick encryption failed: ' . openssl_error_string());
        }
        
        return base64_encode($iv . $tag . $encrypted);
    }
    
    /**
     * Быстрая одноуровневая расшифровка
     * 
     * @param string $encryptedData Зашифрованные данные
     * @return string Расшифрованные данные
     */
    public static function quickDecrypt(string $encryptedData): string
    {
        self::initKeys();
        
        $decoded = base64_decode($encryptedData);
        
        if ($decoded === false || strlen($decoded) < 28) {
            throw new \InvalidArgumentException('Invalid quick encrypted data format');
        }
        
        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);
        
        $decrypted = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            self::$uniqueKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        if ($decrypted === false) {
            throw new \RuntimeException('Quick decryption failed: ' . openssl_error_string());
        }
        
        return $decrypted;
    }
}