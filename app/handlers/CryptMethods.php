<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Fatal cryptographic failure used to prevent legacy catch(Exception) blocks
 * from silently downgrading encrypted data to plaintext.
 */
final class CryptographicFailure extends \Error
{
}

/**
 * Cryptographic helpers for passwords and encrypted application data.
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
            throw new CryptographicFailure('UNIQUE_KEY must contain at least 32 characters');
        }

        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new CryptographicFailure('libsodium XChaCha20-Poly1305 support is required');
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

    public static function hashPassword(string $password): string
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

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }

    public static function encrypt(string $plaintext, string $aad = ''): string
    {
        try {
            $key = self::deriveKey('app-data-encryption');
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
            throw new CryptographicFailure('Encryption failed', 0, $e);
        }
    }

    public static function decrypt(string $payload, string $aad = ''): string
    {
        try {
            $decoded = base64_decode($payload, true);
            if ($decoded === false) {
                throw new \RuntimeException('Invalid payload encoding');
            }

            $data = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($data) || !isset($data['v'], $data['n'], $data['c'])) {
                throw new \RuntimeException('Malformed encrypted payload');
            }

            if ($data['v'] !== 1) {
                throw new \RuntimeException('Unsupported encrypted payload version');
            }

            $nonce = base64_decode((string) $data['n'], true);
            $ciphertext = base64_decode((string) $data['c'], true);
            if ($nonce === false || $ciphertext === false) {
                throw new \RuntimeException('Invalid encrypted payload fields');
            }

            if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
                throw new \RuntimeException('Invalid nonce length');
            }

            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $ciphertext,
                $aad,
                $nonce,
                self::deriveKey('app-data-encryption')
            );

            if ($plaintext === false) {
                throw new \RuntimeException('Message authentication failed');
            }

            return $plaintext;
        } catch (CryptographicFailure $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Fail closed. In particular, legacy NoteController catches only
            // Exception; an Error prevents malformed/plaintext content marked as
            // encrypted from being rendered as though decryption succeeded.
            throw new CryptographicFailure('Decryption failed', 0, $e);
        }
    }
}
