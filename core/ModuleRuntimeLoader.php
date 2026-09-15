<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class ModuleRuntimeLoader
{
    /**
     * Register HTTP routes for isolated modules in deterministic dependency order.
     *
     * Production callers should omit $enabledModuleIds so the persisted lifecycle
     * controls execution. Tests/package tooling may pass an explicit composition to
     * verify routing without attaching a database-backed lifecycle store.
     *
     * @param list<string>|null $enabledModuleIds
     */
    public static function registerRoutes(
        ModuleRegistry $registry,
        Router $router,
        string $modulesRoot,
        ?array $enabledModuleIds = null,
    ): void {
        $enabledModuleIds ??= $registry->enabledComposition();
        $enabled = self::validateEnabledSet($registry, $enabledModuleIds);

        $root = realpath($modulesRoot);
        if ($root === false || !is_dir($root) || is_link($modulesRoot)) {
            throw new RuntimeException("Modules runtime root is missing or invalid: {$modulesRoot}");
        }

        foreach ($registry->loadOrder() as $moduleId) {
            if (!isset($enabled[$moduleId])) {
                continue;
            }

            $manifest = $registry->get($moduleId);
            if ($manifest->runtimeMode() !== 'isolated') {
                continue;
            }

            $entrypoint = $manifest->runtimeEntrypoint();
            if ($entrypoint === null) {
                throw new RuntimeException("Isolated module {$moduleId} has no runtime entrypoint");
            }

            $manifestDirectory = dirname($manifest->manifestPath());
            $moduleRoot = realpath($manifestDirectory);
            if (
                $moduleRoot === false
                || !is_dir($moduleRoot)
                || is_link($manifestDirectory)
                || !self::isPathInside($moduleRoot, $root)
                || basename($moduleRoot) !== $moduleId
            ) {
                throw new RuntimeException("Module runtime path is invalid: {$moduleId}");
            }

            $candidate = $moduleRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entrypoint);
            if (!is_file($candidate) || is_link($candidate)) {
                throw new RuntimeException("Module runtime entrypoint is missing or unsafe: {$moduleId}");
            }

            $realEntrypoint = realpath($candidate);
            if ($realEntrypoint === false || !self::isPathInside($realEntrypoint, $moduleRoot)) {
                throw new RuntimeException("Module runtime entrypoint escapes module root: {$moduleId}");
            }

            $provider = require $realEntrypoint;
            if (!$provider instanceof ModuleRouteProvider) {
                throw new RuntimeException("Module runtime entrypoint must return ModuleRouteProvider: {$moduleId}");
            }

            $provider->registerRoutes($router);
        }
    }

    /**
     * @param list<string> $enabledModuleIds
     * @return array<string,true>
     */
    private static function validateEnabledSet(ModuleRegistry $registry, array $enabledModuleIds): array
    {
        if (!array_is_list($enabledModuleIds)) {
            throw new RuntimeException('Enabled module composition must be a list');
        }

        $enabled = [];
        foreach ($enabledModuleIds as $moduleId) {
            if (!is_string($moduleId) || $moduleId === '' || !$registry->has($moduleId)) {
                throw new RuntimeException('Enabled module composition contains an unknown module');
            }
            if (isset($enabled[$moduleId])) {
                throw new RuntimeException("Enabled module composition contains duplicate module: {$moduleId}");
            }
            $enabled[$moduleId] = true;
        }

        return $enabled;
    }

    private static function isPathInside(string $path, string $root): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $root);
    }
}
