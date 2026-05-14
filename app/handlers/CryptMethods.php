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
     * Master key из env.
     * Должен быть минимум 32 байта случайных данных.
     */
    private static ?string $masterKey = null;
    
    /**
     * Получение master key.
     */
    private static function getMasterKey(): string {
        if (self::$masterKey !== null) {
            return self::$masterKey;
        }

        $key = $_ENV['UNIQUE_KEY'] ?? getenv('UNIQUE_KEY');

        // Явно приводим к строке или используем пустую строку, если false
        if ($key === false || $key === '') {
            $key = '';
        }

        if ($key === '') {
            throw new \RuntimeException('UNIQUE_KEY is missing');
        }

        /**
         * Нормализуем ключ через SHA-256.
         * Получаем ровно 32 байта.
         */
        self::$masterKey = hash('sha256', $key, true);

        return self::$masterKey;
    }

    /**
     * HKDF derivation.
     */
    private static function deriveKey(string $purpose): string
    {
        return hash_hkdf(
            'sha256',
            self::getMasterKey(),
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $purpose
        );
    }
    
    // =========================================================
    // PASSWORDS
    // =========================================================

    /**
     * Хеширование пароля.
     */
    public static function hashPassword(string $password): string
    {
        return password_hash(
            $password,
            PASSWORD_ARGON2ID,
            [
                'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
                'time_cost'   => PASSWORD_ARGON2_DEFAULT_TIME_COST,
                'threads'     => PASSWORD_ARGON2_DEFAULT_THREADS,
            ]
        );
    }

    /**
     * Проверка пароля.
     */
    public static function verifyPassword(
        string $password,
        string $hash
    ): bool {
        return password_verify($password, $hash);
    }

    /**
     * Нужно ли перехешировать пароль.
     */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash(
            $hash,
            PASSWORD_ARGON2ID
        );
    }
    
    // =========================================================
    // ENCRYPTION
    // =========================================================

    /**
     * Шифрование данных.
     */
    public static function encrypt(
        string $plaintext,
        string $aad = ''
    ): string {
        $key = self::deriveKey('app-data-encryption');

        /**
         * XChaCha20 использует 24-byte nonce.
         * Можно безопасно использовать random_bytes().
         */
        $nonce = random_bytes(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
        );

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $aad,
            $nonce,
            $key
        );

        /**
         * Versioned payload.
         */
        $payload = json_encode([
            'v' => 1,
            'n' => base64_encode($nonce),
            'c' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR);

        return base64_encode($payload);
    }

    /**
     * Расшифровка данных.
     */
    public static function decrypt(
        string $payload,
        string $aad = ''
    ): string {
        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid payload');
        }

        $data = json_decode(
            $decoded,
            true,
            flags: JSON_THROW_ON_ERROR
        );

        if (
            !isset($data['v'], $data['n'], $data['c'])
        ) {
            throw new \InvalidArgumentException(
                'Malformed payload'
            );
        }

        if ($data['v'] !== 1) {
            throw new \RuntimeException(
                'Unsupported payload version'
            );
        }

        $nonce = base64_decode($data['n'], true);
        $ciphertext = base64_decode($data['c'], true);

        if ($nonce === false || $ciphertext === false) {
            throw new \InvalidArgumentException(
                'Invalid payload encoding'
            );
        }

        $key = self::deriveKey('app-data-encryption');

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $aad,
            $nonce,
            $key
        );

        if ($plaintext === false) {
            throw new \RuntimeException(
                'Decryption failed'
            );
        }

        return $plaintext;
    }
}