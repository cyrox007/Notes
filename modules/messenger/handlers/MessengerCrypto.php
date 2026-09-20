<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Versioned encryption for messenger message bodies.
 *
 * v2 payload: "v2:" + base64(24-byte nonce || XChaCha20-Poly1305 ciphertext)
 * AAD binds ciphertext to the immutable message UID.
 *
 * Legacy formats are read-only migration paths. New writes never use AES-CBC
 * and never use an embedded/default key.
 */
final class MessengerCrypto
{
    private const PREFIX = 'v2:';
    private const AAD_PREFIX = "notes-messenger-message-v2\0";
    private const COMPAT_AAD = 'notes-messenger-v2';

    private static function key(): string
    {
        return self::keyFromSecret((string) (getenv('MSG_SECRET_KEY') ?: ''));
    }

    private static function keyFromSecret(string $secret): string
    {
        $secret = trim($secret);
        if (strlen($secret) < 32) {
            throw new \RuntimeException('MSG_SECRET_KEY must contain at least 32 characters');
        }

        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new \RuntimeException('libsodium XChaCha20-Poly1305 support is required');
        }

        return hash('sha256', $secret, true);
    }

    public static function isCurrentPayload(string $payload): bool
    {
        return str_starts_with($payload, self::PREFIX);
    }

    public static function encrypt(string $plaintext, string $messageUid): string
    {
        return self::encryptWithKey($plaintext, $messageUid, self::key());
    }

    /** Maintenance-only primitive for master-key rotation. */
    public static function encryptWithSecret(string $plaintext, string $messageUid, string $secret): string
    {
        return self::encryptWithKey($plaintext, $messageUid, self::keyFromSecret($secret));
    }

    /** Maintenance-only current-format decrypt for master-key rotation. */
    public static function decryptCurrentWithSecret(string $payload, string $messageUid, string $secret): string
    {
        if (!self::isCurrentPayload($payload)) {
            throw new \RuntimeException('Messenger payload is not current v2 format; run migrate_crypto.php first');
        }

        return self::decryptV2(substr($payload, strlen(self::PREFIX)), $messageUid, self::keyFromSecret($secret));
    }

    private static function encryptWithKey(string $plaintext, string $messageUid, string $key): string
    {
        if ($messageUid === '') {
            throw new \InvalidArgumentException('Message UID is required for encryption');
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

    public static function decrypt(string $payload, string $messageUid): string
    {
        if ($messageUid === '') {
            throw new \InvalidArgumentException('Message UID is required for decryption');
        }

        if (str_starts_with($payload, self::PREFIX)) {
            return self::decryptV2(substr($payload, strlen(self::PREFIX)), $messageUid, self::key());
        }

        // Transitional payload produced by the short-lived compatibility shim
        // on audit-hardening: base64(16-byte seed || base64(aead ciphertext)).
        $compat = self::decryptTransitionPayload($payload);
        if ($compat !== null) {
            return $compat;
        }

        // Pre-v2 AES-CBC records can only be read when an operator explicitly
        // supplies the historic key during migration.
        $legacy = self::decryptLegacyAesCbc($payload);
        if ($legacy !== null) {
            return $legacy;
        }

        throw new \RuntimeException('Messenger message authentication/decryption failed');
    }

    private static function decryptV2(string $encoded, string $messageUid, string $key): string
    {
        $raw = base64_decode($encoded, true);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if ($raw === false || strlen($raw) <= $nonceLength) {
            throw new \RuntimeException('Malformed messenger ciphertext');
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
            throw new \RuntimeException('Messenger message authentication failed');
        }

        return $plaintext;
    }

    private static function decryptTransitionPayload(string $payload): ?string
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

    private static function decryptLegacyAesCbc(string $payload): ?string
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
