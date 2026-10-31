<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use JsonException;

/**
 * Проверка самоподписанных публичных ключей Matrix (Ed25519 + канонический JSON).
 * Проверяет владение ключом подписи, но НЕ удостоверяет личность собеседника.
 * Доверие требует независимого подтверждения отпечатка на клиенте.
 */
final class MessengerE2eeIdentity
{
    /** @param array<string,mixed> $identity */
    public static function verifyDevice(array $identity, string $userUid, string $deviceId): void
    {
        $matrixId = self::matrixUserId($userUid);
        $device = $identity['device_keys'] ?? null;
        if (!is_array($device)
            || ($device['user_id'] ?? null) !== $matrixId
            || ($device['device_id'] ?? null) !== $deviceId
            || !is_array($device['keys'] ?? null)
            || ($device['keys']['ed25519:' . $deviceId] ?? null) !== ($identity['signing_key'] ?? null)
            || ($device['keys']['curve25519:' . $deviceId] ?? null) !== ($identity['agreement_key'] ?? null)) {
            throw new InvalidArgumentException('Публичные ключи Matrix не соответствуют владельцу устройства');
        }
        $algorithms = $device['algorithms'] ?? null;
        if (!is_array($algorithms)
            || !in_array('m.olm.v1.curve25519-aes-sha2', $algorithms, true)
            || !in_array('m.megolm.v1.aes-sha2', $algorithms, true)) {
            throw new InvalidArgumentException('Устройство не объявляет поддерживаемый протокол Olm/Megolm');
        }
        self::verifySignedJson($device, $matrixId, $deviceId, (string) $identity['signing_key']);
    }

    /** @param array<string,mixed> $key */
    public static function verifyPrekey(array $key, string $userUid, string $deviceId, string $signingKey): void
    {
        if (!isset($key['key']) || !is_string($key['key'])) {
            throw new InvalidArgumentException('Отсутствует одноразовый открытый ключ');
        }
        self::verifySignedJson($key, self::matrixUserId($userUid), $deviceId, $signingKey);
    }

    /** @param array<string,mixed> $object */
    private static function verifySignedJson(array $object, string $userId, string $deviceId, string $signingKey): void
    {
        $signature = $object['signatures'][$userId]['ed25519:' . $deviceId] ?? null;
        if (!is_string($signature)) {
            throw new InvalidArgumentException('Отсутствует подпись устройства');
        }
        $signatureBytes = self::readBase64($signature, SODIUM_CRYPTO_SIGN_BYTES);
        $publicBytes = self::readBase64($signingKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        unset($object['signatures'], $object['unsigned']);

        if (!sodium_crypto_sign_verify_detached(
            $signatureBytes,
            self::canonicalJson($object),
            $publicBytes
        )) {
            throw new InvalidArgumentException('Подпись открытого ключа не прошла проверку');
        }
    }

    /** @param array<string,mixed> $object */
    public static function canonicalJson(array $object): string
    {
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) {
                if (is_float($value)) {
                    throw new InvalidArgumentException('В подписанных JSON запрещены дробные числа');
                }
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $nested) {
                $value[$key] = $normalize($nested);
            }
            return $value;
        };

        try {
            $json = json_encode(
                $normalize($object),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if (!is_string($json)) {
                throw new InvalidArgumentException('Не удалось закодировать подписанные данные');
            }
            return $json;
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Некорректный подписанный JSON', 0, $e);
        }
    }

    public static function matrixUserId(string $userUid): string
    {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $userUid) !== 1) {
            throw new InvalidArgumentException('Некорректный идентификатор владельца ключа');
        }
        return '@' . strtolower($userUid) . ':notes.local';
    }

    private static function readBase64(string $value, int $size): string
    {
        if (preg_match('/^[A-Za-z0-9+\/_-]+={0,2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Недопустимые символы подписи или ключа');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || strlen($decoded) !== $size) {
            throw new InvalidArgumentException('Неверная длина подписи или ключа');
        }
        return $decoded;
    }
}
