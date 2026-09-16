<?php

declare(strict_types=1);

namespace Core;

use InvalidArgumentException;
use JsonException;

final class UpdateManifestVerifier
{
    public const SIGNATURE_PREFIX = 'wou1';
    public const MANIFEST_SCHEMA = 1;
    public const DOMAIN = "WorkspaceOrganizerUpdateManifest/v1\n";
    public const CLOCK_SKEW_SECONDS = 300;
    public const MAX_MANIFEST_BYTES = 131072;

    /** @var array<string,string> raw Ed25519 public keys */
    private array $trustedKeys = [];

    /** @param array<string,string>|null $trustedPublicKeys */
    public function __construct(?array $trustedPublicKeys = null)
    {
        if (!extension_loaded('sodium')) {
            throw new InvalidArgumentException('PHP sodium extension is required for signed updates');
        }

        $source = $trustedPublicKeys;
        if ($source === null) {
            $registry = dirname(__DIR__) . '/config/update_trusted_keys.php';
            if (!is_file($registry)) {
                throw new InvalidArgumentException('Update trust registry is missing');
            }
            $loaded = require $registry;
            if (!is_array($loaded)) {
                throw new InvalidArgumentException('Update trust registry must return an array');
            }
            $source = $loaded;
        }

        foreach ($source as $keyId => $encodedKey) {
            $keyId = trim((string) $keyId);
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,47}$/', $keyId) !== 1) {
                throw new InvalidArgumentException('Invalid update public key id');
            }
            $raw = self::base64UrlDecode((string) $encodedKey);
            if ($raw === null || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new InvalidArgumentException("Invalid Ed25519 update public key for {$keyId}");
            }
            $this->trustedKeys[$keyId] = $raw;
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
     * @return array{valid:bool,code:string,message:string,key_id:?string,manifest:?array<string,mixed>}
     */
    public function verify(string $manifestBytes, string $signatureToken, ?int $now = null): array
    {
        if ($manifestBytes === '' || strlen($manifestBytes) > self::MAX_MANIFEST_BYTES) {
            return $this->result(false, 'manifest_size', 'Update manifest has an invalid size');
        }

        $parts = explode('.', trim($signatureToken));
        if (count($parts) !== 3 || $parts[0] !== self::SIGNATURE_PREFIX) {
            return $this->result(false, 'malformed_signature', 'Update signature has an invalid format');
        }

        [, $keyId, $signatureEncoded] = $parts;
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,47}$/', $keyId) !== 1) {
            return $this->result(false, 'malformed_signature', 'Update signature key id is invalid');
        }
        if (!isset($this->trustedKeys[$keyId])) {
            return $this->result(false, 'unknown_key', 'Update manifest was signed by an unknown key', $keyId);
        }

        $signature = self::base64UrlDecode($signatureEncoded);
        if ($signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return $this->result(false, 'malformed_signature', 'Update signature is damaged', $keyId);
        }

        if (!sodium_crypto_sign_verify_detached(
            $signature,
            self::DOMAIN . $manifestBytes,
            $this->trustedKeys[$keyId]
        )) {
            return $this->result(false, 'invalid_signature', 'Update manifest signature is invalid', $keyId);
        }

        try {
            $manifest = json_decode($manifestBytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->result(false, 'invalid_manifest', 'Update manifest is not valid JSON', $keyId);
        }
        if (!is_array($manifest) || array_is_list($manifest)) {
            return $this->result(false, 'invalid_manifest', 'Update manifest must be a JSON object', $keyId);
        }

        $shapeError = $this->validateManifest($manifest);
        if ($shapeError !== null) {
            return $this->result(false, 'invalid_manifest', $shapeError, $keyId, $manifest);
        }

        if ((int) $manifest['schema'] !== self::MANIFEST_SCHEMA) {
            return $this->result(false, 'unsupported_schema', 'Update manifest schema is not supported', $keyId, $manifest);
        }

        $clock = $now ?? time();
        if ((int) $manifest['issued_at'] > $clock + self::CLOCK_SKEW_SECONDS) {
            return $this->result(false, 'future_manifest', 'Update manifest issuance time is in the future', $keyId, $manifest);
        }

        return $this->result(true, 'valid', 'Update manifest signature is valid', $keyId, $manifest);
    }

    /** @param array<string,mixed> $manifest */
    private function validateManifest(array $manifest): ?string
    {
        foreach ([
            'schema', 'product', 'version', 'version_code', 'channel', 'issued_at',
            'source_commit', 'min_source_version_code', 'requires_php', 'package',
        ] as $required) {
            if (!array_key_exists($required, $manifest)) {
                return "Update manifest is missing {$required}";
            }
        }

        if (!is_int($manifest['schema'])) {
            return 'schema must be an integer';
        }
        if ($manifest['product'] !== 'workspace-organizer') {
            return 'product must be workspace-organizer';
        }
        if (!is_string($manifest['version']) || preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$/', $manifest['version']) !== 1) {
            return 'version is invalid';
        }
        if (!is_int($manifest['version_code']) || $manifest['version_code'] <= 0) {
            return 'version_code must be a positive integer';
        }
        if (!is_string($manifest['channel']) || !in_array($manifest['channel'], ['alpha', 'beta', 'stable'], true)) {
            return 'channel is invalid';
        }
        if (!is_int($manifest['issued_at']) || $manifest['issued_at'] <= 0) {
            return 'issued_at must be a positive Unix timestamp';
        }
        if (!is_string($manifest['source_commit']) || preg_match('/^[0-9a-f]{40}$/', $manifest['source_commit']) !== 1) {
            return 'source_commit must be a full lowercase SHA-1 commit id';
        }
        if (!is_int($manifest['min_source_version_code']) || $manifest['min_source_version_code'] <= 0) {
            return 'min_source_version_code must be a positive integer';
        }
        if (!is_string($manifest['requires_php']) || preg_match('/^\d+\.\d+(?:\.\d+)?$/', $manifest['requires_php']) !== 1) {
            return 'requires_php is invalid';
        }

        $package = $manifest['package'];
        if (!is_array($package) || array_is_list($package)) {
            return 'package must be an object';
        }
        foreach (['filename', 'sha256', 'size', 'format'] as $required) {
            if (!array_key_exists($required, $package)) {
                return "package is missing {$required}";
            }
        }
        if (!is_string($package['filename']) || basename($package['filename']) !== $package['filename']) {
            return 'package filename must not contain a path';
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,190}\.zip$/', $package['filename']) !== 1) {
            return 'package filename is invalid';
        }
        if (!is_string($package['sha256']) || preg_match('/^[0-9a-f]{64}$/', $package['sha256']) !== 1) {
            return 'package sha256 is invalid';
        }
        if (!is_int($package['size']) || $package['size'] <= 0) {
            return 'package size must be a positive integer';
        }
        if ($package['format'] !== 'zip') {
            return 'only zip update packages are supported';
        }

        if (isset($manifest['notes']) && $manifest['notes'] !== null) {
            if (!is_string($manifest['notes']) || mb_strlen($manifest['notes']) > 2000) {
                return 'notes must be a string up to 2000 characters';
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $manifest
     * @return array{valid:bool,code:string,message:string,key_id:?string,manifest:?array<string,mixed>}
     */
    private function result(
        bool $valid,
        string $code,
        string $message,
        ?string $keyId = null,
        ?array $manifest = null
    ): array {
        return [
            'valid' => $valid,
            'code' => $code,
            'message' => $message,
            'key_id' => $keyId,
            'manifest' => $manifest,
        ];
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
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
}
