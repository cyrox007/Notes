<?php

declare(strict_types=1);

namespace Core;

use FilesystemIterator;
use RuntimeException;

/**
 * Определяет контракт БД конкретного состава пакета.
 *
 * Таблицы и схемы платформы остаются здесь, а продуктовые сущности
 * объявляются только через module.json. Состояние enabled/disabled намеренно
 * не учитывается: установленный, но отключённый модуль сохраняет данные и
 * продолжает получать миграции.
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
     * Исторические байты миграций неизменяемы. Миграция storage/settings
     * остаётся платформенной, хотя также создаёт старую таблицу квоты Files:
     * system_settings нужна ещё до проверки лицензии. В свежей схеме такого
     * смешанного владения уже нет.
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
        'database/migrations/20260921_totp_two_factor.sql',
        'database/migrations/20260930_module_entitlements.sql',
        'database/migrations/20261002_user_lifecycle.sql',
        'database/migrations/20261010_user_status_bridge.sql',
    ];

    /**
     * Платформенные миграции, появившиеся после последнего стабильного пакета.
     * Ветка разработки ещё может содержать старый Version::VERSION_CODE, поэтому
     * принадлежность определяется каноническим manifest самого исследуемого
     * пакета, а не версией запущенного updater.
     *
     * @var list<string>
     */
    private const CORE_PACKAGE_MANIFEST_MIGRATIONS = [
        '20261010_notifications_foundation.sql',
        '20261012_notifications_http_fallback.sql',
    ];

    /**
     * @param array<string,ModuleManifest> $modules
     * @param list<string> $packageCoreMigrations
     */
    private function __construct(
        private readonly array $modules,
        private readonly ?int $packageVersionCode = null,
        private readonly array $packageCoreMigrations = []
    )
    {
    }

    public static function fromPackageRoot(string $root): self
    {
        $packageRoot = realpath(rtrim($root, '/\\'));
        if (!is_string($packageRoot) || !is_dir($packageRoot) || is_link($packageRoot)) {
            throw new RuntimeException('Корень пакета отсутствует или небезопасен для определения владения БД');
        }

        $modulesRoot = realpath($packageRoot . '/modules');
        if (!is_string($modulesRoot) || !is_dir($modulesRoot) || is_link($modulesRoot)) {
            throw new RuntimeException('Каталог modules отсутствует или небезопасен для определения владения БД');
        }

        $modules = [];
        $iterator = new FilesystemIterator($modulesRoot, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            if (!$entry->isDir() || $entry->isLink()) {
                continue;
            }
            $id = $entry->getFilename();
            if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $id) !== 1) {
                throw new RuntimeException('Некорректный каталог модуля в контракте владения БД: ' . $id);
            }
            $manifest = ModuleManifest::fromFile($entry->getPathname() . '/module.json', $id);
            $modules[$id] = $manifest;
        }
        ksort($modules, SORT_STRING);

        // Замороженный updater может анализировать более старый исходный пакет.
        // Его собственная Version описывает runtime updater, а не этот пакет.
        $versionPath = $packageRoot . '/core/Version.php';
        $packageVersionCode = null;
        if (is_file($versionPath) && !is_link($versionPath)) {
            $versionSource = file_get_contents($versionPath);
            if (is_string($versionSource)
                && preg_match('/public const VERSION_CODE = ([0-9]+);/', $versionSource, $versionMatch) === 1) {
                $packageVersionCode = (int) $versionMatch[1];
            }
        }

        return new self(
            $modules,
            $packageVersionCode,
            self::packageCoreMigrations($packageRoot)
        );
    }

    /** @return list<string> */
    public function moduleIds(): array
    {
        return array_keys($this->modules);
    }

    /** @return list<string> */
    public function tables(): array
    {
        return $this->uniqueOwned([...self::CORE_TABLES, ...($this->hasNotifications() ? ['notification_events', 'notifications', 'notification_subscriptions', 'notification_channel_configs', 'notification_delivery_jobs', 'notification_delivery_attempts', 'notification_runtime_state'] : [])], 'table', static fn (ModuleManifest $m): array => $m->databaseTables());
    }

    /** @return list<string> */
    public function schemaFiles(): array
    {
        return $this->uniqueOwned([...self::CORE_SCHEMAS, ...($this->hasNotifications() ? ['database/notifications_schema.sql'] : [])], 'schema', static fn (ModuleManifest $m): array => $m->databaseSchemas());
    }

    private function hasNotifications(): bool
    {
        return ($this->packageVersionCode ?? 0) >= 10901;
    }

    /** @return list<string> */
    public function migrationFiles(): array
    {
        $coreMigrations = array_merge(self::CORE_MIGRATIONS, $this->packageCoreMigrations);
        if ($this->packageVersionCode !== null && $this->packageVersionCode <= 10015) {
            $coreMigrations = array_values(array_filter($coreMigrations,
                static fn (string $path): bool => $path !== 'database/migrations/20261010_user_status_bridge.sql'));
        }
        if ($this->packageVersionCode !== null && $this->packageVersionCode <= 10014) {
            $coreMigrations = array_values(array_filter($coreMigrations,
                static fn (string $path): bool => $path !== 'database/migrations/20261002_user_lifecycle.sql'));
        }
        $paths = $this->uniqueOwned($coreMigrations, 'migration', static fn (ModuleManifest $m): array => $m->databaseMigrations());

        // Историческая миграция публикации профиля затрагивает сразу три
        // необязательных модуля и применима только при наличии всех владельцев.
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
            throw new RuntimeException('Владение БД объявляет миграции вне канонического manifest: ' . implode(', ', array_keys($owned)));
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
                        // Неизменяемый мост до 1.0: Core требует system_settings,
                        // а тот же исторический SQL одновременно создаёт квоту Files.
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

    /** @return list<string> */
    private static function packageCoreMigrations(string $packageRoot): array
    {
        $manifestPath = $packageRoot . '/database/migrations/manifest.json';
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            return [];
        }

        $bytes = file_get_contents($manifestPath);
        if (!is_string($bytes)) {
            throw new RuntimeException('Не удалось прочитать канонический manifest миграций пакета');
        }

        try {
            $manifest = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Некорректный канонический manifest миграций пакета', 0, $e);
        }

        $names = is_array($manifest) && is_array($manifest['migrations'] ?? null)
            ? array_values(array_filter($manifest['migrations'], 'is_string'))
            : [];
        $declared = array_fill_keys($names, true);
        $owned = [];
        foreach (self::CORE_PACKAGE_MANIFEST_MIGRATIONS as $name) {
            if (isset($declared[$name])) {
                $owned[] = 'database/migrations/' . $name;
            }
        }
        return $owned;
    }
}
