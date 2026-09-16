<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Runtime registry for cross-module capabilities.
 *
 * Capability identifiers come from module manifests. Providers are registered
 * only while the effective module composition boots and the registry is sealed
 * before application dispatch, so consumers cannot replace providers at runtime.
 */
final class ModuleCapabilityRegistry
{
    /** @var array<string,array{module_id:string,service:object}> */
    private array $providers = [];
    private bool $sealed = false;

    public function register(string $moduleId, string $capability, object $service): void
    {
        if ($this->sealed) {
            throw new RuntimeException('Module capability registry is sealed');
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/', $moduleId) !== 1) {
            throw new RuntimeException('Invalid capability provider module id');
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/', $capability) !== 1) {
            throw new RuntimeException('Invalid module capability identifier');
        }
        if (isset($this->providers[$capability])) {
            $existing = $this->providers[$capability]['module_id'];
            throw new RuntimeException(
                "Capability {$capability} is already provided by module {$existing}; duplicate provider {$moduleId} rejected"
            );
        }

        $this->providers[$capability] = [
            'module_id' => $moduleId,
            'service' => $service,
        ];
    }

    public function seal(): void
    {
        $this->sealed = true;
    }

    public function isSealed(): bool
    {
        return $this->sealed;
    }

    public function has(string $capability): bool
    {
        return isset($this->providers[$capability]);
    }

    public function providerModuleId(string $capability): ?string
    {
        return $this->providers[$capability]['module_id'] ?? null;
    }

    /**
     * Resolve a capability service without exposing the provider's concrete
     * class/path to consumers. `$expectedType` should be a shared interface or
     * base contract when the caller needs runtime type enforcement.
     */
    public function require(string $capability, ?string $expectedType = null): object
    {
        $entry = $this->providers[$capability] ?? null;
        if ($entry === null) {
            throw new RuntimeException("Required module capability is unavailable: {$capability}");
        }

        $service = $entry['service'];
        if ($expectedType !== null && !is_a($service, $expectedType)) {
            throw new RuntimeException(
                "Capability {$capability} provided by {$entry['module_id']} does not implement {$expectedType}"
            );
        }

        return $service;
    }

    /** @return array<string,string> capability => provider module id */
    public function providers(): array
    {
        $result = [];
        foreach ($this->providers as $capability => $entry) {
            $result[$capability] = $entry['module_id'];
        }
        ksort($result, SORT_STRING);
        return $result;
    }
}
