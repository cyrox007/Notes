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

// До инициализации БД и модулей завершаем recovery оборванной updater-транзакции.
// Это позволяет восстановиться даже при временно несовместимом состоянии схемы БД.
\Core\UpdateBootRecoveryGate::enforce(SITEPATH);

// Файлы Core с важным порядком загрузки подключаются явно. Автозагрузчик обслуживает
// только известные пространства Core/shared App; классы модулей подключает runtime.php.
$coreFiles = [
    '/core/config.php',
    '/core/Version.php',
    '/core/RequestOrigin.php',
    '/core/SessionSecurity.php',
    '/core/SecurityHeaders.php',
    '/core/RedirectPolicy.php',
    '/core/WebSocketEndpoint.php',
    '/core/ProfileContentProvider.php',
    '/core/AccountDeactivationGuard.php',
    '/core/ModuleManifest.php',
    '/core/ModuleRegistry.php',
    '/core/ModuleRuntimeProvider.php',
    '/core/ModuleCapabilityRegistry.php',
    '/core/ModuleRuntimeLoader.php',
    '/core/ModuleAssetController.php',
    '/core/DatabaseControll.php',
    '/core/DatabaseManager.php',
    '/core/ModuleLifecycleStore.php',
    '/core/ORM.php',
    '/core/view.php',
    '/core/request.php',
    '/core/helper.php',
    '/core/ViewRenderer.php',
    '/core/ViewContext.php',
    '/core/NativeViewRenderer.php',
    '/core/controller.php',
    '/core/images.php'
];

foreach ($coreFiles as $file) {
    if (file_exists(SITEPATH . $file)) {
        require_once SITEPATH . $file;
    } else {
        throw new RuntimeException("Core file {$file} is missing.");
    }
}

// UUID остаётся историческим глобальным helper без namespace. Подключаем его
// одним явным совместимым include вместо сканирования app/handlers.
$uuidHelper = SITEPATH . '/app/handlers/UUID.php';
if (!is_file($uuidHelper) || is_link($uuidHelper)) {
    throw new RuntimeException('Shared UUID helper is missing or unsafe.');
}
require_once $uuidHelper;

\Core\SessionSecurity::configure();

$deferModuleLifecyclePersistence = defined('WORKSPACE_DEFER_MODULE_LIFECYCLE')
    && WORKSPACE_DEFER_MODULE_LIFECYCLE === true;
$moduleLifecycleStore = $deferModuleLifecyclePersistence
    ? null
    : new \Core\ModuleLifecycleStore(\Core\DatabaseManager::getInstance());
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
