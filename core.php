<?php
// Include vendor/autoload.php if exists
if (file_exists(SITEPATH . '/vendor/autoload.php')) {
    require SITEPATH . '/vendor/autoload.php';
}

// Include Dotenv (or equivalent logic)
if (class_exists('Dotenv\Dotenv')) {
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

// Core files
$coreFiles = [
    '/core/Config.php',
    '/core/Version.php',
    '/core/DatabaseControll.php',
    '/core/DatabaseManager.php',
    '/core/ORM.php',
    '/core/model.php',
    '/core/View.php',
    '/core/Request.php',
    '/core/Controller.php',
    '/core/Helper.php',
    '/core/Images.php'
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