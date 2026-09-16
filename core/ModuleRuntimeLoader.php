<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class ModuleRuntimeLoader
{
    private static ?self $instance = null;

    /** @var array<string,ModuleRuntimeProvider> */
    private array $providers = [];
    private ModuleCapabilityRegistry $capabilityRegistry;

    /** @param list<string> $runtimeComposition */
    private function __construct(
        private readonly ModuleRegistry $registry,
        private readonly array $runtimeComposition,
    ) {
        $this->capabilityRegistry = new ModuleCapabilityRegistry();
    }

    /** @param list<string> $runtimeComposition */
    public static function boot(ModuleRegistry $registry, array $runtimeComposition): self
    {
        $loader = new self($registry, $runtimeComposition);
        $loader->loadProviders();
        $loader->capabilityRegistry->seal();
        self::$instance = $loader;
        return $loader;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('Module runtime loader has not been booted');
        }
        return self::$instance;
    }

    /** @return array<string,ModuleRuntimeProvider> */
    public function providers(): array
    {
        return $this->providers;
    }

    public function capabilities(): ModuleCapabilityRegistry
    {
        return $this->capabilityRegistry;
    }

    public function registerRoutes(Router $router): void
    {
        foreach ($this->runtimeComposition as $moduleId) {
            if (isset($this->providers[$moduleId])) {
                $this->providers[$moduleId]->registerRoutes($router);
            }
        }
    }

    private function loadProviders(): void
    {
        $seen = [];
        foreach ($this->runtimeComposition as $moduleId) {
            if (!is_string($moduleId) || $moduleId === '' || isset($seen[$moduleId])) {
                throw new RuntimeException('Runtime composition contains an invalid or duplicate module id');
            }
            $seen[$moduleId] = true;

            $manifest = $this->registry->get($moduleId);
            if ($manifest->runtimeMode() !== 'isolated') {
                continue;
            }

            $entrypoint = $manifest->runtimeEntrypoint();
            if ($entrypoint === null) {
                throw new RuntimeException("Isolated module {$moduleId} has no runtime entrypoint");
            }

            $moduleRoot = realpath(dirname($manifest->manifestPath()));
            if ($moduleRoot === false || !is_dir($moduleRoot) || is_link($moduleRoot)) {
                throw new RuntimeException("Isolated module root is invalid: {$moduleId}");
            }

            $candidate = $moduleRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entrypoint);
            $resolved = realpath($candidate);
            if ($resolved === false || !is_file($resolved) || is_link($candidate)) {
                throw new RuntimeException("Isolated module entrypoint is missing or invalid: {$moduleId}");
            }

            $prefix = rtrim($moduleRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (!str_starts_with($resolved, $prefix)) {
                throw new RuntimeException("Isolated module entrypoint escapes its module root: {$moduleId}");
            }

            $provider = require $resolved;
            if (!$provider instanceof ModuleRuntimeProvider) {
                throw new RuntimeException("Isolated module entrypoint must return ModuleRuntimeProvider: {$moduleId}");
            }
            if ($provider->moduleId() !== $moduleId) {
                throw new RuntimeException("Isolated module provider id mismatch: {$moduleId}");
            }

            $provider->boot();
            $this->registerCapabilities($manifest, $provider);
            $this->providers[$moduleId] = $provider;
        }
    }

    private function registerCapabilities(ModuleManifest $manifest, ModuleRuntimeProvider $provider): void
    {
        $declared = $manifest->capabilities();
        sort($declared, SORT_STRING);

        $exported = $provider->capabilities();
        $exportedNames = [];
        foreach ($exported as $capability => $service) {
            if (!is_string($capability) || $capability === '' || !is_object($service)) {
                throw new RuntimeException("Module {$manifest->id()} exported an invalid capability service");
            }
            $exportedNames[] = $capability;
        }
        sort($exportedNames, SORT_STRING);

        if ($exportedNames !== $declared) {
            throw new RuntimeException(
                "Module {$manifest->id()} runtime capability exports must exactly match module.json declarations"
            );
        }

        foreach ($exported as $capability => $service) {
            $this->capabilityRegistry->register($manifest->id(), $capability, $service);
        }
    }
}
