<?php

declare(strict_types=1);

namespace App\Helpers;

require_once dirname(__DIR__, 3) . '/core/Environment.php';

use SensitiveParameter;

/**
 * Версионированное шифрование текста сообщений Messenger.
 *
 * Формат v2: "v2:" + base64(24-byte nonce || XChaCha20-Poly1305 ciphertext).
 * AAD привязывает шифротекст к неизменяемому UID сообщения.
 *
 * Старые форматы доступны только для миграционного чтения. Новые записи не
 * используют AES-CBC и встроенный либо запасной ключ.
 */
final class MessengerCrypto
{
    private const PREFIX = 'v2:';
    private const AAD_PREFIX = "notes-messenger-message-v2\0";
    private const COMPAT_AAD = 'notes-messenger-v2';

    private static function key(): string
    {
        return self::keyFromSecret((string) (\Core\Environment::get('MSG_SECRET_KEY') ?: ''));
    }

    private static function keyFromSecret(#[SensitiveParameter] string $secret): string
    {
        $secret = trim($secret);
        if (strlen($secret) < 32) {
            throw new \RuntimeException('MSG_SECRET_KEY должен содержать не менее 32 символов');
        }

        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new \RuntimeException('Для Messenger требуется поддержка libsodium XChaCha20-Poly1305');
        }

        return hash('sha256', $secret, true);
    }

    public static function isCurrentPayload(string $payload): bool
    {
        return str_starts_with($payload, self::PREFIX);
    }

    public static function encrypt(
        #[SensitiveParameter] string $plaintext,
        string $messageUid
    ): string {
        return self::encryptWithKey($plaintext, $messageUid, self::key());
    }

    /** Служебная операция только для ротации мастер-ключа. */
    public static function encryptWithSecret(
        #[SensitiveParameter] string $plaintext,
        string $messageUid,
        #[SensitiveParameter] string $secret
    ): string {
        return self::encryptWithKey($plaintext, $messageUid, self::keyFromSecret($secret));
    }

    /** Служебная расшифровка текущего формата только для ротации мастер-ключа. */
    public static function decryptCurrentWithSecret(
        #[SensitiveParameter] string $payload,
        string $messageUid,
        #[SensitiveParameter] string $secret
    ): string {
        if (!self::isCurrentPayload($payload)) {
            throw new \RuntimeException(
                'Шифротекст Messenger не относится к текущему формату v2; сначала выполните migrate_crypto.php'
            );
        }

        return self::decryptV2(
            substr($payload, strlen(self::PREFIX)),
            $messageUid,
            self::keyFromSecret($secret)
        );
    }

    private static function encryptWithKey(
        #[SensitiveParameter] string $plaintext,
        string $messageUid,
        #[SensitiveParameter] string $key
    ): string {
        if ($messageUid === '') {
            throw new \InvalidArgumentException('Для шифрования требуется UID сообщения');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            self::AAD_PREFIX . $messageUid,
            $nonce,
            $key
        );

        return self::PREFIX . base64_encode($nonce . $ciphertext);
    }

    public static function decrypt(
        #[SensitiveParameter] string $payload,
        string $messageUid
    ): string {
        if ($messageUid === '') {
            throw new \InvalidArgumentException('Для расшифровки требуется UID сообщения');
        }

        if (str_starts_with($payload, self::PREFIX)) {
            return self::decryptV2(substr($payload, strlen(self::PREFIX)), $messageUid, self::key());
        }

        // Переходный формат, который короткое время создавался совместимым
        // слоем audit-hardening: base64(16-byte seed || base64(aead ciphertext)).
        $compat = self::decryptTransitionPayload($payload);
        if ($compat !== null) {
            return $compat;
        }

        // Записи AES-CBC до v2 читаются только когда оператор явно задаёт
        // исторический ключ на время миграции.
        $legacy = self::decryptLegacyAesCbc($payload);
        if ($legacy !== null) {
            return $legacy;
        }

        throw new \RuntimeException('Не удалось подтвердить подлинность или расшифровать сообщение Messenger');
    }

    private static function decryptV2(
        #[SensitiveParameter] string $encoded,
        string $messageUid,
        #[SensitiveParameter] string $key
    ): string {
        $raw = base64_decode($encoded, true);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if ($raw === false || strlen($raw) <= $nonceLength) {
            throw new \RuntimeException('Некорректный шифротекст Messenger');
        }

        $nonce = substr($raw, 0, $nonceLength);
        $ciphertext = substr($raw, $nonceLength);
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            self::AAD_PREFIX . $messageUid,
            $nonce,
            $key
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Не удалось подтвердить подлинность сообщения Messenger');
        }

        return $plaintext;
    }

    private static function decryptTransitionPayload(#[SensitiveParameter] string $payload): ?string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) <= 16) {
            return null;
        }

        $seed = substr($raw, 0, 16);
        $encodedCiphertext = substr($raw, 16);
        $ciphertext = base64_decode($encodedCiphertext, true);
        if ($ciphertext === false) {
            return null;
        }

        $nonce = substr(
            hash('sha256', "notes-messenger-nonce-v2\0" . $seed, true),
            0,
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
        );

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            self::COMPAT_AAD,
            $nonce,
            self::key()
        );

        return $plaintext === false ? null : $plaintext;
    }

    private static function decryptLegacyAesCbc(#[SensitiveParameter] string $payload): ?string
    {
        $legacyKey = (string) (getenv('MSG_LEGACY_SECRET_KEY') ?: '');
        if ($legacyKey === '') {
            return null;
        }

        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) <= 16) {
            return null;
        }

        $iv = substr($raw, 0, 16);
        $encodedCiphertext = substr($raw, 16);
        $plaintext = \openssl_decrypt(
            $encodedCiphertext,
            'aes-256-cbc',
            $legacyKey,
            0,
            $iv
        );

        return $plaintext === false ? null : $plaintext;
    }
}
