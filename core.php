<?php
// Include vendor/autoload.php if exists
if (file_exists(SITEPATH . '/vendor/autoload.php')) {
    require SITEPATH . '/vendor/autoload.php';
}

// Include Dotenv (or equivalent logic)
if (class_exists('Dotenv\Dotenv')) {
    Dotenv\Dotenv::createUnsafeImmutable(SITEPATH)->load();
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

// Core files
$coreFiles = [
    '/core/config.php',
    '/core/database.php',
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
        die("Core file {$file} is missing.");
    }
}

$directories = [
    '/app/models/',
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
        error_log("Directory {$path} does not exist.");
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
        // Если файл, а не директория
        if ($file->isFile() && $file->getExtension() === 'php') {
            require_once $file->getRealPath();
            //error_log("Loaded file: " . $file->getRealPath());  // Логирование загружаемых файлов
        }
    }
}