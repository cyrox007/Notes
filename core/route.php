<?php
    class Route {
        static function getTrack($track, $controller) {
            $uri = urldecode($_SERVER['REQUEST_URI']); // Получаем и декодируем запрос
            
            $uri_route = explode('/', $uri); // рабиваем запрос на массив
            $param = $uri_route[2 + $name_folder_attach_len]; // получаем параметр запроса
            $track_track = str_replace('(param)', $param, $track); // подставляем параметр запроса в полученный маршрут
            $routes = array( // создаем массив, полученных маршрута и контролера
                $controller => $track_track
            );

            foreach ($routes as $key => $route) { // перебираем массив маршрутов и контроллер функций 
                if ($uri == $route) { // проверяем какой маршрут совпал с запросом
                    
                    $contoller_action = explode('@', $key); // разбиваем контроллер функцию на массив
                    $controller_name = $contoller_action[0]; 
                    $action_name = $contoller_action[1];
                    Route::Loader($controller_name, $action_name); // передаем переменные с именем контроллера в функцию загрузки
                }
            }
        }

        static function Loader($controller_name, $action_name) {
            $controller_file = strtolower($controller_name) . '.php';
            $controller_path = "app/controllers/" . $controller_file;
            $action_name = 'action_' . $action_name;
            
            $model_name = str_replace("Controller_", "Model_", $controller_name); // получим имя модели
            
            $model_file = strtolower($model_name) . '.php';
            $model_path = "app/models/" . $model_file;
            if (file_exists($model_path)) {
                include "app/models/" . $model_file;
            }
            
            if (file_exists($controller_path)) {
                include "app/controllers/" . $controller_file;
            }
            else {
                Route::ErrorPage404();
            }
            
            // Создаем контроллер
            $controller = new $controller_name;
            $action = $action_name;

            // вызываем действие контроллера
            if (method_exists($controller, $action)) {
                $controller->$action();
            }
            else {
                Route::ErrorPage404();
            }
        }
        /* static function start() {
            // контроллер и действие по умолчанию
            $controller_name = 'Main';

            $action_name = 'index';
            $routes = explode('/', $_SERVER['REQUEST_URI']);

            // получаем имя экшена
            if (!empty($routes[1])) {
                $action_name = $routes[1];
            }

            // добавляем префиксы
            $model_name = 'Model_' . $controller_name;
            $controller_name = 'Controller_' . $controller_name;
            $action_name = 'action_' . $action_name;

            // подцепляем файл с классом модели (файла модели может и не быть)

            $model_file = strtolower($model_name) . '.php';
            $model_path = "app/models/" . $model_file;
            if (file_exists($model_path)) {
                include "app/models/" . $model_file;
            }

            // подцепляем файл с классом контроллера
            $controller_file = strtolower($controller_name) . '.php';
            $controller_path = "app/controllers/" . $controller_file;
            if (file_exists($controller_path)) {
                include "app/controllers/" . $controller_file;
            }
            else {
                Route::ErrorPage404();
            }

            // Создаем контроллер
            $controller = new $controller_name;
            $action = $action_name;

            // вызываем действие контроллера
            if (method_exists($controller, $action)) {
                $controller->$action();
            }
            else {
                Route::ErrorPage404();
            }
        } */

        function ErrorPage404()
        {
            $host = 'http://' . $_SERVER['HTTP_HOST'] . '/';
            header('HTTP/1.1 404 Not Found');
            header("Status: 404 Not Found");
            header('Location:'.$host.'404');
        }
    }