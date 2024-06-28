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

    foreach ($coreFiles as $file) {
        require_once SITEPATH . $file;
    }
    
    // Load application models
    loadDirectoryFiles(SITEPATH . '/app/models/');

    // Load application controllers
    loadDirectoryFiles(SITEPATH . '/app/controllers/');

    // Load application handlers
    loadDirectoryFiles(SITEPATH . '/app/handlers/');
});

// Маршрутизатор
require_once SITEPATH . '/core/route.php';
require_once SITEPATH . '/core/routerConfig.php';

/**
 * Load all PHP files in a directory excluding '.' and '..'
 *
 * @param string $dir Directory path
 */
function loadDirectoryFiles($dir) {
    $scripts = array_diff(scandir($dir), ['.', '..']);
    foreach ($scripts as $script) {
        require_once $dir . $script;
    }
}
    