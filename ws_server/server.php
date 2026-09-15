<?php

declare(strict_types=1);

ini_set('display_errors', '0');
if (!defined('SITEPATH')) {
    define('SITEPATH', dirname(__FILE__) . '/..');
}
if (!defined('WORKSPACE_DEFER_MODULE_LIFECYCLE')) {
    define('WORKSPACE_DEFER_MODULE_LIFECYCLE', true);
}
error_reporting(E_ALL);
ini_set('error_log', sys_get_temp_dir() . '/workspace-organizer-ws-startup.log');

require_once SITEPATH . '/core.php';

$configuredLog = trim((string) (getenv('LOG_FILE') ?: ''));
if ($configuredLog !== '') {
    $logDirectory = dirname($configuredLog);
    if ((is_dir($logDirectory) || @mkdir($logDirectory, 0700, true)) && is_writable($logDirectory)) {
        ini_set('error_log', $configuredLog);
    }
}

use App\Handlers\SocketTicket;
use App\Models\UserModel;
use App\Services\PermissionService;
use Core\WebSocketEndpoint;
use Workerman\Connection\TcpConnection;
use Workerman\Lib\Timer;
use Workerman\Protocols\Websocket;
use Workerman\Worker;

/** @var array<string,array<int,TcpConnection>> $connections */
$connections = [];
$host = WebSocketEndpoint::bindHost();
$port = WebSocketEndpoint::port();
$publicUrl = WebSocketEndpoint::publicUrl();

// Use an explicit TCP listener + Workerman protocol class instead of relying on
// URI-scheme protocol probing. TLS terminates at the public reverse proxy; this
// process intentionally stays on an internal plain WebSocket listener.
$worker = new Worker(sprintf('tcp://%s:%d', $host, $port));
$worker->name = 'workspace-messenger';
$worker->protocol = Websocket::class;

error_log(sprintf(
    'WebSocket listener configured: tcp://%s:%d; public=%s',
    $host,
    $port,
    $publicUrl
));
if (WebSocketEndpoint::usesSameOriginProxy()) {
    error_log(sprintf(
        'WebSocket reverse proxy required: %s -> %s',
        WebSocketEndpoint::proxyPath(),
        WebSocketEndpoint::proxyBackendUrl()
    ));
}

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
    'GroupSocket' => [
        'info',
        'refresh',
        'rename',
        'add_members',
        'remove_member',
        'set_role',
        'transfer_owner',
        'leave',
    ],
    'SearchSocket' => [
        'all',
        'messages',
        'dialogs',
    ],
    'ForwardSocket' => [
        'saved',
        'save_message',
        'forward',
    ],
    'ReactionSocket' => [
        'list',
        'toggle',
    ],
];

$allowedOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => rtrim(trim($origin), '/'),
    explode(',', (string) (getenv('WS_ALLOWED_ORIGINS') ?: getenv('SITEURL') ?: ''))
)));

// Instantiate the RBAC service lazily after Workerman has entered the worker
// process. This avoids creating a PDO connection in a pre-fork master process.
$canUseMessenger = static function (int $userId): bool {
    return (new PermissionService())->hasPermission($userId, 'messenger.use');
};

$removeConnection = static function (TcpConnection $connection) use (&$connections): void {
    if (!isset($connection->uid)) {
        return;
    }

    $uid = (string) $connection->uid;
    unset($connections[$uid][spl_object_id($connection)]);
    if (empty($connections[$uid])) {
        unset($connections[$uid]);
    }
};

$worker->onConnect = function (TcpConnection $connection) use (&$connections, $allowedOrigins, $canUseMessenger): void {
    $connection->authenticated = false;
    $connection->pingWithoutResponseCount = 0;

    $connection->onWebSocketConnect = function (TcpConnection $connection) use (&$connections, $allowedOrigins, $canUseMessenger): void {
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

        if ($userId === null || !$canUseMessenger($userId)) {
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
        $connectionKey = spl_object_id($connection);
        $connections[$connection->uid][$connectionKey] = $connection;

        $connection->send(json_encode([
            'action' => 'Authorized',
            'user_uid' => $connection->uid,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    };
};

$worker->onClose = function (TcpConnection $connection) use ($removeConnection): void {
    $removeConnection($connection);
};

$worker->onWorkerStart = function () use (&$connections): void {
    // core.php validates module manifests in the pre-fork master, but deliberately
    // defers persisted lifecycle reconciliation. Reset any accidentally inherited
    // singleton connection, then create the lifecycle store inside this worker so
    // every PDO handle is process-local.
    \Core\DatabaseManager::resetInstance();
    \Core\ModuleRegistry::boot(
        SITEPATH . '/modules',
        \Core\Version::VERSION,
        new \Core\ModuleLifecycleStore(\Core\DatabaseManager::getInstance())
    );

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

$worker->onMessage = function (TcpConnection $connection, string $message) use (&$connections, $allowedRoutes, $removeConnection, $canUseMessenger): void {
    if (($connection->authenticated ?? false) !== true || !isset($connection->uid, $connection->userId)) {
        $connection->close();
        return;
    }

    // Re-check the effective permission for every inbound message. Blocking,
    // deactivation, role removal or module-permission revocation therefore takes
    // effect without waiting for a new WebSocket connection.
    if (!$canUseMessenger((int) $connection->userId)) {
        $removeConnection($connection);
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
