<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use InvalidArgumentException;

/**
 * Offline verifier for installation-bound Workspace Organizer licenses.
 *
 * Token format:
 *   wo1.<key-id>.<base64url-json-payload>.<base64url-ed25519-signature>
 *
 * The detached Ed25519 signature covers the exact ASCII bytes:
 *   wo1.<key-id>.<base64url-json-payload>
 *
 * Private signing material must never be present in this repository or in a
 * customer installation. Only public verification keys belong here.
 */
final class LicenseVerifier
{
    public const TOKEN_PREFIX = 'wo1';
    public const PAYLOAD_VERSION = 1;
    public const MAX_TOKEN_LENGTH = 16384;
    public const CLOCK_SKEW_SECONDS = 300;

    /** @var array<string,string> raw Ed25519 public keys */
    private array $trustedKeys = [];
    private Closure $clock;

    /**
     * @param array<string,string>|null $trustedPublicKeys base64url encoded raw public keys.
     *        Passing null loads the public-only release trust registry.
     */
    public function __construct(?array $trustedPublicKeys = null, ?callable $clock = null)
    {
        if (!extension_loaded('sodium')) {
            throw new InvalidArgumentException('PHP sodium extension is required for license verification');
        }

        $source = $trustedPublicKeys ?? self::loadDefaultTrustedPublicKeys();
        foreach ($source as $keyId => $encodedKey) {
            $keyId = trim((string) $keyId);
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', $keyId) !== 1) {
                throw new InvalidArgumentException('Invalid license public key id');
            }

            $raw = self::base64UrlDecode((string) $encodedKey);
            if ($raw === null || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new InvalidArgumentException("Invalid Ed25519 public key for {$keyId}");
            }
            $this->trustedKeys[$keyId] = $raw;
        }

        if ($clock instanceof Closure) {
            $this->clock = $clock;
        } elseif ($clock !== null) {
            $this->clock = Closure::fromCallable($clock);
        } else {
            $this->clock = static fn (): int => time();
        }
    }

    public function hasTrustedKeys(): bool
    {
        return $this->trustedKeys !== [];
    }

    /** @return list<string> */
    public function trustedKeyIds(): array
    {
        return array_values(array_keys($this->trustedKeys));
    }

    /**
     * @return array{
     *   valid:bool,
     *   code:string,
     *   message:string,
     *   key_id:?string,
     *   payload:?array<string,mixed>
     * }
     */
    public function verify(string $token, string $expectedInstallationId): array
    {
        $token = trim($token);
        $installationId = strtolower(trim($expectedInstallationId));

        if (!$this->isUuid($installationId)) {
            return $this->result(false, 'invalid_installation', 'Некорректный идентификатор установки');
        }
        if ($token === '') {
            return $this->result(false, 'empty', 'Лицензионный ключ не установлен');
        }
        if (strlen($token) > self::MAX_TOKEN_LENGTH) {
            return $this->result(false, 'malformed', 'Лицензионный ключ имеет недопустимый размер');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 4 || $parts[0] !== self::TOKEN_PREFIX) {
            return $this->result(false, 'malformed', 'Некорректный формат лицензионного ключа');
        }

        [, $keyId, $payloadEncoded, $signatureEncoded] = $parts;
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', $keyId) !== 1) {
            return $this->result(false, 'malformed', 'Некорректный идентификатор ключа подписи');
        }
        if (!isset($this->trustedKeys[$keyId])) {
            return $this->result(false, 'unknown_key', 'Лицензия подписана неизвестным ключом', $keyId);
        }

        $payloadJson = self::base64UrlDecode($payloadEncoded);
        $signature = self::base64UrlDecode($signatureEncoded);
        if (
            $payloadJson === null
            || $payloadJson === ''
            || $signature === null
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
        ) {
            return $this->result(false, 'malformed', 'Лицензионный ключ повреждён', $keyId);
        }

        $signedBytes = self::TOKEN_PREFIX . '.' . $keyId . '.' . $payloadEncoded;
        if (!sodium_crypto_sign_verify_detached($signature, $signedBytes, $this->trustedKeys[$keyId])) {
            return $this->result(false, 'invalid_signature', 'Подпись лицензионного ключа недействительна', $keyId);
        }

        try {
            $payload = json_decode($payloadJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->result(false, 'invalid_payload', 'Payload лицензии не является корректным JSON', $keyId);
        }
        if (!is_array($payload)) {
            return $this->result(false, 'invalid_payload', 'Payload лицензии должен быть JSON-объектом', $keyId);
        }

        $shapeError = $this->validatePayloadShape($payload);
        if ($shapeError !== null) {
            return $this->result(false, 'invalid_payload', $shapeError, $keyId, $payload);
        }

        if ((int) $payload['v'] !== self::PAYLOAD_VERSION) {
            return $this->result(false, 'unsupported_version', 'Версия лицензионного ключа не поддерживается', $keyId, $payload);
        }

        $payloadInstallation = strtolower((string) $payload['installation_id']);
        if (!hash_equals($installationId, $payloadInstallation)) {
            return $this->result(false, 'wrong_installation', 'Лицензия выпущена для другой установки', $keyId, $payload);
        }

        $now = (int) ($this->clock)();
        $issuedAt = (int) $payload['issued_at'];
        $notBefore = isset($payload['not_before']) && $payload['not_before'] !== null
            ? (int) $payload['not_before']
            : $issuedAt;
        $expiresAt = $payload['expires_at'] === null ? null : (int) $payload['expires_at'];

        if ($issuedAt > $now + self::CLOCK_SKEW_SECONDS || $notBefore > $now + self::CLOCK_SKEW_SECONDS) {
            return $this->result(false, 'not_yet_valid', 'Лицензия ещё не вступила в силу', $keyId, $payload);
        }
        if ($expiresAt !== null && $now > $expiresAt + self::CLOCK_SKEW_SECONDS) {
            return $this->result(false, 'expired', 'Срок действия лицензии истёк', $keyId, $payload);
        }

        return $this->result(true, 'valid', 'Лицензия действительна', $keyId, $payload);
    }

    /** @param array<string,mixed> $payload */
    private function validatePayloadShape(array $payload): ?string
    {
        foreach (['v', 'license_id', 'installation_id', 'issued_at', 'expires_at', 'edition'] as $required) {
            if (!array_key_exists($required, $payload)) {
                return "В лицензии отсутствует поле {$required}";
            }
        }

        if (!is_int($payload['v'])) {
            return 'Поле v должно быть целым числом';
        }
        if (!is_string($payload['license_id']) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $payload['license_id']) !== 1) {
            return 'Некорректный license_id';
        }
        if (!is_string($payload['installation_id']) || !$this->isUuid(strtolower($payload['installation_id']))) {
            return 'Некорректный installation_id';
        }
        if (!is_int($payload['issued_at']) || $payload['issued_at'] <= 0) {
            return 'Некорректный issued_at';
        }
        if ($payload['expires_at'] !== null && (!is_int($payload['expires_at']) || $payload['expires_at'] <= $payload['issued_at'])) {
            return 'Некорректный expires_at';
        }
        if (array_key_exists('not_before', $payload) && $payload['not_before'] !== null) {
            if (!is_int($payload['not_before']) || $payload['not_before'] <= 0) {
                return 'Некорректный not_before';
            }
            if ($payload['expires_at'] !== null && $payload['not_before'] >= $payload['expires_at']) {
                return 'not_before должен быть раньше expires_at';
            }
        }
        if (!is_string($payload['edition']) || preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $payload['edition']) !== 1) {
            return 'Некорректный edition';
        }

        if (isset($payload['customer']) && $payload['customer'] !== null) {
            if (!is_string($payload['customer']) || trim($payload['customer']) === '' || mb_strlen($payload['customer']) > 160) {
                return 'Некорректный customer';
            }
        }

        if (isset($payload['features'])) {
            if (!is_array($payload['features']) || !array_is_list($payload['features'])) {
                return 'features должен быть списком';
            }
            $seen = [];
            foreach ($payload['features'] as $feature) {
                if (!is_string($feature) || preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $feature) !== 1) {
                    return 'Некорректное значение features';
                }
                if (isset($seen[$feature])) {
                    return 'features не должен содержать дубликаты';
                }
                $seen[$feature] = true;
            }
        }

        return null;
    }

    /** @return array<string,string> */
    private static function loadDefaultTrustedPublicKeys(): array
    {
        $path = dirname(__DIR__, 2) . '/config/license_trusted_keys.php';
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('License public-key trust registry is missing or unreadable');
        }

        $keys = (static function (string $registryPath): mixed {
            return require $registryPath;
        })($path);
        if (!is_array($keys)) {
            throw new InvalidArgumentException('License public-key trust registry must return an array');
        }

        /** @var array<string,string> $keys */
        return $keys;
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $value
        ) === 1;
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array{valid:bool,code:string,message:string,key_id:?string,payload:?array<string,mixed>}
     */
    private function result(
        bool $valid,
        string $code,
        string $message,
        ?string $keyId = null,
        ?array $payload = null
    ): array {
        return [
            'valid' => $valid,
            'code' => $code,
            'message' => $message,
            'key_id' => $keyId,
            'payload' => $payload,
        ];
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $padding = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
        return $decoded === false ? null : $decoded;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
