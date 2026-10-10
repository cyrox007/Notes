<?php

declare(strict_types=1);

// Загрузка окружения и инфраструктура являются внутренними. Запуск не должен
// зависеть от Composer/vendor, чтобы приложение стартовало прямо из релизного пакета.
$requireCoreBootstrapFile = static function (string $path, string $error): void {
    if (!is_file($path)) {
        throw new RuntimeException($error);
    }

    require_once $path;
};

$requireCoreBootstrapFile(
    SITEPATH . '/core/Environment.php',
    'Не найден загрузчик окружения ядра.'
);
\Core\Environment::load(SITEPATH . '/.env');

$requireCoreBootstrapFile(
    SITEPATH . '/core/RuntimeAutoloader.php',
    'Не найден загрузчик классов ядра.'
);
\Core\RuntimeAutoloader::register(SITEPATH);
unset($requireCoreBootstrapFile);

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
