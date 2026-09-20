<?php

declare(strict_types=1);

namespace Core;

use FilesystemIterator;
use RuntimeException;

/**
 * Resolves the database contract of a packaged composition.
 *
 * Core platform tables/schemas remain here; product ownership comes only from
 * module.json. Runtime enabled/disabled state is deliberately ignored: an
 * installed but disabled module keeps its data and still receives migrations.
 */
final class DatabaseOwnership
{
    /** @var list<string> */
    private const CORE_TABLES = [
        'users',
        'system_settings',
        'roles',
        'permissions',
        'role_permissions',
        'user_roles',
        'role_module_policies',
        'module_lifecycle',
        'user_action_log',
    ];

    /** @var list<string> */
    private const CORE_SCHEMAS = [
        'database/core_identity_schema.sql',
        'database/access_control_schema.sql',
        'database/audit_schema.sql',
        'database/core_settings_schema.sql',
        'database/module_lifecycle_schema.sql',
    ];

    /**
     * Historical migration bytes are immutable. The storage/settings migration
     * is intentionally core-owned here even though it also creates the legacy
     * File Manager quota table: system_settings must exist before licensing.
     * Fresh installs no longer have that mixed ownership.
     *
     * @var list<string>
     */
    private const CORE_MIGRATIONS = [
        'database/migrations/20260913_user_contract_v2.sql',
        'database/migrations/20260913_system_settings_storage_quota.sql',
        'database/migrations/20260915_rbac_foundation.sql',
        'database/migrations/20260915_role_module_policies.sql',
        'database/migrations/20260915_module_lifecycle.sql',
        'database/migrations/20260915_installation_license.sql',
        'database/migrations/20260920_user_action_log.sql',
    ];

    /** @param array<string,ModuleManifest> $modules */
    private function __construct(private readonly array $modules)
    {
    }

    public static function fromPackageRoot(string $root): self
    {
        $modulesRoot = realpath(rtrim($root, '/\\') . '/modules');
        if (!is_string($modulesRoot) || !is_dir($modulesRoot) || is_link($modulesRoot)) {
            throw new RuntimeException('Modules root is missing or unsafe for database ownership');
        }

        $modules = [];
        $iterator = new FilesystemIterator($modulesRoot, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            if (!$entry->isDir() || $entry->isLink()) {
                continue;
            }
            $id = $entry->getFilename();
            if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $id) !== 1) {
                throw new RuntimeException('Invalid module directory in database ownership: ' . $id);
            }
            $manifest = ModuleManifest::fromFile($entry->getPathname() . '/module.json', $id);
            $modules[$id] = $manifest;
        }
        ksort($modules, SORT_STRING);
        return new self($modules);
    }

    /** @return list<string> */
    public function moduleIds(): array
    {
        return array_keys($this->modules);
    }

    /** @return list<string> */
    public function tables(): array
    {
        return $this->uniqueOwned(self::CORE_TABLES, 'table', static fn (ModuleManifest $m): array => $m->databaseTables());
    }

    /** @return list<string> */
    public function schemaFiles(): array
    {
        return $this->uniqueOwned(self::CORE_SCHEMAS, 'schema', static fn (ModuleManifest $m): array => $m->databaseSchemas());
    }

    /** @return list<string> */
    public function migrationFiles(): array
    {
        $paths = $this->uniqueOwned(self::CORE_MIGRATIONS, 'migration', static fn (ModuleManifest $m): array => $m->databaseMigrations());

        // 0.13 profile publication is an immutable historical migration touching
        // three optional product modules. It is applicable only when all three
        // table owners are present in the packaged composition.
        $crossModule = 'database/migrations/20260914_profile_publication.sql';
        if (!isset($this->modules['notes'], $this->modules['tasks'], $this->modules['files'])) {
            $paths = array_values(array_filter($paths, static fn (string $path): bool => $path !== $crossModule));
        }
        return $paths;
    }

    /** @return list<string> */
    public function migrationNamesInCanonicalOrder(array $canonical): array
    {
        $owned = [];
        foreach ($this->migrationFiles() as $path) {
            $owned[basename($path)] = true;
        }

        $selected = [];
        foreach ($canonical as $name) {
            if (isset($owned[$name])) {
                $selected[] = $name;
                unset($owned[$name]);
            }
        }
        if ($owned !== []) {
            throw new RuntimeException('Database ownership declares migrations absent from canonical manifest: ' . implode(', ', array_keys($owned)));
        }
        return $selected;
    }

    /**
     * @param list<string> $core
     * @param callable(ModuleManifest):list<string> $reader
     * @return list<string>
     */
    private function uniqueOwned(array $core, string $kind, callable $reader): array
    {
        $result = [];
        $owners = [];
        foreach ($core as $value) {
            $result[] = $value;
            $owners[$value] = 'core';
        }
        foreach ($this->modules as $moduleId => $manifest) {
            foreach ($reader($manifest) as $value) {
                if (isset($owners[$value])) {
                    if ($kind === 'migration'
                        && $value === 'database/migrations/20260913_system_settings_storage_quota.sql'
                        && $moduleId === 'files') {
                        // Immutable pre-1.0 bridge: Core needs system_settings,
                        // while the same historical SQL also creates Files quota.
                        continue;
                    }
                    throw new RuntimeException("Database {$kind} '{$value}' has multiple owners: {$owners[$value]}, {$moduleId}");
                }
                $owners[$value] = $moduleId;
                $result[] = $value;
            }
        }
        return $result;
    }
}
