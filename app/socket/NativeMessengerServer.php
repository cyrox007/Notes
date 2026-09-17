<?php

declare(strict_types=1);

namespace App\Sockets;

use App\Handlers\SocketTicket;
use App\Models\UserModel;
use App\Services\LicenseRuntimePolicy;
use App\Services\PermissionService;
use Closure;
use Core\DatabaseManager;
use Core\ModuleLifecycleStore;
use Core\ModuleRegistry;
use Core\Version;
use RuntimeException;
use Throwable;

/**
 * Vendor-free Messenger WebSocket runtime.
 *
 * TLS stays at the reverse proxy. This process owns the internal TCP listener,
 * RFC6455 upgrade/frame lifecycle and dispatch into the existing Messenger
 * socket handlers.
 */
final class NativeMessengerServer
{
    private const HEARTBEAT_INTERVAL_SECONDS = 5.0;
    private const HANDSHAKE_TIMEOUT_SECONDS = 10.0;

    /** @var array<string,list<string>> */
    private const ALLOWED_ROUTES = [
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
            'activity',
        ],
        'DialogStateSocket' => ['list', 'pin', 'archive', 'mute'],
        'ReceiptSocket' => ['list', 'delivered'],
        'MediaSocket' => ['send'],
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
        'SearchSocket' => ['all', 'messages', 'dialogs'],
        'ForwardSocket' => ['saved', 'save_message', 'forward'],
        'ReactionSocket' => ['list', 'toggle'],
    ];

    /**
     * Actions proven not to persist user/application state. All other allowed
     * actions are treated as mutations and require a valid installation license
     * once a production trust root is configured. Activity/typing notifications
     * are ephemeral presence only and therefore remain available in read-only.
     *
     * @var array<string,list<string>>
     */
    private const READ_ONLY_ROUTES = [
        'PingSocket' => ['index'],
        'MessangerSocket' => ['get_dialogs', 'load', 'user_typing', 'stop_typing', 'activity'],
        'DialogStateSocket' => ['list'],
        'ReceiptSocket' => ['list'],
        'GroupSocket' => ['info', 'refresh'],
        'SearchSocket' => ['all', 'messages', 'dialogs'],
        'ReactionSocket' => ['list'],
    ];

    /** @var resource|null */
    private $listener = null;

    /** @var array<int,NativeSocketConnection> */
    private array $clients = [];

    /** @var array<string,array<int,SocketConnection>> */
    private array $connections = [];

    /** @var list<string> */
    private array $allowedOrigins;

    private LicenseRuntimePolicy $licensePolicy;
    private Closure $ticketValidator;
    private Closure $messengerPermissionChecker;
    private Closure $userUidResolver;
    private bool $running = false;
    private float $lastHeartbeatAt = 0.0;

    /**
     * @param list<string> $allowedOrigins
     */
    public function __construct(
        private string $host,
        private int $port,
        array $allowedOrigins,
        private int $maxConnections = 256,
        private int $maxPayloadBytes = SocketFrameCodec::DEFAULT_MAX_PAYLOAD_BYTES,
        ?LicenseRuntimePolicy $licensePolicy = null,
        ?callable $ticketValidator = null,
        ?callable $messengerPermissionChecker = null,
        ?callable $userUidResolver = null
    ) {
        if ($this->port < 1 || $this->port > 65535) {
            throw new RuntimeException('Invalid WebSocket listener port');
        }
        if ($this->maxConnections < 1 || $this->maxConnections > 10000) {
            throw new RuntimeException('Invalid WebSocket connection limit');
        }
        if ($this->maxPayloadBytes < 1024 || $this->maxPayloadBytes > 16_777_216) {
            throw new RuntimeException('Invalid WebSocket payload limit');
        }

        $normalized = [];
        foreach ($allowedOrigins as $origin) {
            $origin = rtrim(trim((string) $origin), '/');
            if ($origin !== '') {
                $normalized[$origin] = true;
            }
        }
        $this->allowedOrigins = array_keys($normalized);
        $this->licensePolicy = $licensePolicy ?? new LicenseRuntimePolicy();
        $this->ticketValidator = $ticketValidator !== null
            ? Closure::fromCallable($ticketValidator)
            : static fn (string $ticket): ?int => SocketTicket::validate($ticket);
        $this->messengerPermissionChecker = $messengerPermissionChecker !== null
            ? Closure::fromCallable($messengerPermissionChecker)
            : static fn (int $userId): bool => (new PermissionService())->hasPermission($userId, 'messenger.use');
        $this->userUidResolver = $userUidResolver !== null
            ? Closure::fromCallable($userUidResolver)
            : static function (int $userId): ?string {
                $user = UserModel::select('uid')->where('id', '=', $userId)->first();
                return $user && !empty($user->uid) ? (string) $user->uid : null;
            };
    }

    public function run(): void
    {
        $this->bootLifecycleStore();
        $this->listener = $this->createListener();
        $this->running = true;
        $this->lastHeartbeatAt = microtime(true);
        $this->installSignalHandlers();

        error_log(sprintf(
            'Native WebSocket listener started: tcp://%s:%d; max_connections=%d; max_payload=%d',
            $this->host,
            $this->port,
            $this->maxConnections,
            $this->maxPayloadBytes
        ));

        try {
            while ($this->running) {
                $this->iterate();
            }
        } finally {
            $this->shutdown();
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function bootLifecycleStore(): void
    {
        DatabaseManager::resetInstance();
        ModuleRegistry::boot(
            SITEPATH . '/modules',
            Version::VERSION,
            new ModuleLifecycleStore(DatabaseManager::getInstance())
        );
    }

    /** @return resource */
    private function createListener()
    {
        $host = trim($this->host, '[]');
        $displayHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $errno = 0;
        $errstr = '';
        $listener = @stream_socket_server(
            sprintf('tcp://%s:%d', $displayHost, $this->port),
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if (!is_resource($listener)) {
            throw new RuntimeException(sprintf(
                'Unable to start WebSocket listener on %s:%d: %s (%d)',
                $this->host,
                $this->port,
                $errstr !== '' ? $errstr : 'unknown socket error',
                $errno
            ));
        }
        stream_set_blocking($listener, false);
        return $listener;
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        foreach (['SIGTERM', 'SIGINT', 'SIGHUP'] as $signalName) {
            if (!defined($signalName)) {
                continue;
            }
            $signal = constant($signalName);
            if (!is_int($signal)) {
                continue;
            }
            pcntl_signal($signal, function (): void {
                $this->stop();
            });
        }
    }

    private function iterate(): void
    {
        if (!is_resource($this->listener)) {
            throw new RuntimeException('WebSocket listener is not available');
        }

        $read = [$this->listener];
        $write = [];
        foreach ($this->clients as $client) {
            $stream = $client->stream();
            if (!is_resource($stream) || $client->isDestroyed()) {
                continue;
            }
            if (!$client->isClosing()) {
                $read[] = $stream;
            }
            if ($client->hasPendingOutput()) {
                $write[] = $stream;
            }
        }

        $except = null;
        $selected = @stream_select($read, $write, $except, 0, 200_000);
        if ($selected === false) {
            $this->tick();
            return;
        }

        foreach ($read as $stream) {
            if ($stream === $this->listener) {
                try {
                    $this->acceptPendingClients();
                } catch (Throwable $e) {
                    error_log('Native WebSocket accept failure: ' . $e->getMessage());
                }
                continue;
            }
            $id = get_resource_id($stream);
            $client = $this->clients[$id] ?? null;
            if ($client === null) {
                continue;
            }
            try {
                $this->readClient($client);
            } catch (Throwable $e) {
                error_log(sprintf('Native WebSocket client read failure (%d): %s', $id, $e->getMessage()));
                $this->dropClient($client);
            }
        }

        foreach ($write as $stream) {
            if (!is_resource($stream)) {
                continue;
            }
            $id = get_resource_id($stream);
            $client = $this->clients[$id] ?? null;
            if ($client === null) {
                continue;
            }
            try {
                $client->flush();
            } catch (Throwable $e) {
                error_log(sprintf('Native WebSocket client write failure (%d): %s', $id, $e->getMessage()));
                $this->dropClient($client);
            }
        }

        $this->tick();
    }

    private function acceptPendingClients(): void
    {
        if (!is_resource($this->listener)) {
            return;
        }

        while (true) {
            $stream = @stream_socket_accept($this->listener, 0);
            if (!is_resource($stream)) {
                return;
            }

            if (count($this->clients) >= $this->maxConnections) {
                @fwrite(
                    $stream,
                    "HTTP/1.1 503 Service Unavailable\r\nConnection: close\r\nContent-Length: 0\r\n\r\n"
                );
                @fclose($stream);
                continue;
            }

            $client = new NativeSocketConnection($stream);
            $this->clients[$client->id()] = $client;
        }
    }

    private function readClient(NativeSocketConnection $client): void
    {
        if ($client->isClosing() || $client->isDestroyed()) {
            return;
        }

        $stream = $client->stream();
        if (!is_resource($stream)) {
            $this->dropClient($client);
            return;
        }

        $chunk = @fread($stream, 65536);
        if ($chunk === false) {
            $this->dropClient($client);
            return;
        }
        if ($chunk === '') {
            if (feof($stream)) {
                $this->dropClient($client);
            }
            return;
        }

        $client->appendInput($chunk);

        if (!$client->handshakeComplete) {
            if (!$this->processHandshake($client)) {
                return;
            }
        }

        if ($client->handshakeComplete && $client->inputBuffer() !== '' && !$client->isClosing()) {
            $this->processFrames($client);
        }
    }

    private function processHandshake(NativeSocketConnection $client): bool
    {
        try {
            $handshake = SocketHandshake::tryParse($client->inputBuffer());
        } catch (Throwable $e) {
            error_log('Native WebSocket handshake parse failed: ' . $e->getMessage());
            $client->rejectHttp(400, 'Bad Request');
            return false;
        }

        if ($handshake === null) {
            return false;
        }

        $client->consumeInput((int) $handshake['consumed']);
        $origin = rtrim((string) ($handshake['origin'] ?? ''), '/');
        if ($this->allowedOrigins !== [] && ($origin === '' || !in_array($origin, $this->allowedOrigins, true))) {
            error_log('Rejected WebSocket origin: ' . ($origin !== '' ? $origin : '[missing]'));
            $client->rejectHttp(403, 'Forbidden');
            return false;
        }

        $query = is_array($handshake['query'] ?? null) ? $handshake['query'] : [];
        $ticketValue = $query['ticket'] ?? '';
        $ticket = is_scalar($ticketValue) ? (string) $ticketValue : '';

        try {
            $userId = ($this->ticketValidator)($ticket);
        } catch (Throwable $e) {
            error_log('WebSocket ticket validation failed: ' . $e->getMessage());
            $client->rejectHttp(403, 'Forbidden');
            return false;
        }

        if (!is_int($userId) || $userId < 1 || !$this->canUseMessenger($userId)) {
            $client->rejectHttp(403, 'Forbidden');
            return false;
        }

        try {
            $userUid = ($this->userUidResolver)($userId);
        } catch (Throwable $e) {
            error_log('WebSocket user lookup failed: ' . $e->getMessage());
            $client->rejectHttp(503, 'Service Unavailable');
            return false;
        }
        if (!is_string($userUid) || trim($userUid) === '') {
            $client->rejectHttp(403, 'Forbidden');
            return false;
        }

        $client->uid = $userUid;
        $client->userId = $userId;
        $client->authenticated = true;
        $client->handshakeComplete = true;
        $this->connections[$client->uid][$client->id()] = $client;

        $client->queueRaw((string) $handshake['response']);
        $this->sendJson($client, [
            'action' => 'Authorized',
            'user_uid' => $client->uid,
        ]);
        $this->sendReadOnlyLicenseState($client);

        return true;
    }

    private function processFrames(NativeSocketConnection $client): void
    {
        try {
            $frames = $client->decodeFrames($this->maxPayloadBytes);
        } catch (Throwable $e) {
            error_log('Rejected WebSocket frame: ' . $e->getMessage());
            $client->closeWithCode(1002, 'Protocol error');
            return;
        }

        foreach ($frames as $frame) {
            if ($client->isClosing() || $client->isDestroyed()) {
                return;
            }

            $opcode = (int) $frame['opcode'];
            $payload = (string) $frame['payload'];
            $fin = (bool) $frame['fin'];

            if ($opcode === SocketFrameCodec::OPCODE_PING) {
                $client->sendPong($payload);
                if ($client->isClosing()) {
                    return;
                }
                continue;
            }
            if ($opcode === SocketFrameCodec::OPCODE_PONG) {
                continue;
            }
            if ($opcode === SocketFrameCodec::OPCODE_CLOSE) {
                try {
                    $close = SocketFrameCodec::decodeClosePayload($payload);
                } catch (Throwable $e) {
                    $code = str_contains($e->getMessage(), 'UTF-8') ? 1007 : 1002;
                    $client->closeWithCode($code, 'Invalid close frame');
                    return;
                }
                $client->closeWithCode($close['code'] ?? 1000, $close['reason']);
                return;
            }
            if ($opcode === SocketFrameCodec::OPCODE_BINARY) {
                $client->closeWithCode(1003, 'Binary messages are not supported');
                return;
            }

            if ($opcode === SocketFrameCodec::OPCODE_TEXT) {
                if ($client->fragmentOpcode !== null) {
                    $client->closeWithCode(1002, 'Unexpected data frame');
                    return;
                }
                if ($fin) {
                    $this->dispatchText($client, $payload);
                    if ($client->isClosing() || $client->isDestroyed()) {
                        return;
                    }
                    continue;
                }
                $client->fragmentOpcode = SocketFrameCodec::OPCODE_TEXT;
                $client->fragmentBuffer = $payload;
                continue;
            }

            if ($opcode === SocketFrameCodec::OPCODE_CONTINUATION) {
                if ($client->fragmentOpcode === null) {
                    $client->closeWithCode(1002, 'Unexpected continuation');
                    return;
                }
                $client->fragmentBuffer .= $payload;
                if (strlen($client->fragmentBuffer) > $this->maxPayloadBytes) {
                    $client->closeWithCode(1009, 'Message too large');
                    return;
                }
                if ($fin) {
                    $message = $client->fragmentBuffer;
                    $client->fragmentBuffer = '';
                    $client->fragmentOpcode = null;
                    $this->dispatchText($client, $message);
                    if ($client->isClosing() || $client->isDestroyed()) {
                        return;
                    }
                }
            }
        }
    }

    private function dispatchText(NativeSocketConnection $client, string $message): void
    {
        if (preg_match('//u', $message) !== 1) {
            $client->closeWithCode(1007, 'Invalid UTF-8');
            return;
        }

        if (!$client->authenticated || $client->uid === null || $client->userId === null) {
            $client->closeWithCode(1008, 'Authentication required');
            return;
        }

        if (!$this->canUseMessenger($client->userId)) {
            $client->closeWithCode(1008, 'Permission revoked');
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
        if (!isset(self::ALLOWED_ROUTES[$className])
            || !in_array($methodName, self::ALLOWED_ROUTES[$className], true)) {
            error_log('Rejected WebSocket action: ' . $action);
            return;
        }

        if (!$this->isReadOnlyAction($className, $methodName)) {
            $licenseState = $this->licensePolicy->state();
            if ($licenseState['enforced'] && !$licenseState['writable']) {
                $this->sendJson($client, [
                    'action' => 'LicenseReadOnly',
                    'code' => $licenseState['code'],
                    'message' => $licenseState['message'],
                ]);
                return;
            }
        }

        $fullClassName = __NAMESPACE__ . '\\' . $className;
        if (!class_exists($fullClassName) || !method_exists($fullClassName, $methodName)) {
            error_log('Configured WebSocket action is unavailable: ' . $action);
            return;
        }

        $payload = $data['data'] ?? [];
        if (!is_array($payload)) {
            return;
        }
        unset($payload['user_uid'], $payload['user_id'], $payload['from_user_id']);

        try {
            $handler = new $fullClassName();
            $handler->$methodName($this->connections, $client, $client->uid, $payload);
        } catch (Throwable $e) {
            error_log(sprintf('WebSocket handler failure for %s: %s', $action, $e->getMessage()));
        }
    }

    private function isReadOnlyAction(string $className, string $methodName): bool
    {
        return isset(self::READ_ONLY_ROUTES[$className])
            && in_array($methodName, self::READ_ONLY_ROUTES[$className], true);
    }

    private function sendReadOnlyLicenseState(SocketConnection $client): void
    {
        $state = $this->licensePolicy->state();
        if (!$state['enforced'] || $state['writable']) {
            return;
        }

        $this->sendJson($client, [
            'action' => 'LicenseReadOnly',
            'code' => $state['code'],
            'message' => $state['message'],
        ]);
    }

    private function canUseMessenger(int $userId): bool
    {
        try {
            return (bool) ($this->messengerPermissionChecker)($userId);
        } catch (Throwable $e) {
            error_log('Messenger permission evaluation failed: ' . $e->getMessage());
            return false;
        }
    }

    /** @param array<string,mixed> $payload */
    private function sendJson(SocketConnection $connection, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            $connection->send($json);
        }
    }

    private function tick(): void
    {
        $now = microtime(true);

        foreach ($this->clients as $client) {
            $client->enforceCloseDeadline($now);
            if ($client->isDestroyed()) {
                $this->dropClient($client);
                continue;
            }
            if ($client->isClosing()) {
                continue;
            }
            if (!$client->handshakeComplete && ($now - $client->acceptedAt) >= self::HANDSHAKE_TIMEOUT_SECONDS) {
                $client->rejectHttp(408, 'Request Timeout');
            }
        }

        if (($now - $this->lastHeartbeatAt) >= self::HEARTBEAT_INTERVAL_SECONDS) {
            $this->lastHeartbeatAt = $now;
            foreach ($this->connections as $uid => $userConnections) {
                foreach ($userConnections as $connectionId => $connection) {
                    if (!$connection instanceof NativeSocketConnection || $connection->isDestroyed()) {
                        unset($this->connections[$uid][$connectionId]);
                        continue;
                    }
                    if ($connection->isClosing()) {
                        continue;
                    }
                    if ($connection->pingWithoutResponseCount >= 3) {
                        $connection->closeWithCode(1001, 'Heartbeat timeout');
                        continue;
                    }
                    $this->sendJson($connection, ['action' => 'Ping']);
                    $connection->pingWithoutResponseCount++;
                }
                if (empty($this->connections[$uid])) {
                    unset($this->connections[$uid]);
                }
            }
        }

        foreach ($this->clients as $client) {
            $client->enforceCloseDeadline($now);
            if ($client->isDestroyed()) {
                $this->dropClient($client);
            }
        }
    }

    private function dropClient(NativeSocketConnection $client): void
    {
        $id = $client->id();
        if ($client->uid !== null && isset($this->connections[$client->uid][$id])) {
            unset($this->connections[$client->uid][$id]);
            if (empty($this->connections[$client->uid])) {
                unset($this->connections[$client->uid]);
            }
        }
        unset($this->clients[$id]);
        $client->destroy();
    }

    private function shutdown(): void
    {
        foreach ($this->clients as $client) {
            $client->destroy();
        }
        $this->clients = [];
        $this->connections = [];

        if (is_resource($this->listener)) {
            @fclose($this->listener);
        }
        $this->listener = null;
        error_log('Native WebSocket listener stopped');
    }
}
