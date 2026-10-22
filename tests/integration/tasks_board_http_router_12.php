<?php
declare(strict_types=1);

/**
 * Настоящий маршрутизатор Notes для изолированного встроенного HTTP-сервера PHP.
 * Только тестовая база, никаких входящих подключений извне.
 */
$name = getenv('DBNAME') ?: '';
if (!is_string($name) || !str_contains(strtolower($name), 'test')) {
    http_response_code(503);
    exit('Не разрешено подключение к рабочей БД');
}
$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    $special = [
        'App\\Services\\TaskBoardService' => '/modules/tasks/services/TaskBoardService.php',
        'App\\Services\\Tasks\\TaskBoardVersionMutationService' =>
            '/modules/tasks/services/TaskBoardVersionMutationService.php',
    ];
    $relative = $special[$class] ?? null;
    if ($relative === null) {
        if (str_starts_with($class, 'Core\\')) {
            $relative = '/core/' . substr($class, 5) . '.php';
        } elseif (str_starts_with($class, 'App\\Services\\')) {
            $relative = '/app/services/' . substr($class, 13) . '.php';
        } elseif (str_starts_with($class, 'App\\Controllers\\')) {
            $relative = '/modules/tasks/controllers/' . substr($class, 16) . '.php';
        } elseif (str_starts_with($class, 'Modules\\Tasks\\')) {
            $name = substr($class, 14);
            foreach (['/modules/tasks/services/', '/modules/tasks/'] as $prefix) {
                if (is_file($root . $prefix . $name . '.php')) {
                    $relative = $prefix . $name . '.php';
                    break;
                }
            }
        }
    }
    if ($relative !== null && is_file($root . $relative)) {
        require_once $root . $relative;
    }
});
require_once $root . '/core/Config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/core/Router.php';
$provider = require $root . '/modules/tasks/runtime.php';
$router = \Core\Router::getInstance();
$provider->registerRoutes($router);
$router->dispatch();
