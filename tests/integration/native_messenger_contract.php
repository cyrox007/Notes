<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function nativeMessengerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$viewPath = $root . '/app/views/messager_page/index.php';
nativeMessengerAssert(is_file($viewPath), 'native Messenger view is missing');
$view = (string) file_get_contents($viewPath);
nativeMessengerAssert(str_contains($view, '$view->layout(\'core/base\''), 'Messenger does not use the native application shell');
foreach (['{extends', '{include', '{foreach', '{if', '$smarty'] as $legacyToken) {
    nativeMessengerAssert(!str_contains($view, $legacyToken), "Messenger native view still contains Smarty token {$legacyToken}");
}
nativeMessengerAssert(str_contains($view, '$view->e($currentUser[\'uid\'] ?? \'\')'), 'current Messenger user UID is not escaped');
nativeMessengerAssert(str_contains($view, '$view->e($contact[\'uid\'] ?? \'\')'), 'Messenger contact UID is not escaped');
nativeMessengerAssert(str_contains($view, "'socket_ticket' => \$socket_ticket ?? ''"), 'socket ticket is not propagated into native shell');
nativeMessengerAssert(str_contains($view, "'socket_url' => \$socket_url ?? ''"), 'socket URL is not propagated into native shell');

foreach ([
    'id="messenger-app"',
    'id="messenger-connection"',
    'id="dialog-list"',
    'id="chat-active"',
    'id="message-list"',
    'id="message-input"',
    'id="message-send-button"',
    'id="message-attach-button"',
    'id="message-file-input"',
    'id="new-chat-dialog"',
    'id="group-info-dialog"',
    'id="group-member-list"',
] as $hook) {
    nativeMessengerAssert(str_contains($view, $hook), "Messenger DOM hook {$hook} is missing");
}

$cssFiles = ['style.css', 'media.css', 'forwarding.css', 'reactions.css', 'voice.css', 'group.css', 'search.css'];
$jsFiles = ['script.js', 'dialog-actions.js', 'receipts.js', 'media.js', 'forwarding.js', 'reactions.js', 'voice.js', 'group.js', 'search.js'];
foreach (array_merge($cssFiles, $jsFiles) as $asset) {
    nativeMessengerAssert(str_contains($view, "'{$asset}'"), "Messenger native view does not load {$asset}");
    nativeMessengerAssert(is_file($root . '/app/views/messager_page/' . $asset), "Messenger module asset {$asset} is missing");
}

$literalOpen = '{' . 'literal}';
$literalClose = '{/' . 'literal}';
foreach ($jsFiles as $asset) {
    $source = (string) file_get_contents($root . '/app/views/messager_page/' . $asset);
    $source = str_replace([$literalOpen, $literalClose], '', $source);
    foreach (['{$', '{if ', '{foreach ', '{include '] as $smartyToken) {
        nativeMessengerAssert(!str_contains($source, $smartyToken), "Messenger JS {$asset} still requires Smarty expansion: {$smartyToken}");
    }
}

$script = (string) file_get_contents($root . '/app/views/messager_page/script.js');
nativeMessengerAssert(str_contains($script, 'window.wspace.messenger = app'), 'Messenger app bootstrap contract is missing');
nativeMessengerAssert(str_contains($script, 'socketConfig'), 'Messenger client no longer consumes socket runtime config');

$media = (string) file_get_contents($root . '/app/views/messager_page/media.js');
nativeMessengerAssert(str_contains($media, 'pendingPasteFiles'), 'clipboard attachment staging state is missing');
nativeMessengerAssert(str_contains($media, "addEventListener('paste'"), 'clipboard paste handler is missing');
nativeMessengerAssert(str_contains($media, 'stagePastedFiles(files)'), 'clipboard files are no longer staged before send');
nativeMessengerAssert(str_contains($media, 'app.submitComposer = () =>'), 'explicit composer send hook for staged clipboard files is missing');
nativeMessengerAssert(str_contains($media, 'sendFiles(files).then'), 'staged clipboard files are not sent through explicit composer submission');
nativeMessengerAssert(str_contains($media, "appPath('/messenger/upload')"), 'Messenger media upload is not BASE_PATH-aware');

$connectionUx = (string) file_get_contents($root . '/assets/js/messenger-connection-ux.js');
nativeMessengerAssert(str_contains($connectionUx, 'ticketSubject'), 'account-switch ticket identity guard is missing');
nativeMessengerAssert(str_contains($connectionUx, "window.wspace.path('/messenger/socket-ticket')"), 'socket ticket refresh is not BASE_PATH-aware');
nativeMessengerAssert(str_contains($connectionUx, 'nextSubject !== activeTicketSubject'), 'account switch detection is missing');

$qaCss = (string) file_get_contents($root . '/assets/css/live-qa-final.css');
nativeMessengerAssert(str_contains($qaCss, '.messenger-app .messenger-message'), 'high-specificity Messenger bubble containment fix is missing');
nativeMessengerAssert(str_contains($qaCss, 'max-width: 100%'), 'Messenger media containment max-width fix is missing');

$controller = (string) file_get_contents($root . '/app/controllers/MessagerController.php');
nativeMessengerAssert(str_contains($controller, 'SocketTicket::issue'), 'Messenger controller no longer issues short-lived socket tickets');
nativeMessengerAssert(str_contains($controller, "'socket_ticket' => \$socketTicket"), 'socket ticket is not passed to Messenger view');
nativeMessengerAssert(str_contains($controller, "'socket_url' => \$socketUrl"), 'socket URL is not passed to Messenger view');
nativeMessengerAssert(str_contains($controller, 'MessengerMediaService'), 'protected Messenger media service boundary is missing');

// 1.0 WebSocket transport boundary: business handlers must not know about
// Workerman. The current Workerman dependency is isolated to the compatibility
// adapter and server bootstrap so it can be replaced in the next phase.
$connectionBoundary = $root . '/app/socket/SocketConnection.php';
nativeMessengerAssert(is_file($connectionBoundary), 'transport-neutral SocketConnection is missing');
require_once $connectionBoundary;

$fake = new class extends \App\Sockets\SocketConnection {
    public array $sent = [];
    public bool $closed = false;
    public bool $destroyed = false;

    public function send(string $payload): void { $this->sent[] = $payload; }
    public function close(): void { $this->closed = true; }
    public function destroy(): void { $this->destroyed = true; }
};
$fake->uid = 'contract-user';
$fake->userId = 42;
$fake->send('{"action":"Ping"}');
$fake->close();
$fake->destroy();
nativeMessengerAssert($fake->uid === 'contract-user' && $fake->userId === 42, 'SocketConnection does not retain auth identity state');
nativeMessengerAssert($fake->sent === ['{"action":"Ping"}'], 'SocketConnection send contract is broken');
nativeMessengerAssert($fake->closed && $fake->destroyed, 'SocketConnection lifecycle contract is broken');

$handlerFiles = [
    'PingSocket.php',
    'MessangerSocket.php',
    'DialogStateSocket.php',
    'ReceiptSocket.php',
    'MediaSocket.php',
    'GroupSocket.php',
    'SearchSocket.php',
    'ForwardSocket.php',
    'ReactionSocket.php',
];
foreach ($handlerFiles as $handlerFile) {
    $path = $root . '/app/socket/' . $handlerFile;
    nativeMessengerAssert(is_file($path), "socket handler {$handlerFile} is missing");
    $handlerSource = (string) file_get_contents($path);
    nativeMessengerAssert(!str_contains($handlerSource, 'Workerman\\'), "business socket {$handlerFile} still imports Workerman");
    nativeMessengerAssert(!str_contains($handlerSource, 'TcpConnection'), "business socket {$handlerFile} still depends on TcpConnection");
    nativeMessengerAssert(str_contains($handlerSource, 'SocketConnection'), "business socket {$handlerFile} does not use SocketConnection boundary");
    require_once $path;
}

$adapterPath = $root . '/app/socket/WorkermanConnectionAdapter.php';
nativeMessengerAssert(is_file($adapterPath), 'Workerman compatibility adapter is missing');
$adapterSource = (string) file_get_contents($adapterPath);
nativeMessengerAssert(str_contains($adapterSource, 'extends SocketConnection'), 'Workerman adapter does not implement application connection boundary');
nativeMessengerAssert(str_contains($adapterSource, 'Workerman\\Connection\\TcpConnection'), 'Workerman adapter no longer wraps TcpConnection');

$server = (string) file_get_contents($root . '/ws_server/server.php');
nativeMessengerAssert(str_contains($server, 'WorkermanConnectionAdapter'), 'WebSocket server does not route raw connections through adapter');
nativeMessengerAssert(str_contains($server, '$handler->$methodName($connections, $adapter, $adapter->uid, $payload)'), 'handler dispatch bypasses transport adapter');
nativeMessengerAssert(str_contains($server, "SocketTicket::validate(\$ticket)"), 'transport migration lost signed ticket validation');
nativeMessengerAssert(str_contains($server, "hasPermission(\$userId, 'messenger.use')"), 'transport migration lost messenger RBAC check');
nativeMessengerAssert(str_contains($server, "unset(\$payload['user_uid'], \$payload['user_id'], \$payload['from_user_id'])"), 'transport migration lost anti-impersonation payload stripping');

require_once __DIR__ . '/native_websocket_protocol_contract.php';

echo "[OK] native Messenger view, transport boundary and protocol contract\n";
