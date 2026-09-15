<?php

declare(strict_types=1);

namespace Core;

use FilesystemIterator;
use RuntimeException;

final class ModuleRegistry
{
    private static ?self $instance = null;

    /** @param array<string,ModuleManifest> $modules */
    private function __construct(
        private readonly array $modules,
        private readonly array $loadOrder,
    ) {
    }

    public static function boot(string $modulesRoot, string $coreVersion): self
    {
        $registry = self::discover($modulesRoot, $coreVersion);
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
            $manifest->assertCompatibleWithCore($coreVersion);

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
        self::assertCapabilitiesUnique($modules);
        $loadOrder = self::resolveLoadOrder($modules);

        return new self($modules, $loadOrder);
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

    /** @return list<string> */
    public function defaultComposition(): array
    {
        $enabled = [];
        foreach ($this->loadOrder as $moduleId) {
            if ($this->modules[$moduleId]->defaultEnabled()) {
                $enabled[] = $moduleId;
            }
        }
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
        return $resolved;
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

    /** @param array<string,ModuleManifest> $modules */
    private static function assertCapabilitiesUnique(array $modules): void
    {
        $owners = [];
        foreach ($modules as $module) {
            foreach ($module->capabilities() as $capability) {
                if (isset($owners[$capability])) {
                    throw new RuntimeException(
                        "Capability {$capability} is declared by both {$owners[$capability]} and {$module->id()}"
                    );
                }
                $owners[$capability] = $module->id();
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

    private static function isPathInside(string $path, string $root): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $root);
    }
}
