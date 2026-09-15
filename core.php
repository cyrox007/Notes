<?php
// Include vendor/autoload.php if exists
if (file_exists(SITEPATH . '/vendor/autoload.php')) {
    require SITEPATH . '/vendor/autoload.php';
}

// Include Dotenv (or equivalent logic)
if (class_exists('Dotenv\\Dotenv')) {
    try {
        Dotenv\Dotenv::createUnsafeImmutable(SITEPATH)->load();
    } catch (\Dotenv\Exception\InvalidPathException $e) {
        throw new \Exception("Environment configuration file (.env) not found. Please create a .env file in the project root directory.", 500);
    }
}

// Register the autoload function
spl_autoload_register(function ($class) {
    $classPath = SITEPATH . '/' . str_replace('\\', '/', $class) . '.php';

    if (file_exists($classPath)) {
        require_once $classPath;
    } else {
        error_log("Class file for {$class} not found at path: {$classPath}");
    }
});

// Core files. Paths intentionally match repository casing because production Linux
// filesystems are case-sensitive.
$coreFiles = [
    '/core/config.php',
    '/core/Version.php',
    '/core/SessionSecurity.php',
    '/core/RedirectPolicy.php',
    '/core/ModuleManifest.php',
    '/core/ModuleRegistry.php',
    '/core/DatabaseControll.php',
    '/core/DatabaseManager.php',
    '/core/ModuleLifecycleStore.php',
    '/core/ORM.php',
    '/core/model.php',
    '/core/view.php',
    '/core/request.php',
    '/core/controller.php',
    '/core/helper.php',
    '/core/images.php'
];

foreach ($coreFiles as $file) {
    if (file_exists(SITEPATH . $file)) {
        require_once SITEPATH . $file;
    } else {
        throw new RuntimeException("Core file {$file} is missing.");
    }
}

// Session cookie/security settings must be fixed before any Request can call
// session_start(). Fail closed if PHP refuses the configured policy.
\Core\SessionSecurity::configure();

// 0.14 module-platform boundary: every product module must have a validated
// manifest and a persisted lifecycle record before legacy application code is
// loaded. Valid-but-core-incompatible modules are retained as lifecycle state
// instead of disappearing from the registry. Runtime loading still uses app/*
// during migration; module-owned bootstrap/routes are the next phase.
\Core\ModuleRegistry::boot(
    SITEPATH . '/modules',
    \Core\Version::VERSION,
    new \Core\ModuleLifecycleStore(\Core\DatabaseManager::getInstance())
);

$directories = [
    '/app/models/',
    '/app/services/',
    '/app/controllers/',
    '/app/socket/',
    '/app/handlers/',
    '/app/middlewares/'
];

// Load each directory files if directory exists
array_walk($directories, function ($directory) {
    $path = SITEPATH . $directory;
    if (is_dir($path)) {
        loadDirectoryFiles($path);
    } else {
        // Services are optional for older installs; all other directories are expected.
        if ($directory !== '/app/services/') {
            error_log("Directory {$path} does not exist.");
        }
    }
});

/**
 * Loads all PHP files in a given directory.
 *
 * @param string $directory Directory path
 * @return void
 */
function loadDirectoryFiles(string $directory): void {
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
