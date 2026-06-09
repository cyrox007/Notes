<?php

declare(strict_types=1);

ini_set('display_errors', '1');

if (!defined('SITEPATH')) {
	define('SITEPATH', dirname(__FILE__) . '/..');
}

error_reporting(E_ALL);
ini_set('error_log', SITEPATH . '/.logs/php-errors.log');


require_once SITEPATH . '/core.php';

use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

/**
 * =========================================================================
 * CONNECTION STORAGE
 * =========================================================================
 *
 * Формат:
 *
 * [
 *     userId => [
 *         connectionId => TcpConnection
 *     ]
 * ]
 */
$connections = []; // сюда будем складывать все подключения

/**
 * =========================================================================
 * ROUTES
 * =========================================================================
 */
$routes = [
	'Chat:sendMessage' => [
		'class' => \App\Sockets\MessengerSocket::class,
		'method' => 'sendMessage'
	],
	'Chat:typing'=> [
		'class'=> \App\Sockets\MessengerSocket::class,
		'method'=> 'typing'
	],
	'Presence:setOnline' => [
        'class'  => \App\Sockets\PingSocket::class,
        'method' => 'setOnline'
    ],
];

/**
 * =========================================================================
 * WORKER
 * =========================================================================
 */
$worker = new Worker("websocket://0.0.0.0:27800");


/**
 * Количество процессов
 */
$worker->count = 4;

/**
 * =========================================================================
 * ON CONNECT
 * =========================================================================
 */
$worker->onWebSocketConnect = function(
    TcpConnection $connection,
    $httpBuffer
) {

        /**
         * =========================================================
         * AUTH
         * =========================================================
         */

        $token = $_GET['token'] ?? null;

        if (!$token) {
            $connection->close();
            return;
        }

        /**
         * Здесь должна быть твоя реальная авторизация
         */
        $user = validateSocketToken($token);

        if (!$user) {
            $connection->close();
            return;
        }

        /**
         * =========================================================
         * CONNECTION CONTEXT
         * =========================================================
         */

        $connection->context = [
            'uid' => (int)$user['id'],
            'lastPongAt' => time(),
        ];

        $uid = $connection->context['uid'];

        /**
         * =========================================================
         * STORE CONNECTION
         * =========================================================
         */

        if (!isset($connections[$uid])) {
            $connections[$uid] = [];
        }

        $connections[$uid][$connection->id] = $connection;

        echo "User {$uid} connected\n";

        /**
         * =========================================================
         * AUTHORIZED EVENT
         * =========================================================
         */

        $connection->send(json_encode([
            'action' => 'Authorized',
            'data' => [
                'user_id' => $uid
            ]
        ], JSON_UNESCAPED_UNICODE));
};

$worker->onClose = function ($connection) use (&$connections) {
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

$worker->onWorkerStart = function ($worker) use (&$connections) {
	$interval = 5; // пингуем каждые 5 секунд

	Timer::add($interval, function () use (&$connections) {
		foreach ($connections as $c) {
			// Если ответ от клиента не пришел 3 раза, то удаляем соединение из списка
			// и оповещаем всех участников об "отвалившемся" пользователе
			if ($c->pingWithoutResponseCount >= 3) {
				echo $c->uid . " отвалился\n";
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

$worker->onMessage = function (TcpConnection $connection, $message) use (&$connections) {
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
