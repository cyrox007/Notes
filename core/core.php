<?php
    session_start();
    // подключаем файлы ядра
    
    spl_autoload_register(function () {
        require_once 'core/config.php';
        require_once 'core/model.php';
        $methods_scripts = array_diff(scandir('app/models/'), array('.', '..'));
        foreach ($methods_scripts as $script) {
            require_once 'app/models/' . $script;
        }

        require_once 'core/view.php';
        
        require_once 'core/controller.php';
        $methods_scripts = array_diff(scandir('app/controllers/'), array('.', '..'));
        foreach ($methods_scripts as $script) {
            require_once 'app/controllers/' . $script;
        }
        
        $methods_scripts = array_diff(scandir('app/handlers/'), array('.', '..'));
        foreach ($methods_scripts as $script) {
            require_once 'app/handlers/' . $script;
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
        require_once 'core/helper.php';
        require_once 'core/images.php';
    });

    // Маршрутизатор
    require_once 'core/route.php';
    require_once 'core/routerConfig.php';