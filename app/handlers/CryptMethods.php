<?php

declare(strict_types=1);

namespace App\Helpers;

use SensitiveParameter;

/**
 * Критический сбой криптографии, который не даёт старым catch(Exception)
 * незаметно понизить защищённые данные до открытого текста.
 */
final class CryptographicFailure extends \Error
{
}

/**
 * Криптографические операции для паролей и защищённых данных приложения.
 */
class CryptMethods
{
    private static ?string $masterKey = null;

    private static function getMasterKey(): string
    {
        if (self::$masterKey !== null) {
            return self::$masterKey;
        }

        $key = $_ENV['UNIQUE_KEY'] ?? getenv('UNIQUE_KEY');
        $key = is_string($key) ? trim($key) : '';

        if (strlen($key) < 32) {
            throw new CryptographicFailure('UNIQUE_KEY должен содержать не менее 32 символов');
        }

        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new CryptographicFailure('Для шифрования требуется поддержка libsodium XChaCha20-Poly1305');
        }

        self::$masterKey = hash('sha256', $key, true);
        return self::$masterKey;
    }

    private static function deriveKey(string $purpose): string
    {
        return hash_hkdf(
            'sha256',
            self::getMasterKey(),
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $purpose
        );
    }

    public static function hashPassword(#[SensitiveParameter] string $password): string
    {
        return password_hash(
            $password,
            PASSWORD_ARGON2ID,
            [
                'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
                'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST,
                'threads' => PASSWORD_ARGON2_DEFAULT_THREADS,
            ]
        );
    }

    public static function verifyPassword(
        #[SensitiveParameter] string $password,
        #[SensitiveParameter] string $hash
    ): bool {
        return password_verify($password, $hash);
    }

    public static function needsRehash(#[SensitiveParameter] string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }

    public static function encrypt(#[SensitiveParameter] string $plaintext, string $aad = ''): string
    {
        return self::encryptWithDerivedKey($plaintext, $aad, self::deriveKey('app-data-encryption'));
    }

    /**
     * Служебная операция только для ротации ключей данных.
     * Обычные записи приложения должны продолжать использовать encrypt().
     */
    public static function encryptWithSecret(
        #[SensitiveParameter] string $plaintext,
        string $aad,
        #[SensitiveParameter] string $secret
    ): string {
        return self::encryptWithDerivedKey(
            $plaintext,
            $aad,
            self::deriveKeyFromSecret($secret, 'app-data-encryption')
        );
    }

    public static function decrypt(#[SensitiveParameter] string $payload, string $aad = ''): string
    {
        return self::decryptWithDerivedKey($payload, $aad, self::deriveKey('app-data-encryption'));
    }

    /**
     * Служебная операция ротации, проверяющая шифротекст явно переданным
     * старым или новым секретом.
     */
    public static function decryptWithSecret(
        #[SensitiveParameter] string $payload,
        string $aad,
        #[SensitiveParameter] string $secret
    ): string {
        return self::decryptWithDerivedKey(
            $payload,
            $aad,
            self::deriveKeyFromSecret($secret, 'app-data-encryption')
        );
    }

    public static function isCurrentPayload(string $payload): bool
    {
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return false;
        }

        try {
            $data = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }

        return is_array($data)
            && ($data['v'] ?? null) === 1
            && isset($data['n'], $data['c'])
            && is_string($data['n'])
            && is_string($data['c']);
    }

    private static function deriveKeyFromSecret(
        #[SensitiveParameter] string $secret,
        string $purpose
    ): string {
        $secret = trim($secret);
        if (strlen($secret) < 32) {
            throw new CryptographicFailure('Явный секрет шифрования данных должен содержать не менее 32 символов');
        }
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new CryptographicFailure('Для шифрования требуется поддержка libsodium XChaCha20-Poly1305');
        }

        return hash_hkdf(
            'sha256',
            hash('sha256', $secret, true),
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $purpose
        );
    }

    private static function encryptWithDerivedKey(
        #[SensitiveParameter] string $plaintext,
        string $aad,
        #[SensitiveParameter] string $key
    ): string {
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                $aad,
                $nonce,
                $key
            );

            $payload = json_encode([
                'v' => 1,
                'n' => base64_encode($nonce),
                'c' => base64_encode($ciphertext),
            ], JSON_THROW_ON_ERROR);

            return base64_encode($payload);
        } catch (CryptographicFailure $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CryptographicFailure('Не удалось зашифровать данные', 0, $e);
        }
    }

    private static function decryptWithDerivedKey(
        #[SensitiveParameter] string $payload,
        string $aad,
        #[SensitiveParameter] string $key
    ): string {
        try {
            $decoded = base64_decode($payload, true);
            if ($decoded === false) {
                throw new \RuntimeException('Некорректная кодировка шифротекста');
            }

            $data = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($data) || !isset($data['v'], $data['n'], $data['c'])) {
                throw new \RuntimeException('Некорректная структура шифротекста');
            }
            if ($data['v'] !== 1) {
                throw new \RuntimeException('Версия шифротекста не поддерживается');
            }

            $nonce = base64_decode((string) $data['n'], true);
            $ciphertext = base64_decode((string) $data['c'], true);
            if ($nonce === false || $ciphertext === false) {
                throw new \RuntimeException('Некорректные поля шифротекста');
            }
            if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
                throw new \RuntimeException('Некорректная длина nonce');
            }

            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $ciphertext,
                $aad,
                $nonce,
                $key
            );
            if ($plaintext === false) {
                throw new \RuntimeException('Не удалось подтвердить подлинность сообщения');
            }

            return $plaintext;
        } catch (CryptographicFailure $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CryptographicFailure('Не удалось расшифровать данные', 0, $e);
        }
    }
}
