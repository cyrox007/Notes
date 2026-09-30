<?php

// Загрузка окружения и инфраструктура 1.0 являются внутренними. Запуск не должен
// зависеть от Composer/vendor, чтобы приложение стартовало прямо из релизного пакета.
$environmentLoader = SITEPATH . '/core/Environment.php';
if (!is_file($environmentLoader)) {
    throw new RuntimeException('Core environment loader is missing.');
}
require_once $environmentLoader;
\Core\Environment::load(SITEPATH . '/.env');

$runtimeAutoloader = SITEPATH . '/core/RuntimeAutoloader.php';
if (!is_file($runtimeAutoloader)) {
    throw new RuntimeException('Core runtime autoloader is missing.');
}
require_once $runtimeAutoloader;
\Core\RuntimeAutoloader::register(SITEPATH);

// Классы ядра после регистрации RuntimeAutoloader загружаются только по
// фактическому обращению. Ручного списка файлов bootstrap больше нет.

\Core\SessionSecurity::configure();

$deferModuleLifecyclePersistence = defined('WORKSPACE_DEFER_MODULE_LIFECYCLE')
    && WORKSPACE_DEFER_MODULE_LIFECYCLE === true;
$moduleLifecycleStore = $deferModuleLifecyclePersistence
    ? null
    : new \Core\ModuleLifecycleStore(
        \Core\DatabaseManager::getInstance(),
        new \App\Services\LicenseModuleEntitlementService(),
    );
$moduleRegistry = \Core\ModuleRegistry::boot(
    SITEPATH . '/modules',
    \Core\Version::VERSION,
    $moduleLifecycleStore
);

// Обычный runtime использует сохранённый согласованный состав включённых модулей.
// Точки входа с отложенным lifecycle могут использовать состав пакета по умолчанию,
// но изолированные providers всегда подключаются только через runtime.php.
$moduleRuntimeComposition = $moduleLifecycleStore !== null
    ? $moduleRegistry->enabledComposition()
    : $moduleRegistry->defaultComposition();
\Core\ModuleRuntimeLoader::boot($moduleRegistry, $moduleRuntimeComposition);
