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

/** @var array<string,array<int,TcpConnection>> $connections */
$connections = [];
$host = trim((string) (getenv('WS_HOST') ?: '0.0.0.0'));
$port = (int) (getenv('WS_PORT') ?: 27800);

$worker = new Worker(sprintf('websocket://%s:%d', $host, $port));

$allowedRoutes = [
    'PingSocket' => ['index'],
    'MessangerSocket' => [
        'get_dialogs',
        'load',
        'create_dialog',
        'message_send',
        'edit_message',
        'delete_message',
        'mark_read',
        'user_typing',
        'stop_typing',
    ],
    'DialogStateSocket' => [
        'list',
        'pin',
        'archive',
        'mute',
    ],
    'ReceiptSocket' => [
        'list',
        'delivered',
    ],
    'MediaSocket' => [
        'send',
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

        $user = UserModel::select('uid', 'is_active')->where('id', '=', $userId)->first();
        if (!$user || empty($user->uid) || (int) $user->is_active !== 1) {
            $connection->close();
            return;
        }

        $connection->uid = (string) $user->uid;
        $connection->userId = $userId;
        $connection->authenticated = true;
        $connectionKey = spl_object_id($connection);
        $connections[$connection->uid][$connectionKey] = $connection;

        $connection->send(json_encode([
            'action' => 'Authorized',
            'user_uid' => $connection->uid,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    };
};

$worker->onClose = function (TcpConnection $connection) use (&$connections): void {
    if (!isset($connection->uid)) {
        return;
    }

    $uid = (string) $connection->uid;
    unset($connections[$uid][spl_object_id($connection)]);
    if (empty($connections[$uid])) {
        unset($connections[$uid]);
    }
};

$worker->onWorkerStart = function () use (&$connections): void {
    Timer::add(5, function () use (&$connections): void {
        foreach (array_keys($connections) as $uid) {
            foreach ($connections[$uid] ?? [] as $connectionKey => $connection) {
                if ($connection->pingWithoutResponseCount >= 3) {
                    unset($connections[$uid][$connectionKey]);
                    $connection->destroy();
                    continue;
                }

                $connection->send(json_encode(['action' => 'Ping']));
                $connection->pingWithoutResponseCount++;
            }

            if (empty($connections[$uid])) {
                unset($connections[$uid]);
            }
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

    $payload = $data['data'] ?? [];
    if (!is_array($payload)) {
        return;
    }

    unset($payload['user_uid'], $payload['user_id'], $payload['from_user_id']);

    try {
        $handler = new $fullClassName();
        $handler->$methodName($connections, $connection, (string) $connection->uid, $payload);
    } catch (\Throwable $e) {
        error_log(sprintf('WebSocket handler failure for %s: %s', $action, $e->getMessage()));
    }
};

Worker::runAll();