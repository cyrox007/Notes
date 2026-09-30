<?php

declare(strict_types=1);

namespace App\Services;

use Core\ModuleRegistry;
use DomainException;
use InvalidArgumentException;

/**
 * Управление состоянием только тех модулей, которые разрешены текущей лицензией.
 */
final class ModuleManagementService
{
    /** @var array<string,string> */
    private const MODULE_LABELS = [
        'admin' => 'Администрирование',
        'files' => 'Файлы',
        'messenger' => 'Мессенджер',
        'notes' => 'Заметки',
        'profile' => 'Профиль',
        'tasks' => 'Задачи',
    ];

    private PermissionService $permissions;
    private LicenseModuleEntitlementService $entitlements;

    public function __construct(
        private ?ModuleRegistry $registry = null,
        ?PermissionService $permissions = null,
        ?LicenseModuleEntitlementService $entitlements = null,
    ) {
        $this->registry ??= ModuleRegistry::getInstance();
        $this->permissions = $permissions ?? new PermissionService();
        $this->entitlements = $entitlements ?? new LicenseModuleEntitlementService();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function snapshot(int $actorId): array
    {
        $this->requireManager($actorId);

        $rows = [];
        foreach ($this->registry->all() as $moduleId => $manifest) {
            $decision = $this->entitlements->decision($manifest);
            if (!$decision['entitled']) {
                continue;
            }

            $lifecycle = $this->registry->lifecycleFor($moduleId);
            $rows[] = [
                'id' => $moduleId,
                'name' => self::MODULE_LABELS[$moduleId] ?? $manifest->name(),
                'version' => $manifest->version(),
                'required' => $manifest->required(),
                'license_feature' => $decision['feature'],
                'configured_state' => (string) ($lifecycle['configured_state'] ?? ''),
                'effective_state' => (string) ($lifecycle['effective_state'] ?? ''),
                'last_error' => trim((string) ($lifecycle['last_error'] ?? '')),
                'dependencies' => $manifest->dependencies(),
            ];
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    public function setEnabled(int $actorId, string $moduleId, bool $enabled): array
    {
        $this->requireManager($actorId);

        $moduleId = strtolower(trim($moduleId));
        if ($moduleId === '' || !$this->registry->has($moduleId)) {
            throw new InvalidArgumentException('Модуль не найден', 404);
        }

        $manifest = $this->registry->get($moduleId);
        $decision = $this->entitlements->decision($manifest);
        if (!$decision['entitled']) {
            // Не раскрываем через управляющий маршрут модули, которых нет в лицензии.
            throw new InvalidArgumentException('Модуль не найден', 404);
        }

        if ($manifest->required() && !$enabled) {
            throw new DomainException('Обязательный системный модуль отключить нельзя', 409);
        }

        return $this->registry->transitionLifecycle(
            $moduleId,
            $enabled ? 'enabled' : 'disabled',
            $enabled ? 'Включено администратором установки' : 'Отключено администратором установки',
        );
    }

    private function requireManager(int $actorId): void
    {
        $this->permissions->requirePermission($actorId, 'admin.settings.manage');
        if (!$this->permissions->hasRole($actorId, 'superadmin')) {
            throw new DomainException('Управление модулями доступно только суперадминистратору', 403);
        }
    }
}
