<?php

declare(strict_types=1);

use App\Handlers\SocketTicket;
use App\Sockets\NativeMessengerServer;
use App\Sockets\NativeSocketConnection;
use Core\SessionSecurity;

$root = dirname(__DIR__, 2);
require_once $root . '/core/SessionSecurity.php';
require_once $root . '/modules/messenger/handlers/SocketTicket.php';
require_once $root . '/app/services/LicenseRuntimePolicy.php';
require_once $root . '/modules/messenger/socket/SocketConnection.php';
require_once $root . '/modules/messenger/socket/SocketFrameCodec.php';
require_once $root . '/modules/messenger/socket/NativeSocketConnection.php';
require_once $root . '/modules/messenger/socket/SocketHandshake.php';
require_once $root . '/modules/messenger/socket/NativeMessengerServer.php';

function authEpochAssert(bool $condition, string $reason): void
{
    if (!$condition) {
        throw new RuntimeException('Проверка отзыва веб-сеансов: ' . $reason);
    }
}

putenv('WS_TICKET_SECRET=notes-test-session-epoch-signing-key-with-strong-entropy-1234');
$oldTicket = SocketTicket::issue(7, null, 1);
$newTicket = SocketTicket::issue(7, null, 3);
authEpochAssert(SocketTicket::validate($newTicket) === 7,
    'сохранён прежний контракт проверки подписи билета');
authEpochAssert(SocketTicket::validateClaims($oldTicket) === [
    'user_id' => 7, 'auth_session_epoch' => 1
], 'старый билет привязан к первой версии');
authEpochAssert(SocketTicket::validateClaims($newTicket) === [
    'user_id' => 7, 'auth_session_epoch' => 3
], 'новый билет привязан к текущей версии');
authEpochAssert(SocketTicket::validateClaims($newTicket . 'tamper') === null,
    'подделка подписанного билета отклонена');
authEpochAssert(SessionSecurity::matchesAuthSessionEpoch(1, 3) === false,
    'прежний PHP-сеанс не подтверждает новую версию');
authEpochAssert(SessionSecurity::matchesAuthSessionEpoch(3, 3) === true,
    'текущий PHP-сеанс сохраняет авторизацию');

if (!function_exists('stream_socket_pair')) {
    throw new RuntimeException('Испытанию требуется локальная пара сокетов');
}

/** @return array{0:NativeSocketConnection,1:mixed} */
function sessionEpochSocket(string $ticket): array
{
    $streams = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (!is_array($streams) || count($streams) !== 2) {
        throw new RuntimeException('Не удалось создать изолированные сокеты');
    }
    $client = new NativeSocketConnection($streams[0]);
    $client->appendInput(
        "GET /ws?ticket={$ticket} HTTP/1.1\r\n"
        . "Host: example.test\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
        . "Sec-WebSocket-Version: 13\r\n\r\n"
    );
    return [$client, $streams[1]];
}

/** @param NativeMessengerServer $server */
function sessionEpochHandshake(NativeMessengerServer $server, NativeSocketConnection $client): bool
{
    $method = new ReflectionMethod(NativeMessengerServer::class, 'processHandshake');
    $method->setAccessible(true);
    return $method->invoke($server, $client);
}

$actualEpoch = 3;
$policy = new \App\Services\LicenseRuntimePolicy(
    static fn (): bool => true,
    static fn (): array => ['valid' => true, 'code' => 'valid', 'message' => 'Проверено'],
    static fn (): array => ['active' => false, 'valid' => true]
);
$server = new NativeMessengerServer(
    host: '127.0.0.1',
    port: 28701,
    allowedOrigins: [],
    licensePolicy: $policy,
    messengerPermissionChecker: static fn (int $userId): bool => true,
    messengerEntitlementChecker: static fn (): bool => true,
    userUidResolver: static fn (int $userId): string => 'test-user',
    authSessionEpochChecker: static function (int $userId, int $epoch) use (&$actualEpoch): bool {
        return $userId === 7 && $epoch === $actualEpoch;
    }
);

[$old, $oldPeer] = sessionEpochSocket($oldTicket);
authEpochAssert(sessionEpochHandshake($server, $old) === false,
    'WebSocket с отозванной эпохой не должен проходить рукопожатие');
authEpochAssert($old->isClosing(), 'рукопожатие старого билета закрыто');
$old->destroy();
fclose($oldPeer);

[$current, $currentPeer] = sessionEpochSocket($newTicket);
authEpochAssert(sessionEpochHandshake($server, $current) === true,
    'WebSocket новой эпохи авторизован');
authEpochAssert($current->authSessionEpoch === 3,
    'версия сохранена в объекте соединения');
$actualEpoch = 4;
$server->dispatchTransportMessage($current,
    '{"action":"PingSocket:index","data":{}}', null, 'websocket');
authEpochAssert($current->isClosing(),
    'после отзыва активное WebSocket-соединение закрывается перед обработкой');
$current->destroy();
fclose($currentPeer);

echo "Проверка отзыва PHP-сеансов и WebSocket: пройдена\n";
