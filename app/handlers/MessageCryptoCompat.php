<?php

declare(strict_types=1);

namespace App\Sockets;

/**
 * Compatibility shim for the legacy MessangerSocket crypto calls.
 *
 * MessangerSocket historically called the unqualified openssl_* functions from
 * this namespace. Defining the namespaced variants lets us upgrade writes to
 * authenticated encryption without changing the public socket contract or the
 * database payload shape in the same migration step.
 *
 * New writes:
 *   base64(16-byte random salt || base64(XChaCha20-Poly1305 ciphertext))
 *
 * Reads:
 *   1. XChaCha20-Poly1305 with MSG_SECRET_KEY.
 *   2. Optional legacy AES-256-CBC read-only fallback when
 *      MSG_LEGACY_SECRET_KEY is explicitly configured.
 *
 * There is deliberately no embedded/default secret.
 */

function messengerCryptoKey(): string
{
    $secret = (string) (getenv('MSG_SECRET_KEY') ?: '');

    if (strlen($secret) < 32) {
        throw new \RuntimeException('MSG_SECRET_KEY must contain at least 32 characters');
    }

    if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
        throw new \RuntimeException('libsodium XChaCha20-Poly1305 support is required');
    }

    return hash('sha256', $secret, true);
}

function messengerNonceFromIv(string $iv): string
{
    return substr(
        hash('sha256', "notes-messenger-nonce-v2\0" . $iv, true),
        0,
        SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
    );
}

/**
 * Keep a 16-byte prefix so existing payload framing in MessangerSocket remains
 * stable. The 24-byte XChaCha nonce is deterministically derived from it.
 */
function openssl_cipher_iv_length(string $cipher_algo): int
{
    return 16;
}

function openssl_random_pseudo_bytes(int $length, ?bool &$strong_result = null): string
{
    $strong_result = true;
    return random_bytes($length);
}

function openssl_encrypt(
    string $data,
    string $cipher_algo,
    string $passphrase,
    int $options = 0,
    string $iv = '',
    ?string &$tag = null,
    string $aad = '',
    int $tag_length = 16
): string|false {
    if (strlen($iv) !== 16) {
        throw new \RuntimeException('Invalid messenger crypto IV length');
    }

    $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
        $data,
        'notes-messenger-v2',
        messengerNonceFromIv($iv),
        messengerCryptoKey()
    );

    return base64_encode($ciphertext);
}

function openssl_decrypt(
    string $data,
    string $cipher_algo,
    string $passphrase,
    int $options = 0,
    string $iv = '',
    string $tag = '',
    string $aad = ''
): string|false {
    if (strlen($iv) !== 16) {
        throw new \RuntimeException('Invalid messenger crypto IV length');
    }

    $decoded = base64_decode($data, true);
    if ($decoded !== false) {
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $decoded,
            'notes-messenger-v2',
            messengerNonceFromIv($iv),
            messengerCryptoKey()
        );

        if ($plaintext !== false) {
            return $plaintext;
        }
    }

    // Transitional read-only path for pre-migration AES-CBC records.
    // Operators must opt in explicitly; the historic hard-coded fallback key is
    // intentionally never assumed here.
    $legacyKey = (string) (getenv('MSG_LEGACY_SECRET_KEY') ?: '');
    if ($legacyKey !== '') {
        $legacyPlaintext = \openssl_decrypt(
            $data,
            'aes-256-cbc',
            $legacyKey,
            0,
            $iv
        );

        if ($legacyPlaintext !== false) {
            return $legacyPlaintext;
        }
    }

    throw new \RuntimeException('Messenger message authentication/decryption failed');
}
