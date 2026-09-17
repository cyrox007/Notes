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

$viewPath = $module . '/views/index.php';
nativeMessengerAssert(is_file($viewPath), 'native Messenger module view is missing');
$view = (string) file_get_contents($viewPath);
nativeMessengerAssert(str_contains($view, "$view->layout('core/base'"), 'Messenger does not use the native application shell');
foreach (['{extends', '{include', '{foreach', '{if', '$smarty'] as $legacyToken) {
    nativeMessengerAssert(!str_contains($view, $legacyToken), "Messenger native view still contains Smarty token {$legacyToken}");
}
nativeMessengerAssert(str_contains($view, "$view->e($currentUser['uid'] ?? '')"), 'current Messenger user UID is not escaped');
nativeMessengerAssert(str_contains($view, "$view->e($contact['uid'] ?? '')"), 'Messenger contact UID is not escaped');
nativeMessengerAssert(str_contains($view, "'socket_ticket' => $socket_ticket ?? ''"), 'socket ticket is not propagated into native shell');
nativeMessengerAssert(str_contains($view, "'socket_url' => $socket_url ?? ''"), 'socket URL is not propagated into native shell');

foreach (['messenger-app', 'messenger-connection', 'dialog-list', 'chat-active', 'message-list', 'message-input', 'message-send-button', 'message-attach-button', 'message-file-input', 'new-chat-dialog', 'group-info-dialog', 'group-member-list'] as $id) {
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
foreach (['pendingPasteFiles', "addEventListener('paste'", 'stagePastedFiles(files)', 'app.submitComposer = () =>', 'sendFiles(files).then', "appPath('/messenger/upload')"] as $needle) {
    nativeMessengerAssert(str_contains($media, $needle), "Messenger media contract is missing: {$needle}");
}

$connectionUx = (string) file_get_contents($root . '/assets/js/messenger-connection-ux.js');
foreach (['ticketSubject', "window.wspace.path('/messenger/socket-ticket')", 'nextSubject !== activeTicketSubject'] as $needle) {
    nativeMessengerAssert(str_contains($connectionUx, $needle), "Messenger connection UX contract is missing: {$needle}");
}

$qaCss = (string) file_get_contents($root . '/assets/css/live-qa-final.css');
nativeMessengerAssert(str_contains($qaCss, '.messenger-app .messenger-message'), 'high-specificity Messenger bubble containment fix is missing');
nativeMessengerAssert(str_contains($qaCss, 'max-width: 100%'), 'Messenger media containment max-width fix is missing');

$controller = (string) file_get_contents($module . '/controllers/MessagerController.php');
foreach (['SocketTicket::issue', "'socket_ticket' => $socketTicket", "'socket_url' => $socketUrl", 'MessengerMediaService'] as $needle) {
    nativeMessengerAssert(str_contains($controller, $needle), "Messenger controller contract is missing: {$needle}");
}

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

$handlerFiles = ['PingSocket.php', 'MessangerSocket.php', 'DialogStateSocket.php', 'ReceiptSocket.php', 'MediaSocket.php', 'GroupSocket.php', 'SearchSocket.php', 'ForwardSocket.php', 'ReactionSocket.php'];
foreach ($handlerFiles as $handlerFile) {
    $path = $module . '/socket/' . $handlerFile;
    nativeMessengerAssert(is_file($path), "socket handler {$handlerFile} is missing");
    $source = (string) file_get_contents($path);
    nativeMessengerAssert(!str_contains($source, 'Workerman\\') && !str_contains($source, 'TcpConnection'), "business socket {$handlerFile} still depends on Workerman transport");
    nativeMessengerAssert(str_contains($source, 'SocketConnection'), "business socket {$handlerFile} does not use SocketConnection boundary");
    require_once $path;
}

$nativeConnectionPath = $module . '/socket/NativeSocketConnection.php';
$nativeServerPath = $module . '/socket/NativeMessengerServer.php';
nativeMessengerAssert(is_file($nativeConnectionPath) && is_file($nativeServerPath), 'native Messenger transport runtime is missing');
$nativeConnectionSource = (string) file_get_contents($nativeConnectionPath);
$nativeServerSource = (string) file_get_contents($nativeServerPath);
foreach (['extends SocketConnection', 'SocketFrameCodec::encodeText', 'SocketFrameCodec::decodeClientFrames'] as $needle) {
    nativeMessengerAssert(str_contains($nativeConnectionSource, $needle), "native connection contract is missing: {$needle}");
}
foreach (['stream_socket_server(', 'stream_select(', 'SocketHandshake::tryParse', 'SocketTicket::validate', "hasPermission($userId, 'messenger.use')", "unset($payload['user_uid'], $payload['user_id'], $payload['from_user_id'])", 'ALLOWED_ROUTES', 'HANDSHAKE_TIMEOUT_SECONDS', 'maxConnections'] as $needle) {
    nativeMessengerAssert(str_contains($nativeServerSource, $needle), "native server contract is missing: {$needle}");
}

$server = (string) file_get_contents($root . '/ws_server/server.php');
nativeMessengerAssert(str_contains($server, 'NativeMessengerServer'), 'WebSocket entrypoint does not start native runtime');
nativeMessengerAssert(!str_contains($server, 'Workerman\\') && !str_contains($server, 'WorkermanConnectionAdapter'), 'WebSocket entrypoint still routes through Workerman');
foreach (['WS_MAX_CONNECTIONS', 'WS_MAX_PAYLOAD_BYTES', "'status'", "'restart'"] as $needle) {
    nativeMessengerAssert(str_contains($server, $needle), "native entrypoint contract is missing: {$needle}");
}

$manifest = json_decode((string) file_get_contents($module . '/module.json'), true);
nativeMessengerAssert(is_array($manifest) && ($manifest['runtime']['mode'] ?? null) === 'isolated', 'Messenger manifest is not isolated');
nativeMessengerAssert(is_file($module . '/runtime.php') && is_file($module . '/MessengerRuntimeProvider.php'), 'Messenger isolated runtime provider is missing');

$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
nativeMessengerAssert(is_array($composer), 'composer.json is invalid');
nativeMessengerAssert(!isset($composer['require']['workerman/workerman']), 'Workerman is still a runtime Composer dependency');
nativeMessengerAssert(!is_file($root . '/app/socket/WorkermanConnectionAdapter.php'), 'obsolete Workerman compatibility adapter still exists');
$coreSource = (string) file_get_contents($root . '/core.php');
nativeMessengerAssert(!str_contains($coreSource, 'vendor/autoload.php'), 'core runtime still depends on Composer vendor autoload');

require_once __DIR__ . '/native_websocket_protocol_contract.php';

echo "[OK] isolated vendor-free native Messenger view, transport, runtime and protocol contract\n";
