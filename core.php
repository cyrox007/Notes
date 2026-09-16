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

spl_autoload_register(function ($class) {
    $classPath = SITEPATH . '/' . str_replace('\\', '/', $class) . '.php';

    if (file_exists($classPath)) {
        require_once $classPath;
    } else {
        error_log("Class file for {$class} not found at path: {$classPath}");
    }
});

// Core files. Paths intentionally match repository casing because production Linux
// filesystems are case-sensitive. Native view infrastructure is explicitly loaded
// because the generic namespace autoloader would map Core to /Core, not /core.
$coreFiles = [
    '/core/config.php',
    '/core/Version.php',
    '/core/SessionSecurity.php',
    '/core/RedirectPolicy.php',
    '/core/WebSocketEndpoint.php',
    '/core/ModuleManifest.php',
    '/core/ModuleRegistry.php',
    '/core/ModuleRuntimeProvider.php',
    '/core/ModuleRuntimeLoader.php',
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

// HTTP runtime uses the reconciled effective composition. Entrypoints that defer
// lifecycle persistence (notably the native WS process bootstrap) use the package
// default composition; isolated runtime code is still loaded explicitly rather
// than through the legacy recursive app/* loader.
$moduleRuntimeComposition = $moduleLifecycleStore !== null
    ? $moduleRegistry->enabledComposition()
    : $moduleRegistry->defaultComposition();
\Core\ModuleRuntimeLoader::boot($moduleRegistry, $moduleRuntimeComposition);

// Transitional legacy loader. Product files disappear from these shared app/*
// directories as each module moves behind ModuleRuntimeLoader. This loader is
// removed once the final bundled module is isolated.
$directories = [
    '/app/models/',
    '/app/services/',
    '/app/controllers/',
    '/app/socket/',
    '/app/handlers/',
    '/app/middlewares/'
];

array_walk($directories, function ($directory) {
    $path = SITEPATH . $directory;
    if (is_dir($path)) {
        loadDirectoryFiles($path);
    } elseif ($directory !== '/app/services/') {
        error_log("Directory {$path} does not exist.");
    }
});

/**
 * @param string $directory Directory path
 */
function loadDirectoryFiles(string $directory): void
{
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            require_once $file->getRealPath();
        }
    }
}
