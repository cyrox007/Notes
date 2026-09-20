<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$module = $root . '/modules/messenger';

function nativeMessengerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$manifest = json_decode((string) file_get_contents($module . '/module.json'), true, 32, JSON_THROW_ON_ERROR);
nativeMessengerAssert(($manifest['runtime']['mode'] ?? null) === 'isolated', 'Messenger manifest is not isolated');
nativeMessengerAssert(($manifest['runtime']['entrypoint'] ?? null) === 'runtime.php', 'Messenger isolated entrypoint drifted');
nativeMessengerAssert(is_file($module . '/runtime.php'), 'Messenger runtime entrypoint is missing');

$viewPath = $module . '/views/index.php';
nativeMessengerAssert(is_file($viewPath), 'native Messenger module view is missing');
$view = (string) file_get_contents($viewPath);
nativeMessengerAssert(str_contains($view, '$view->layout(\'core/base\''), 'Messenger does not use the native application shell');
foreach (['{extends', '{include', '{foreach', '{if', '$smarty'] as $legacyToken) {
    nativeMessengerAssert(!str_contains($view, $legacyToken), "Messenger native view still contains Smarty token {$legacyToken}");
}
nativeMessengerAssert(str_contains($view, '$view->e($currentUser[\'uid\'] ?? \'\')'), 'current Messenger user UID is not escaped');
nativeMessengerAssert(str_contains($view, '$view->e($contact[\'uid\'] ?? \'\')'), 'Messenger contact UID is not escaped');
nativeMessengerAssert(str_contains($view, "'socket_ticket' => \$socket_ticket ?? ''"), 'socket ticket is not propagated into native shell');
nativeMessengerAssert(str_contains($view, "'socket_url' => \$socket_url ?? ''"), 'socket URL is not propagated into native shell');

$messengerStyle = (string) file_get_contents($module . '/views/style.css');
nativeMessengerAssert(str_contains($messengerStyle, '--msg-surface:var(--ui-surface)'), 'Messenger no longer consumes shared theme surface tokens');
nativeMessengerAssert(str_contains($messengerStyle, '--msg-accent:var(--module-accent'), 'Messenger no longer consumes the shared module accent system');
nativeMessengerAssert(str_contains($view, "'body_class' => 'workspace-viewport workspace-viewport--messenger'"), 'Messenger does not request the viewport workspace layout');
nativeMessengerAssert(str_contains($messengerStyle, 'min-height:0;flex:1;display:grid'), 'Messenger root is not flexed into the available viewport height');
nativeMessengerAssert(!str_contains($messengerStyle, '100dvh -'), 'Messenger returned to fragile hard-coded viewport height subtraction');

foreach ([
    'messenger-app', 'messenger-connection', 'dialog-list', 'chat-active',
    'message-list', 'message-input', 'message-send-button', 'message-attach-button',
    'message-file-input', 'new-chat-dialog', 'group-info-dialog', 'group-member-list',
] as $id) {
    nativeMessengerAssert(str_contains($view, 'id="' . $id . '"'), "Messenger DOM hook {$id} is missing");
}

$cssFiles = ['style.css', 'media.css', 'forwarding.css', 'reactions.css', 'voice.css', 'group.css', 'search.css'];
$jsFiles = ['protocol-origin.js', 'script.js', 'activity.js', 'dialog-actions.js', 'receipts.js', 'media.js', 'forwarding.js', 'reactions.js', 'voice.js', 'group.js', 'search.js'];
foreach (array_merge($cssFiles, $jsFiles) as $asset) {
    nativeMessengerAssert(str_contains($view, "'{$asset}'"), "Messenger native view does not load {$asset}");
    nativeMessengerAssert(is_file($module . '/views/' . $asset), "Messenger module asset {$asset} is missing");
}

$literalOpen = '{' . 'literal}';
$literalClose = '{/' . 'literal}';
foreach ($jsFiles as $asset) {
    $source = (string) file_get_contents($module . '/views/' . $asset);
    $source = str_replace([$literalOpen, $literalClose], '', $source);
    foreach (['{$', '{if ', '{foreach ', '{include '] as $smartyToken) {
        nativeMessengerAssert(!str_contains($source, $smartyToken), "Messenger JS {$asset} still requires Smarty expansion: {$smartyToken}");
    }
}

$script = (string) file_get_contents($module . '/views/script.js');
nativeMessengerAssert(str_contains($script, 'window.wspace.messenger = app'), 'Messenger app bootstrap contract is missing');
nativeMessengerAssert(str_contains($script, 'socketConfig'), 'Messenger client no longer consumes socket runtime config');

$media = (string) file_get_contents($module . '/views/media.js');
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

$mediaCss = (string) file_get_contents($module . '/views/media.css');
nativeMessengerAssert(str_contains($messengerStyle, '.messenger-message{'), 'Messenger bubble layout contract is missing');
nativeMessengerAssert(str_contains($messengerStyle, 'max-width:76%'), 'Messenger bubble containment contract is missing');
nativeMessengerAssert(str_contains($mediaCss, 'max-width:min(420px,100%)'), 'Messenger media containment contract is missing');

$controllerPath = $module . '/controllers/MessagerController.php';
nativeMessengerAssert(is_file($controllerPath), 'Messenger controller is not module-owned');
$controller = (string) file_get_contents($controllerPath);
nativeMessengerAssert(str_contains($controller, 'SocketTicket::issue'), 'Messenger controller no longer issues short-lived socket tickets');
nativeMessengerAssert(str_contains($controller, "'socket_ticket' => \$socketTicket"), 'socket ticket is not passed to Messenger view');
nativeMessengerAssert(str_contains($controller, "'socket_url' => \$socketUrl"), 'socket URL is not passed to Messenger view');
nativeMessengerAssert(str_contains($controller, 'MessengerMediaService'), 'protected Messenger media service boundary is missing');

$connectionBoundary = $module . '/socket/SocketConnection.php';
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
    'PingSocket.php', 'MessangerSocket.php', 'DialogStateSocket.php', 'ReceiptSocket.php',
    'MediaSocket.php', 'GroupSocket.php', 'SearchSocket.php', 'ForwardSocket.php', 'ReactionSocket.php',
];
foreach ($handlerFiles as $handlerFile) {
    $path = $module . '/socket/' . $handlerFile;
    nativeMessengerAssert(is_file($path), "socket handler {$handlerFile} is missing");
    $handlerSource = (string) file_get_contents($path);
    nativeMessengerAssert(!str_contains($handlerSource, 'Workerman\\'), "business socket {$handlerFile} still imports Workerman");
    nativeMessengerAssert(!str_contains($handlerSource, 'TcpConnection'), "business socket {$handlerFile} still depends on TcpConnection");
    nativeMessengerAssert(str_contains($handlerSource, 'SocketConnection'), "business socket {$handlerFile} does not use SocketConnection boundary");
}

$nativeConnectionPath = $module . '/socket/NativeSocketConnection.php';
$nativeServerPath = $module . '/socket/NativeMessengerServer.php';
nativeMessengerAssert(is_file($nativeConnectionPath), 'native stream connection implementation is missing');
nativeMessengerAssert(is_file($nativeServerPath), 'native Messenger WebSocket server is missing');
$nativeConnectionSource = (string) file_get_contents($nativeConnectionPath);
$nativeServerSource = (string) file_get_contents($nativeServerPath);
nativeMessengerAssert(str_contains($nativeConnectionSource, 'extends SocketConnection'), 'native connection does not implement SocketConnection boundary');
nativeMessengerAssert(str_contains($nativeConnectionSource, 'SocketFrameCodec::encodeText'), 'native connection does not frame outgoing text messages');
nativeMessengerAssert(str_contains($nativeConnectionSource, 'SocketFrameCodec::decodeClientFrames'), 'native connection does not decode RFC6455 client frames');
nativeMessengerAssert(str_contains($nativeServerSource, 'stream_socket_server('), 'native server does not own a TCP listener');
nativeMessengerAssert(str_contains($nativeServerSource, 'stream_select('), 'native server does not provide an event loop');
nativeMessengerAssert(str_contains($nativeServerSource, 'SocketHandshake::tryParse'), 'native server does not perform RFC6455 upgrade parsing');
nativeMessengerAssert(str_contains($nativeServerSource, 'SocketTicket::validate'), 'native server lost signed ticket validation');
nativeMessengerAssert(str_contains($nativeServerSource, "hasPermission(\$userId, 'messenger.use')"), 'native server lost Messenger RBAC checks');
nativeMessengerAssert(str_contains($nativeServerSource, "unset(\$payload['user_uid'], \$payload['user_id'], \$payload['from_user_id'])"), 'native server lost anti-impersonation payload stripping');
nativeMessengerAssert(str_contains($nativeServerSource, 'ALLOWED_ROUTES'), 'native server lost action allowlist');
nativeMessengerAssert(str_contains($nativeServerSource, 'HANDSHAKE_TIMEOUT_SECONDS'), 'native server lacks handshake timeout');
nativeMessengerAssert(str_contains($nativeServerSource, 'maxConnections'), 'native server lacks connection limit');

$server = (string) file_get_contents($root . '/ws_server/server.php');
nativeMessengerAssert(str_contains($server, 'NativeMessengerServer'), 'WebSocket entrypoint does not start native runtime');
nativeMessengerAssert(!str_contains($server, 'Workerman\\'), 'WebSocket entrypoint still imports Workerman');
nativeMessengerAssert(!str_contains($server, 'WorkermanConnectionAdapter'), 'WebSocket entrypoint still routes through Workerman adapter');
nativeMessengerAssert(str_contains($server, 'WS_MAX_CONNECTIONS'), 'native entrypoint does not expose connection cap');
nativeMessengerAssert(str_contains($server, 'WS_MAX_PAYLOAD_BYTES'), 'native entrypoint does not expose payload cap');
nativeMessengerAssert(str_contains($server, "'status'"), 'native entrypoint lost process status command');
nativeMessengerAssert(str_contains($server, "'restart'"), 'native entrypoint lost restart command');

$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
nativeMessengerAssert(is_array($composer), 'composer.json is invalid');
nativeMessengerAssert(!isset($composer['require']['workerman/workerman']), 'Workerman is still a runtime Composer dependency');
nativeMessengerAssert(!is_file($module . '/socket/WorkermanConnectionAdapter.php'), 'obsolete Workerman compatibility adapter still exists');
nativeMessengerAssert(!is_file($root . '/app/socket/NativeMessengerServer.php'), 'Messenger WebSocket runtime leaked back into app/socket');
nativeMessengerAssert(!is_file($root . '/app/controllers/MessagerController.php'), 'Messenger HTTP controller leaked back into app/controllers');
$coreSource = (string) file_get_contents($root . '/core.php');
nativeMessengerAssert(!str_contains($coreSource, 'vendor/autoload.php'), 'core runtime still depends on Composer vendor autoload');

require_once __DIR__ . '/native_websocket_protocol_contract.php';

echo "[OK] isolated vendor-free native Messenger view, transport, runtime and protocol contract\n";
