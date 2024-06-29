<?php
ini_set('display_errors', 1);
define('SITEPATH', __DIR__);

// подключаем файлы ядра
spl_autoload_register(function () {
    require SITEPATH . '/vendor/autoload.php';
    Dotenv\Dotenv::createUnsafeImmutable(SITEPATH)->load();

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

    array_walk($coreFiles, function($file) {
        require_once SITEPATH . $file;
    });

    $directories = [
        '/app/models/',
        '/app/controllers/',
        '/app/handlers/',
        '/app/middlewares/'
    ];

    // Load each directory files if directory exists
    array_walk($directories, function($directory) {
        $path = SITEPATH . $directory;
        if (is_dir($path)) {
            loadDirectoryFiles($path);
        }
    });
});

// Маршрутизатор
require_once SITEPATH . '/core/route.php';
require_once SITEPATH . '/core/routerConfig.php';

/**
 * Loads all PHP files in a given directory.
 *
 * @param string $directory Directory path
 * @return void
 */
function loadDirectoryFiles(string $directory): void {
    // Iterate over each PHP file in the directory and require it
    foreach (glob("{$directory}/*.php") as $file) {
        require_once $file;
    }
}
    