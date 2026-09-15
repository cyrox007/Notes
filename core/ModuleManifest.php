<?php

declare(strict_types=1);

namespace Core;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class ModuleManifest
{
    public const SCHEMA_VERSION = 1;

    /** @param list<string> $dependencies */
    /** @param list<string> $capabilities */
    /** @param list<string> $storageNamespaces */
    private function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly string $version,
        private readonly string $coreMin,
        private readonly string $coreMaxExclusive,
        private readonly array $dependencies,
        private readonly array $capabilities,
        private readonly bool $bundled,
        private readonly bool $defaultEnabled,
        private readonly ?string $licenseFeature,
        private readonly string $runtimeMode,
        private readonly array $storageNamespaces,
        private readonly string $manifestPath,
        private readonly string $integrityHash,
    ) {
    }

    public static function fromFile(string $manifestPath, string $expectedModuleId): self
    {
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            throw new RuntimeException("Module manifest is missing or not a regular file: {$manifestPath}");
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            throw new RuntimeException("Cannot read module manifest: {$manifestPath}");
        }

        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid module manifest JSON: {$manifestPath}", 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException("Module manifest root must be an object: {$manifestPath}");
        }

        if (($data['schema'] ?? null) !== self::SCHEMA_VERSION) {
            throw new RuntimeException("Unsupported module manifest schema: {$manifestPath}");
        }

        $id = self::requireIdentifier($data, 'id');
        if ($id !== $expectedModuleId) {
            throw new RuntimeException("Module id '{$id}' must match directory '{$expectedModuleId}'");
        }

        $name = self::requireString($data, 'name', 1, 120);
        $version = self::requireVersion($data, 'version');

        $core = $data['core'] ?? null;
        if (!is_array($core)) {
            throw new RuntimeException("Module {$id} must define core compatibility");
        }
        $coreMin = self::requireVersion($core, 'min');
        $coreMaxExclusive = self::requireVersion($core, 'max_exclusive');
        if (version_compare($coreMin, $coreMaxExclusive, '>=')) {
            throw new RuntimeException("Module {$id} has an invalid core version range");
        }

        $dependencies = self::identifierList($data['dependencies'] ?? [], "{$id}.dependencies");
        if (in_array($id, $dependencies, true)) {
            throw new RuntimeException("Module {$id} cannot depend on itself");
        }

        $capabilities = self::identifierList($data['capabilities'] ?? [], "{$id}.capabilities");
        if ($capabilities === []) {
            throw new RuntimeException("Module {$id} must declare at least one capability");
        }

        $package = $data['package'] ?? null;
        if (!is_array($package)) {
            throw new RuntimeException("Module {$id} must define package metadata");
        }
        $bundled = self::requireBool($package, 'bundled');
        $defaultEnabled = self::requireBool($package, 'default_enabled');

        $license = $data['license'] ?? null;
        if (!is_array($license)) {
            throw new RuntimeException("Module {$id} must define license metadata");
        }
        $licenseFeature = $license['feature'] ?? null;
        if ($licenseFeature !== null) {
            if (!is_string($licenseFeature) || preg_match('/^[a-z][a-z0-9_.-]{1,95}$/', $licenseFeature) !== 1) {
                throw new RuntimeException("Module {$id} has an invalid license feature identifier");
            }
        }

        $runtime = $data['runtime'] ?? null;
        if (!is_array($runtime)) {
            throw new RuntimeException("Module {$id} must define runtime metadata");
        }
        $runtimeMode = $runtime['mode'] ?? null;
        if (!is_string($runtimeMode) || !in_array($runtimeMode, ['legacy', 'isolated'], true)) {
            throw new RuntimeException("Module {$id} runtime mode must be legacy or isolated");
        }

        $storageNamespaces = self::identifierList($data['storage_namespaces'] ?? [], "{$id}.storage_namespaces");

        return new self(
            $id,
            $name,
            $version,
            $coreMin,
            $coreMaxExclusive,
            $dependencies,
            $capabilities,
            $bundled,
            $defaultEnabled,
            $licenseFeature,
            $runtimeMode,
            $storageNamespaces,
            $manifestPath,
            hash('sha256', $raw),
        );
    }

    public function assertCompatibleWithCore(string $coreVersion): void
    {
        if (version_compare($coreVersion, $this->coreMin, '<')) {
            throw new RuntimeException("Module {$this->id} requires core >= {$this->coreMin}; current {$coreVersion}");
        }
        if (version_compare($coreVersion, $this->coreMaxExclusive, '>=')) {
            throw new RuntimeException("Module {$this->id} requires core < {$this->coreMaxExclusive}; current {$coreVersion}");
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function bundled(): bool
    {
        return $this->bundled;
    }

    public function defaultEnabled(): bool
    {
        return $this->defaultEnabled;
    }

    public function licenseFeature(): ?string
    {
        return $this->licenseFeature;
    }

    public function runtimeMode(): string
    {
        return $this->runtimeMode;
    }

    /** @return list<string> */
    public function storageNamespaces(): array
    {
        return $this->storageNamespaces;
    }

    public function manifestPath(): string
    {
        return $this->manifestPath;
    }

    public function integrityHash(): string
    {
        return $this->integrityHash;
    }

    private static function requireIdentifier(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid identifier field: {$key}");
        }
        return $value;
    }

    private static function requireString(array $data, string $key, int $min, int $max): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException("Missing string field: {$key}");
        }
        $value = trim($value);
        $length = strlen($value);
        if ($length < $min || $length > $max) {
            throw new InvalidArgumentException("Invalid length for field: {$key}");
        }
        return $value;
    }

    private static function requireVersion(array $data, string $key): string
    {
        $value = self::requireString($data, $key, 1, 64);
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid semantic version field: {$key}");
        }
        return $value;
    }

    private static function requireBool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new InvalidArgumentException("Invalid boolean field: {$key}");
        }
        return $value;
    }

    /** @return list<string> */
    private static function identifierList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException("{$field} must be a list");
        }

        $result = [];
        foreach ($value as $entry) {
            if (!is_string($entry) || preg_match('/^[a-z][a-z0-9_.-]{1,95}$/', $entry) !== 1) {
                throw new InvalidArgumentException("{$field} contains an invalid identifier");
            }
            $result[] = $entry;
        }

        if (count($result) !== count(array_unique($result))) {
            throw new InvalidArgumentException("{$field} contains duplicate identifiers");
        }

        sort($result, SORT_STRING);
        return $result;
    }
}
