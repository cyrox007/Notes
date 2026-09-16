<?php

declare(strict_types=1);

namespace Core;

use FilesystemIterator;
use RuntimeException;

final class ModuleRegistry
{
    private static ?self $instance = null;

    /** @var array<string,array<string,mixed>> */
    private array $lifecycle = [];

    /** @param array<string,ModuleManifest> $modules */
    private function __construct(
        private readonly array $modules,
        private readonly array $loadOrder,
        private readonly string $coreVersion,
        private readonly ?ModuleLifecycleStore $lifecycleStore = null,
    ) {
    }

    public static function boot(
        string $modulesRoot,
        string $coreVersion,
        ?ModuleLifecycleStore $lifecycleStore = null
    ): self {
        $discovered = self::discover($modulesRoot, $coreVersion);
        $registry = new self(
            $discovered->modules,
            $discovered->loadOrder,
            $coreVersion,
            $lifecycleStore,
        );
        if ($lifecycleStore !== null) {
            $registry->lifecycle = $lifecycleStore->reconcile($registry->modules, $coreVersion);
        }
        self::$instance = $registry;
        return $registry;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('Module registry has not been booted');
        }
        return self::$instance;
    }

    public static function discover(string $modulesRoot, string $coreVersion): self
    {
        $root = realpath($modulesRoot);
        if ($root === false || !is_dir($root) || is_link($modulesRoot)) {
            throw new RuntimeException("Modules root is missing or invalid: {$modulesRoot}");
        }

        $modules = [];
        $iterator = new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            if (!$entry->isDir() || $entry->isLink()) {
                continue;
            }

            $moduleId = $entry->getFilename();
            if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $moduleId) !== 1) {
                throw new RuntimeException("Invalid module directory name: {$moduleId}");
            }

            $modulePath = $entry->getRealPath();
            if ($modulePath === false || !self::isPathInside($modulePath, $root)) {
                throw new RuntimeException("Module path escapes modules root: {$moduleId}");
            }

            $manifest = ModuleManifest::fromFile($modulePath . '/module.json', $moduleId);
            if (isset($modules[$manifest->id()])) {
                throw new RuntimeException("Duplicate module id: {$manifest->id()}");
            }
            $modules[$manifest->id()] = $manifest;
        }

        if ($modules === []) {
            throw new RuntimeException('No module manifests were discovered');
        }

        ksort($modules, SORT_STRING);
        self::assertDependenciesExist($modules);
        $loadOrder = self::resolveLoadOrder($modules);

        return new self($modules, $loadOrder, $coreVersion);
    }

    public function has(string $moduleId): bool
    {
        return isset($this->modules[$moduleId]);
    }

    public function get(string $moduleId): ModuleManifest
    {
        if (!isset($this->modules[$moduleId])) {
            throw new RuntimeException("Unknown module: {$moduleId}");
        }
        return $this->modules[$moduleId];
    }

    /** @return array<string,ModuleManifest> */
    public function all(): array
    {
        return $this->modules;
    }

    /** @return list<string> */
    public function loadOrder(): array
    {
        return $this->loadOrder;
    }

    /**
     * Manifest-only default composition. This intentionally ignores persisted
     * runtime lifecycle state so package/distribution planning remains stable.
     * Alternative installed providers may share a capability, but a default
     * runtime composition must still resolve to one active owner per capability.
     *
     * @return list<string>
     */
    public function defaultComposition(): array
    {
        $enabled = [];
        foreach ($this->loadOrder as $moduleId) {
            if ($this->modules[$moduleId]->defaultEnabled()) {
                $enabled[] = $moduleId;
            }
        }
        $this->assertCompositionCapabilitiesUnique($enabled);
        return $enabled;
    }

    /** @param list<string> $requested */
    /** @return list<string> */
    public function resolveComposition(array $requested): array
    {
        $wanted = [];
        foreach ($requested as $moduleId) {
            if (!is_string($moduleId) || !$this->has($moduleId)) {
                throw new RuntimeException('Composition contains an unknown module');
            }
            $wanted[$moduleId] = true;
        }

        $changed = true;
        while ($changed) {
            $changed = false;
            foreach (array_keys($wanted) as $moduleId) {
                foreach ($this->modules[$moduleId]->dependencies() as $dependency) {
                    if (!isset($wanted[$dependency])) {
                        $wanted[$dependency] = true;
                        $changed = true;
                    }
                }
            }
        }

        $resolved = [];
        foreach ($this->loadOrder as $moduleId) {
            if (isset($wanted[$moduleId])) {
                $resolved[] = $moduleId;
            }
        }
        $this->assertCompositionCapabilitiesUnique($resolved);
        return $resolved;
    }

    /** @return array<string,array<string,mixed>> */
    public function lifecycle(): array
    {
        $this->assertLifecycleAvailable();
        return $this->lifecycle;
    }

    /** @return array<string,mixed> */
    public function lifecycleFor(string $moduleId): array
    {
        $this->assertLifecycleAvailable();
        if (!isset($this->lifecycle[$moduleId])) {
            throw new RuntimeException("Missing lifecycle state for module: {$moduleId}");
        }
        return $this->lifecycle[$moduleId];
    }

    public function effectiveState(string $moduleId): string
    {
        return (string) $this->lifecycleFor($moduleId)['effective_state'];
    }

    public function isRuntimeEnabled(string $moduleId): bool
    {
        return $this->effectiveState($moduleId) === 'enabled';
    }

    /**
     * Runtime composition is strictly the persisted effective enabled set.
     * Dependencies that cannot run are reconciled to degraded before this method
     * is reached, so disabled/incompatible modules are never silently enabled.
     *
     * @return list<string>
     */
    public function enabledComposition(): array
    {
        $this->assertLifecycleAvailable();
        $enabled = [];
        foreach ($this->loadOrder as $moduleId) {
            if (($this->lifecycle[$moduleId]['effective_state'] ?? null) === 'enabled') {
                $enabled[] = $moduleId;
            }
        }
        $this->assertCompositionCapabilitiesUnique($enabled);
        return $enabled;
    }

    /** @return array<string,mixed> */
    public function transitionLifecycle(string $moduleId, string $targetState, ?string $reason = null): array
    {
        $this->assertLifecycleAvailable();
        $this->lifecycleStore->transition(
            $moduleId,
            $targetState,
            $this->modules,
            $this->coreVersion,
            $reason,
        );
        $this->lifecycle = $this->lifecycleStore->reconcile($this->modules, $this->coreVersion);
        return $this->lifecycleFor($moduleId);
    }

    /** @param array<string,ModuleManifest> $modules */
    private static function assertDependenciesExist(array $modules): void
    {
        foreach ($modules as $module) {
            foreach ($module->dependencies() as $dependency) {
                if (!isset($modules[$dependency])) {
                    throw new RuntimeException("Module {$module->id()} requires missing dependency {$dependency}");
                }
            }
        }
    }

    /** @param list<string> $composition */
    private function assertCompositionCapabilitiesUnique(array $composition): void
    {
        $owners = [];
        foreach ($composition as $moduleId) {
            $module = $this->get($moduleId);
            foreach ($module->capabilities() as $capability) {
                if (isset($owners[$capability])) {
                    throw new RuntimeException(
                        "Capability {$capability} is active in both {$owners[$capability]} and {$moduleId}"
                    );
                }
                $owners[$capability] = $moduleId;
            }
        }
    }

    /** @param array<string,ModuleManifest> $modules */
    /** @return list<string> */
    private static function resolveLoadOrder(array $modules): array
    {
        $state = [];
        $order = [];

        $visit = function (string $moduleId) use (&$visit, &$state, &$order, $modules): void {
            $currentState = $state[$moduleId] ?? 0;
            if ($currentState === 2) {
                return;
            }
            if ($currentState === 1) {
                throw new RuntimeException("Module dependency cycle detected at {$moduleId}");
            }

            $state[$moduleId] = 1;
            foreach ($modules[$moduleId]->dependencies() as $dependency) {
                $visit($dependency);
            }
            $state[$moduleId] = 2;
            $order[] = $moduleId;
        };

        foreach (array_keys($modules) as $moduleId) {
            $visit($moduleId);
        }

        return $order;
    }

    private function assertLifecycleAvailable(): void
    {
        if ($this->lifecycleStore === null) {
            throw new RuntimeException('Persisted module lifecycle is not attached to this registry');
        }
    }

    private static function isPathInside(string $path, string $root): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $root);
    }
}
