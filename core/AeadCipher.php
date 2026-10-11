<?php

declare(strict_types=1);

namespace Core;

use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

/**
 * Общий адаптер для аутентифицированного шифрования XChaCha20-Poly1305.
 *
 * Формат запечатанного значения: nonce || ciphertext. Кодирование и версия
 * формата остаются ответственностью вызывающего слоя.
 */
final class AeadCipher
{
    public static function seal(
        #[SensitiveParameter] string $plaintext,
        string $additionalData,
        #[SensitiveParameter] string $key
    ): string {
        self::assertAvailable();
        self::assertKey($key);

        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $additionalData,
            $nonce,
            $key
        );

        return $nonce . $ciphertext;
    }

    public static function open(
        #[SensitiveParameter] string $payload,
        string $additionalData,
        #[SensitiveParameter] string $key
    ): string|false {
        self::assertAvailable();
        self::assertKey($key);

        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($payload) <= $nonceLength) {
            return false;
        }

        return self::openWithNonce(
            substr($payload, $nonceLength),
            $additionalData,
            substr($payload, 0, $nonceLength),
            $key
        );
    }

    public static function openWithNonce(
        #[SensitiveParameter] string $ciphertext,
        string $additionalData,
        #[SensitiveParameter] string $nonce,
        #[SensitiveParameter] string $key
    ): string|false {
        self::assertAvailable();
        self::assertKey($key);

        if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new InvalidArgumentException('Некорректная длина nonce для XChaCha20-Poly1305');
        }

        return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $additionalData,
            $nonce,
            $key
        );
    }

    private static function assertAvailable(): void
    {
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new RuntimeException('Требуется поддержка libsodium XChaCha20-Poly1305');
        }
    }

    private static function assertKey(#[SensitiveParameter] string $key): void
    {
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new InvalidArgumentException('Некорректная длина ключа XChaCha20-Poly1305');
        }
    }
}
