<?php

declare(strict_types=1);

namespace App\Services;

use Core\ModuleEntitlementResolver;
use Core\ModuleManifest;

/**
 * Преобразует проверенную лицензию установки в разрешения модулей.
 *
 * Разрешение является явным: feature должна присутствовать в подписанном
 * payload. Отсутствие features не означает полный доступ.
 */
final class LicenseModuleEntitlementService implements ModuleEntitlementResolver
{
    /** @var array<string,mixed>|null */
    private ?array $status = null;

    public function __construct(private ?LicenseService $licenses = null)
    {
        $this->licenses ??= new LicenseService();
    }

    /** @return array{entitled:bool,feature:?string,reason:?string} */
    public function decision(ModuleManifest $manifest): array
    {
        $feature = $manifest->licenseFeature();
        if ($feature === null || trim($feature) === '') {
            return [
                'entitled' => false,
                'feature' => null,
                'reason' => 'Модуль не объявляет обязательное лицензионное разрешение',
            ];
        }

        $status = $this->status();
        if (empty($status['valid'])) {
            return [
                'entitled' => false,
                'feature' => $feature,
                'reason' => 'Действующая лицензия не подтверждена',
            ];
        }

        $features = isset($status['features']) && is_array($status['features'])
            ? array_values(array_map('strval', $status['features']))
            : [];

        if (!in_array($feature, $features, true)) {
            return [
                'entitled' => false,
                'feature' => $feature,
                'reason' => 'Модуль не разрешён текущей лицензией',
            ];
        }

        return [
            'entitled' => true,
            'feature' => $feature,
            'reason' => null,
        ];
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        return $this->status ??= $this->licenses->status();
    }

    public function isEntitled(ModuleManifest $manifest): bool
    {
        return $this->decision($manifest)['entitled'];
    }
}
