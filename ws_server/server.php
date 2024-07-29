<?php 

ini_set('display_errors', 1);
if (!defined('SITEPATH')) {
    define('SITEPATH', dirname(__FILE__).'/..');
}
error_reporting(E_ALL);
ini_set('error_log', SITEPATH . '/.logs/php-errors.log');

require_once SITEPATH . '/core.php';

use Workerman\Lib\Timer;
use Workerman\Worker;

use Workerman\Connection\TcpConnection;

$connections = []; // сюда будем складывать все подключения

// Стартуем WebSocket-сервер на порту 27800
$worker = new Worker("websocket://0.0.0.0:27800");

$worker->onConnect = function($connection) use(&$connections) {
    // Эта функция выполняется при подключении пользователя к WebSocket-серверу
    $connection->onWebSocketConnect = function($connection) use(&$connections) {
        $connection->uid = $_GET['user_uid'];
        $connection->pingWithoutResponseCount = 0;
        $connections[$connection->uid] = $connection;
        $messageData = [
            'action' => 'Authorized'
        ];
        
        $connection->send(json_encode($messageData, JSON_UNESCAPED_UNICODE));
    };
};

$worker->onClose = function($connection) use(&$connections)
{
    // Эта функция выполняется при закрытии соединения
    if (!isset($connections[$connection->uid])) {
        return;
    }
    
    // Удаляем соединение из списка
    unset($connections[$connection->uid]);

    foreach ($connections as $c) {
        // здесь мы отправим в браузер параметр статуса оффлайн
    }
};

$worker->onWorkerStart = function($worker) use (&$connections) {
    $interval = 5; // пингуем каждые 5 секунд

    Timer::add($interval, function() use(&$connections) {
        foreach ($connections as $c) {
            // Если ответ от клиента не пришел 3 раза, то удаляем соединение из списка
            // и оповещаем всех участников об "отвалившемся" пользователе
            if ($c->pingWithoutResponseCount >= 3) {
                echo $c->uid." отвалился\n";
                unset($connections[$c->uid]); 
                $c->destroy(); // уничтожаем соединение
                
                // рассылаем оповещение
                foreach ($connections as $c) {
                    // здесь мы отправим в браузер параметр статуса оффлайн
                }
            } else {
                $pingMsg = [
                    'action' => 'Ping'
                ];
                $c->send(json_encode($pingMsg));
                $c->pingWithoutResponseCount++; // увеличиваем счетчик пингов
            }
        }
    });
};

$worker->onMessage = function(TcpConnection $connection, $message) use (&$connections) {
    // Распаковываем JSON
    $data = json_decode($message, true);

    if (!isset($data['action'])) {
        return;
    }
    
    $action = $data['action'];
    
    if (strpos($action, ':') === false) {
        return;
    }

    list($className, $methodName) = explode(':', $action, 2);

    $fullClassName = "App\\Sockets\\" . $className;

    if (!class_exists($fullClassName)) {
        error_log("Class {$fullClassName} not found");
        return;
    }

    $classInterface = new $fullClassName();

    if (!method_exists($classInterface, $methodName)) {
        error_log("Method {$methodName} does not exist in class {$fullClassName}");
        return;
    }

    $dataParams = isset($data['data']) ? $data['data'] : [];
    $classInterface->$methodName($connections, $connection, ...$dataParams);
};

Worker::runAll();