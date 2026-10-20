<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use InvalidArgumentException;
use RuntimeException;

/**
 * Стандартный Web Push: RFC 8291 (aes128gcm), RFC 8292 (VAPID/ES256).
 * Не реализует собственный протокол шифрования сообщений Notes.
 */
final class WebPushCrypto
{
    private const P256_SPKI_PREFIX = "3059301306072a8648ce3d020106082a8648ce3d030107034200";

    public static function base64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function decode64(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('Недопустимая кодировка ключа Push');
        }
        $bytes = base64_decode(strtr($value, '-_', '+/'), true);
        if (!is_string($bytes) || !hash_equals(self::base64url($bytes), $value)) {
            throw new InvalidArgumentException('Повреждённая кодировка ключа Push');
        }
        return $bytes;
    }

    /**
     * @return \OpenSSLAsymmetricKey
     */
    public static function publicFromBytes(string $point): \OpenSSLAsymmetricKey
    {
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new InvalidArgumentException('Ключ устройства должен быть точкой P-256');
        }
        $der = hex2bin(self::P256_SPKI_PREFIX) . $point;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if (!$key instanceof \OpenSSLAsymmetricKey) {
            throw new InvalidArgumentException('Некорректная точка эллиптической кривой');
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['ec']['curve_name'] ?? '') !== 'prime256v1') {
            throw new InvalidArgumentException('Поддерживается только P-256');
        }
        return $key;
    }

    public static function point(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['ec']['curve_name'] ?? '') !== 'prime256v1'
            || !is_string($details['ec']['x'] ?? null)
            || !is_string($details['ec']['y'] ?? null)) {
            throw new InvalidArgumentException('Нужен ключ P-256');
        }
        return "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
            . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    public static function vapidPrivate(): \OpenSSLAsymmetricKey
    {
        $encoded = getenv('NOTES_VAPID_PRIVATE_KEY_BASE64');
        if (!is_string($encoded) || $encoded === '' || strlen($encoded) > 8192) {
            throw new RuntimeException('VAPID ещё не настроен: нет ключа окружения');
        }
        $pem = base64_decode($encoded, true);
        if (!is_string($pem)) {
            throw new RuntimeException('VAPID: неверная кодировка приватного ключа');
        }
        $key = openssl_pkey_get_private($pem);
        if (!$key instanceof \OpenSSLAsymmetricKey) {
            throw new RuntimeException('VAPID: приватный ключ не найден или повреждён');
        }
        self::point($key);
        return $key;
    }

    public static function publicApplicationKey(): string
    {
        return self::base64url(self::point(self::vapidPrivate()));
    }

    /**
     * @return array{Authorization:string,TTL:string,Content-Encoding:string}
     */
    public static function headers(string $endpoint): array
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || !is_string($parts['host'] ?? null)) {
            throw new InvalidArgumentException('Неверный Push endpoint');
        }
        $host = strtolower($parts['host']);
        $origin = 'https://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $subject = (string) (getenv('NOTES_VAPID_CONTACT') ?: '');
        if (preg_match('~^(?:mailto:[^\s@]+@[^\s@]+|https://[A-Za-z0-9.-]+(?:/[^\s]*)?)$~D', $subject) !== 1) {
            throw new RuntimeException('VAPID: укажите NOTES_VAPID_CONTACT (mailto: или HTTPS)');
        }

        $header = self::base64url(json_encode(
            ['typ' => 'JWT', 'alg' => 'ES256'], JSON_THROW_ON_ERROR
        ));
        $claims = self::base64url(json_encode(
            ['aud' => $origin, 'exp' => time() + 3600, 'sub' => $subject],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));
        $message = $header . '.' . $claims;
        $signed = openssl_sign($message, $der, self::vapidPrivate(), OPENSSL_ALGO_SHA256);
        if (!$signed || !is_string($der)) {
            throw new RuntimeException('VAPID: не удалось подписать запрос');
        }
        $token = $message . '.' . self::base64url(self::rawEcdsa($der));
        return [
            'Authorization' => 'vapid t=' . $token . ', k=' . self::publicApplicationKey(),
            'TTL' => '120',
            'Content-Encoding' => 'aes128gcm',
        ];
    }

    /**
     * @param string $receiverPublic 65 байт несжатой P-256 точки
     * @param string $authSecret 16 байт ключа Web Push
     */
    public static function encrypt(string $payload, string $receiverPublic, string $authSecret): string
    {
        if (strlen($payload) > 3000 || strlen($authSecret) !== 16) {
            throw new InvalidArgumentException('Недопустимый размер содержимого Web Push');
        }

        $receiver = self::publicFromBytes($receiverPublic);
        $ephemeral = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if (!$ephemeral instanceof \OpenSSLAsymmetricKey) {
            throw new RuntimeException('Web Push: не удалось получить временный ключ');
        }
        $senderPublic = self::point($ephemeral);
        $shared = openssl_pkey_derive($receiver, $ephemeral, 32);
        if (!is_string($shared) || strlen($shared) !== 32) {
            throw new RuntimeException('Web Push: ошибка согласования ECDH');
        }

        $keyInfo = "WebPush: info\0" . $receiverPublic . $senderPublic;
        $authExtract = hash_hmac('sha256', $shared, $authSecret, true);
        $ikm = hash_hmac('sha256', $keyInfo . "\x01", $authExtract, true);

        $salt = random_bytes(16);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

        $ciphertext = openssl_encrypt(
            $payload . "\x02", 'aes-128-gcm', $cek,
            OPENSSL_RAW_DATA, $nonce, $tag, '', 16
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Web Push: невозможно зашифровать сообщение');
        }
        // salt (16), rs (4), idlen (1), ephemeral key (65), payload + GCM tag.
        return $salt . pack('N', 4096) . chr(65) . $senderPublic . $ciphertext . $tag;
    }

    /**
     * OpenSSL возвращает ECDSA в ASN.1 DER; JWS ES256 требует r||s по 32 байта.
     */
    private static function rawEcdsa(string $der): string
    {
        $length = strlen($der);
        if ($length < 68 || ord($der[0]) !== 0x30) {
            throw new RuntimeException('VAPID: неверная DER-подпись');
        }
        $offset = 1;
        $sequenceLength = ord($der[$offset++]);
        if ($sequenceLength >= 128 || $sequenceLength !== $length - $offset) {
            throw new RuntimeException('VAPID: неверная длина DER-подписи');
        }
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if ($offset + 2 > $length || ord($der[$offset++]) !== 0x02) {
                throw new RuntimeException('VAPID: неверный компонент DER');
            }
            $n = ord($der[$offset++]);
            if ($n < 1 || $n > 33 || $offset + $n > $length) {
                throw new RuntimeException('VAPID: неверная длина компонента ECDSA');
            }
            $value = ltrim(substr($der, $offset, $n), "\0");
            if ($value === '' || strlen($value) > 32) {
                throw new RuntimeException('VAPID: неверный компонент подписи');
            }
            $parts[] = str_pad($value, 32, "\0", STR_PAD_LEFT);
            $offset += $n;
        }
        if ($offset !== $length) {
            throw new RuntimeException('VAPID: неожиданные байты подписи');
        }
        return $parts[0] . $parts[1];
    }
}
