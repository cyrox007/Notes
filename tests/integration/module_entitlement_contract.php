<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function moduleEntitlementAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

require_once $root . '/core/ModuleManifest.php';

foreach (['admin', 'files', 'messenger', 'notes', 'profile', 'tasks'] as $moduleId) {
    $manifest = \Core\ModuleManifest::fromFile(
        $root . '/modules/' . $moduleId . '/module.json',
        $moduleId
    );
    moduleEntitlementAssert(
        $manifest->licenseFeature() === 'workspace.' . $moduleId,
        "{$moduleId} использует неожиданный идентификатор лицензионной возможности"
    );
}

$admin = \Core\ModuleManifest::fromFile($root . '/modules/admin/module.json', 'admin');
moduleEntitlementAssert($admin->required(), 'Admin должен быть обязательным системным модулем');

$entitlement = (string) file_get_contents($root . '/app/services/LicenseModuleEntitlementService.php');
moduleEntitlementAssert(
    str_contains($entitlement, "empty(\$status['valid'])"),
    'разрешение модуля не требует действующей лицензии'
);
moduleEntitlementAssert(
    str_contains($entitlement, "in_array(\$feature, \$features, true)"),
    'разрешение модуля не требует явного feature в лицензии'
);
moduleEntitlementAssert(
    !str_contains($entitlement, '$features === [] ? true'),
    'пустой список features не должен означать полный доступ'
);

$core = (string) file_get_contents($root . '/core.php');
moduleEntitlementAssert(
    str_contains($core, 'new \\App\\Services\\LicenseModuleEntitlementService()'),
    'production runtime не подключает лицензионный фильтр модулей'
);

$lifecycle = (string) file_get_contents($root . '/core/ModuleLifecycleStore.php');
moduleEntitlementAssert(
    str_contains($lifecycle, "'unlicensed'"),
    'lifecycle не имеет отдельного состояния отсутствия разрешения лицензии'
);
moduleEntitlementAssert(
    str_contains($lifecycle, "$manifest->required() && in_array($targetState, ['disabled', 'uninstalled'], true)"),
    'lifecycle не защищает обязательный системный модуль от отключения'
);

$router = (string) file_get_contents($root . '/core/routerConfig.php');
moduleEntitlementAssert(
    str_contains($router, "'/license'") && str_contains($router, "'system_license'"),
    'core-маршрут восстановления лицензии должен работать независимо от Admin'
);

$adminProvider = (string) file_get_contents($root . '/modules/admin/AdminRuntimeProvider.php');
moduleEntitlementAssert(
    str_contains($adminProvider, "->add('GET', '/modules'"),
    'Admin не предоставляет страницу управления модулями'
);
moduleEntitlementAssert(
    str_contains($adminProvider, "->add('POST', '/modules/state'"),
    'Admin не предоставляет защищённое действие изменения состояния модуля'
);

fwrite(STDOUT, "[OK] лицензионные разрешения и управление модулями\n");
