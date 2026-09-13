<?php

declare(strict_types=1);

ini_set('display_errors', '0');
if (!defined('SITEPATH')) {
    define('SITEPATH', dirname(__FILE__) . '/..');
}
error_reporting(E_ALL);
ini_set('error_log', SITEPATH . '/.logs/php-errors.log');

require_once SITEPATH . '/core.php';

use App\Handlers\SocketTicket;
use App\Models\UserModel;
use Workerman\Connection\TcpConnection;
use Workerman\Lib\Timer;
use Workerman\Worker;

$connections = [];
$host = trim((string) (getenv('WS_HOST') ?: '0.0.0.0'));
$port = (int) (getenv('WS_PORT') ?: 27800);

$worker = new Worker(sprintf('websocket://%s:%d', $host, $port));

$allowedRoutes = [
    'PingSocket' => ['index'],
    'MessangerSocket' => [
        'load',
        'get_dialogs',
        'create_dialog',
        'user_typing',
        'stop_typing',
        'message_send',
        'edit_message',
        'delete_message',
        'update_message_status',
    ],
];

$allowedOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => rtrim(trim($origin), '/'),
    explode(',', (string) (getenv('WS_ALLOWED_ORIGINS') ?: getenv('SITEURL') ?: ''))
)));

$worker->onConnect = function (TcpConnection $connection) use (&$connections, $allowedOrigins): void {
    $connection->authenticated = false;
    $connection->pingWithoutResponseCount = 0;

    $connection->onWebSocketConnect = function (TcpConnection $connection) use (&$connections, $allowedOrigins): void {
        $origin = rtrim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
        if ($allowedOrigins !== [] && ($origin === '' || !in_array($origin, $allowedOrigins, true))) {
            error_log('Rejected WebSocket origin: ' . ($origin ?: '[missing]'));
            $connection->close();
            return;
        }

        $ticket = (string) ($_GET['ticket'] ?? '');

        try {
            $userId = SocketTicket::validate($ticket);
        } catch (\Throwable $e) {
            error_log('WebSocket ticket validation failed: ' . $e->getMessage());
            $connection->close();
            return;
        }

        if ($userId === null) {
            $connection->close();
            return;
        }

        $user = UserModel::select('uid')->where('id', '=', $userId)->first();
        if (!$user || empty($user->uid)) {
            $connection->close();
            return;
        }

        $connection->uid = (string) $user->uid;
        $connection->userId = $userId;
        $connection->authenticated = true;
        $connections[$connection->uid] = $connection;

        $connection->send(json_encode([
            'action' => 'Authorized',
        ], JSON_UNESCAPED_UNICODE));
    };
};

$worker->onClose = function (TcpConnection $connection) use (&$connections): void {
    if (!isset($connection->uid)) {
        return;
    }

    if (($connections[$connection->uid] ?? null) === $connection) {
        unset($connections[$connection->uid]);
    }
};

$worker->onWorkerStart = function () use (&$connections): void {
    Timer::add(5, function () use (&$connections): void {
        foreach ($connections as $uid => $connection) {
            if ($connection->pingWithoutResponseCount >= 3) {
                unset($connections[$uid]);
                $connection->destroy();
                continue;
            }

            $connection->send(json_encode(['action' => 'Ping']));
            $connection->pingWithoutResponseCount++;
        }
    });
};

$worker->onMessage = function (TcpConnection $connection, string $message) use (&$connections, $allowedRoutes): void {
    if (($connection->authenticated ?? false) !== true || !isset($connection->uid)) {
        $connection->close();
        return;
    }

    $data = json_decode($message, true);
    if (!is_array($data) || !is_string($data['action'] ?? null)) {
        return;
    }

    $action = $data['action'];
    if (substr_count($action, ':') !== 1) {
        return;
    }

    [$className, $methodName] = explode(':', $action, 2);
    if (!isset($allowedRoutes[$className]) || !in_array($methodName, $allowedRoutes[$className], true)) {
        error_log(sprintf('Rejected WebSocket action: %s', $action));
        return;
    }

    $fullClassName = 'App\\Sockets\\' . $className;
    if (!class_exists($fullClassName) || !method_exists($fullClassName, $methodName)) {
        error_log(sprintf('Configured WebSocket action is unavailable: %s', $action));
        return;
    }

    $params = $data['data'] ?? [];
    if (!is_array($params)) {
        return;
    }

    if ($className === 'MessangerSocket') {
        unset($params['user_uid']);
        $params = ['user_uid' => $connection->uid] + $params;
    }

    try {
        $handler = new $fullClassName();
        $handler->$methodName($connections, $connection, ...$params);
    } catch (\ArgumentCountError | \TypeError $e) {
        error_log(sprintf('Invalid WebSocket payload for %s: %s', $action, $e->getMessage()));
    } catch (\Throwable $e) {
        error_log(sprintf('WebSocket handler failure for %s: %s', $action, $e->getMessage()));
    }
};

Worker::runAll();
