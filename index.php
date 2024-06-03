<?php
ini_set('display_errors', 1);
session_start();

define('SITEPATH', __DIR__);

// подключаем файлы ядра

spl_autoload_register(function () {
    require_once SITEPATH . '/core/config.php';
    require_once SITEPATH . '/core/model.php';
    $methods_scripts = array_diff(scandir(SITEPATH . '/app/models/'), array('.', '..'));
    foreach ($methods_scripts as $script) {
        require_once SITEPATH . '/app/models/' . $script;
    }

    require_once SITEPATH . '/core/view.php';
    
    require_once SITEPATH . '/core/controller.php';
    $methods_scripts = array_diff(scandir(SITEPATH . '/app/controllers/'), array('.', '..'));
    foreach ($methods_scripts as $script) {
        require_once SITEPATH . '/app/controllers/' . $script;
    }
    
    $methods_scripts = array_diff(scandir(SITEPATH . '/app/handlers/'), array('.', '..'));
    foreach ($methods_scripts as $script) {
        require_once SITEPATH . '/app/handlers/' . $script;
    }
    /*
    Здесь обычно подключаются дополнительные модули, реализующие различный функционал:
        > аутентификацию
        > кеширование
        > работу с формами
        > абстракции для доступа к данным
        > ORM
        > Unit тестирование
        > Benchmarking
        > Работу с изображениями
        > Backup
        > и др.
    */
    require_once SITEPATH . '/core/helper.php';
    require_once SITEPATH . '/core/images.php';
});

// Маршрутизатор
require_once SITEPATH . '/core/route.php';
require_once SITEPATH . '/core/routerConfig.php';
    