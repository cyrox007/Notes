<?php

// Environment loading and all 1.0 runtime infrastructure are internal. Runtime
// boot must therefore remain independent from Composer/vendor so the application
// can start from the release bundle with no third-party PHP packages installed.
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

// Core files are still bootstrapped explicitly where ordering matters. The
// runtime autoloader only resolves known core/shared-App namespace roots; it does
// not own module classes. Isolated module classes are loaded by their runtime.php.
$coreFiles = [
    '/core/config.php',
    '/core/Version.php',
    '/core/RequestOrigin.php',
    '/core/SessionSecurity.php',
    '/core/RedirectPolicy.php',
    '/core/WebSocketEndpoint.php',
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
    '/core/model.php',
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

// UUID is a historical global helper rather than a namespaced shared service.
// Keep it as one explicit compatibility include instead of scanning app/handlers.
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

// Normal runtime uses the reconciled persisted enabled composition. Entrypoints
// that deliberately defer lifecycle persistence may request the package default
// composition, but isolated providers are always loaded only through runtime.php.
$moduleRuntimeComposition = $moduleLifecycleStore !== null
    ? $moduleRegistry->enabledComposition()
    : $moduleRegistry->defaultComposition();
\Core\ModuleRuntimeLoader::boot($moduleRegistry, $moduleRuntimeComposition);
