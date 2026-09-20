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
nativeMessengerAssert(str_contains($view, 'data-socket-url="<?= $view->e($socket_url ?? \'\') ?>"'), 'Messenger root does not carry a direct socket URL fallback');
nativeMessengerAssert(str_contains($view, 'data-socket-ticket="<?= $view->e($socket_ticket ?? \'\') ?>"'), 'Messenger root does not carry a direct socket ticket fallback');

$messengerStyle = (string) file_get_contents($module . '/views/style.css');
nativeMessengerAssert(str_contains($messengerStyle, '--msg-surface:var(--ui-surface)'), 'Messenger no longer consumes shared theme surface tokens');
nativeMessengerAssert(str_contains($messengerStyle, '--msg-accent:var(--module-accent'), 'Messenger no longer consumes the shared module accent system');
nativeMessengerAssert(str_contains($view, "'body_class' => 'workspace-viewport workspace-viewport--messenger'"), 'Messenger does not request the viewport workspace layout');
nativeMessengerAssert(str_contains($messengerStyle, 'min-height:0;flex:1;display:grid'), 'Messenger root is not flexed into the available viewport height');
nativeMessengerAssert(!str_contains($messengerStyle, '100dvh -'), 'Messenger returned to fragile hard-coded viewport height subtraction');

foreach ([
    'messenger-app', 'messenger-connection', 'dialog-list', 'chat-active',
    'message-list', 'message-input', 'message-send-button', 'message-attach-button',
    'message-file-input', 'message-storage-button', 'storage-file-dialog', 'storage-file-list',
    'workspace-create-button', 'workspace-create-menu', 'workspace-action-dialog',
    'workspace-action-form', 'new-chat-dialog', 'group-info-dialog', 'group-member-list',
] as $id) {
    nativeMessengerAssert(str_contains($view, 'id="' . $id . '"'), "Messenger DOM hook {$id} is missing");
}

$cssFiles = ['style.css', 'media.css', 'forwarding.css', 'reactions.css', 'voice.css', 'group.css', 'search.css', 'workspace-actions.css', 'storage-files.css', 'visual-refresh.css'];
$jsFiles = ['protocol-origin.js', 'script.js', 'activity.js', 'dialog-actions.js', 'receipts.js', 'media.js', 'forwarding.js', 'reactions.js', 'voice.js', 'group.js', 'search.js', 'workspace-actions.js', 'storage-files.js'];
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
nativeMessengerAssert(str_contains($script, 'runtime.socketUrl'), 'Messenger socket bootstrap does not fall back to native runtime config');
nativeMessengerAssert(str_contains($script, 'this.root.dataset.socketUrl'), 'Messenger socket bootstrap does not fall back to server-rendered DOM config');
nativeMessengerAssert(str_contains($script, "deepLink.get('dialog')"), 'Messenger cannot deep-link back to a source dialog');
nativeMessengerAssert(str_contains($script, "deepLink.get('message')"), 'Messenger cannot deep-link back to a source message');
nativeMessengerAssert(str_contains($script, 'focusRequestedMessage()'), 'Messenger source-message highlighting contract is missing');

$workspaceActions = (string) file_get_contents($module . '/views/workspace-actions.js');
nativeMessengerAssert(str_contains($workspaceActions, "appPath('/messenger/workspace/' + kind)"), 'Messenger workspace actions are not BASE_PATH-aware');
nativeMessengerAssert(str_contains($workspaceActions, "data-workspace-kind"), 'Messenger workspace task/note switch is missing');
nativeMessengerAssert(str_contains($workspaceActions, 'sourceMessage'), 'Messenger cannot create a workspace object from an existing message');

$storageFiles = (string) file_get_contents($module . '/views/storage-files.js');
nativeMessengerAssert(str_contains($storageFiles, "appPath('/messenger/workspace/files')"), 'Messenger storage picker is not BASE_PATH-aware');
nativeMessengerAssert(str_contains($storageFiles, "appPath('/messenger/workspace/file-attachment')"), 'Messenger storage attachment action is missing');
nativeMessengerAssert(str_contains($storageFiles, "appPath('/messenger/workspace/file-link')"), 'Messenger storage public-link action is missing');
nativeMessengerAssert(str_contains($storageFiles, "MediaSocket:send"), 'Messenger storage attachment is not handed to the media socket');
nativeMessengerAssert(str_contains($storageFiles, "MessangerSocket:message_send"), 'Messenger storage public link is not sent through the normal message path');

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
nativeMessengerAssert(str_contains($view, 'class="messenger-composer__tools"'), 'Messenger composer tools are not grouped for stable medium-width layout');

$visualRefresh = (string) file_get_contents($module . '/views/visual-refresh.css');
nativeMessengerAssert(str_contains($visualRefresh, 'grid-template-columns:clamp(248px,23vw,304px)'), 'Messenger balanced desktop column contract is missing');
nativeMessengerAssert(str_contains($visualRefresh, '@media(max-width:1020px)'), 'Messenger medium-width layout breakpoint is missing');
nativeMessengerAssert(str_contains($visualRefresh, '@media(max-width:760px)'), 'Messenger mobile single-pane breakpoint is missing');
$workspaceCss = (string) file_get_contents($module . '/views/workspace-actions.css');
$storageCss = (string) file_get_contents($module . '/views/storage-files.css');
nativeMessengerAssert(str_contains($workspaceCss, '.messenger-workspace-task-fields[hidden]{display:none!important}'), 'workspace task fields ignore hidden state');
nativeMessengerAssert(str_contains($workspaceCss, '.messenger-workspace-form[data-kind="note"] .messenger-workspace-task-fields{display:none!important}'), 'workspace note mode can still expose task-only fields');
nativeMessengerAssert(str_contains($workspaceActions, 'form.dataset.kind = kind'), 'workspace modal does not expose mode state to CSS');
nativeMessengerAssert(str_contains($workspaceCss, '.messenger-workspace-source[hidden]{display:none!important}'), 'workspace source context ignores hidden state');
nativeMessengerAssert(str_contains($workspaceCss, '.messenger-workspace-menu{position:absolute'), 'workspace create menu lost floating popover styling');
nativeMessengerAssert(str_contains($workspaceCss, '.messenger-workspace-menu[hidden]{display:none!important}'), 'workspace create menu ignores hidden state');
nativeMessengerAssert(str_contains($workspaceCss, '.messenger-workspace-menu__item{'), 'workspace create menu items lost native styling');
nativeMessengerAssert(str_contains($visualRefresh, '#workspace-create-menu{position:absolute!important'), 'final visual layer does not harden workspace popover positioning');
nativeMessengerAssert(str_contains($visualRefresh, '#workspace-create-menu[hidden]{display:none!important}'), 'final visual layer can expose hidden workspace popover');
nativeMessengerAssert(str_contains($storageCss, '.messenger-storage-selected[hidden]{display:none!important}'), 'storage selected-file panel ignores hidden state');
nativeMessengerAssert(str_contains($storageCss, '.messenger-storage-empty[hidden]{display:none!important}'), 'storage empty state ignores hidden state');
nativeMessengerAssert(str_contains($storageCss, '.messenger-storage-list[hidden]{display:none!important}'), 'storage empty result still reserves list space');
nativeMessengerAssert(str_contains($storageFiles, 'list.hidden = !hasFiles'), 'storage picker does not collapse an empty file list');
nativeMessengerAssert(str_contains($messengerStyle, '.messenger-primary-button:disabled'), 'Messenger modal actions do not expose disabled state');
nativeMessengerAssert(str_contains($mediaCss, 'max-width:min(420px,100%)'), 'Messenger media containment contract is missing');

$controllerPath = $module . '/controllers/MessagerController.php';
nativeMessengerAssert(is_file($controllerPath), 'Messenger controller is not module-owned');
$controller = (string) file_get_contents($controllerPath);
nativeMessengerAssert(str_contains($controller, 'SocketTicket::issue'), 'Messenger controller no longer issues short-lived socket tickets');
nativeMessengerAssert(str_contains($controller, "'socket_ticket' => \$socketTicket"), 'socket ticket is not passed to Messenger view');
nativeMessengerAssert(str_contains($controller, "'socket_url' => \$socketUrl"), 'socket URL is not passed to Messenger view');
nativeMessengerAssert(str_contains($controller, 'MessengerMediaService'), 'protected Messenger media service boundary is missing');

$workspaceControllerPath = $module . '/controllers/MessengerWorkspaceController.php';
nativeMessengerAssert(is_file($workspaceControllerPath), 'Messenger workspace action controller is missing');
$workspaceController = (string) file_get_contents($workspaceControllerPath);
nativeMessengerAssert(str_contains($workspaceController, "require('workspace.notes', WorkspaceNoteCreator::class)"), 'Messenger note action bypasses the module capability registry');
nativeMessengerAssert(str_contains($workspaceController, "require('workspace.tasks', WorkspaceTaskCreator::class)"), 'Messenger task action bypasses the module capability registry');
nativeMessengerAssert(str_contains($workspaceController, "require('workspace.files', WorkspaceFileProvider::class)"), 'Messenger file actions bypass the module capability registry');
nativeMessengerAssert(str_contains($workspaceController, 'importWorkspaceFile'), 'Messenger cannot import a private-storage file as a chat attachment');
nativeMessengerAssert(str_contains($workspaceController, 'createWorkspaceFileShare'), 'Messenger cannot create a public file link');
nativeMessengerAssert(str_contains($workspaceController, 'messageForWorkspaceAction'), 'Messenger workspace action does not validate source-message access');
nativeMessengerAssert(str_contains($workspaceController, "route('messenger')"), 'workspace object source reference does not link back to Messenger');

$provider = (string) file_get_contents($module . '/MessengerRuntimeProvider.php');
nativeMessengerAssert(str_contains($provider, "'/workspace/note'"), 'Messenger note-create route is missing');
nativeMessengerAssert(str_contains($provider, "'/workspace/task'"), 'Messenger task-create route is missing');
nativeMessengerAssert(str_contains($provider, "'/workspace/files'"), 'Messenger private-storage browse route is missing');
nativeMessengerAssert(str_contains($provider, "'/workspace/file-attachment'"), 'Messenger private-storage attachment route is missing');
nativeMessengerAssert(str_contains($provider, "'/workspace/file-link'"), 'Messenger private-storage link route is missing');
nativeMessengerAssert(str_contains($provider, 'MessengerWorkspaceController::class'), 'Messenger workspace routes are not module-owned');

$coreNoteBoundary = (string) file_get_contents($root . '/core/WorkspaceNoteCreator.php');
$coreTaskBoundary = (string) file_get_contents($root . '/core/WorkspaceTaskCreator.php');
$coreFileBoundary = (string) file_get_contents($root . '/core/WorkspaceFileProvider.php');
nativeMessengerAssert(str_contains($coreNoteBoundary, 'interface WorkspaceNoteCreator'), 'shared note creation boundary is missing');
nativeMessengerAssert(str_contains($coreTaskBoundary, 'interface WorkspaceTaskCreator'), 'shared task creation boundary is missing');
nativeMessengerAssert(str_contains($coreFileBoundary, 'interface WorkspaceFileProvider'), 'shared private-file boundary is missing');

$mediaService = (string) file_get_contents($module . '/services/MessengerMediaService.php');
nativeMessengerAssert(str_contains($mediaService, 'importWorkspaceFile('), 'Messenger media service cannot copy a private-storage file into message storage');
nativeMessengerAssert(str_contains($mediaService, "'messenger', 'max_attachment_bytes'"), 'private-storage attachment import bypasses Messenger role size policy');

$voice = (string) file_get_contents($module . '/views/voice.js');
$voiceCss = (string) file_get_contents($module . '/views/voice.css');
nativeMessengerAssert(str_contains($voice, "nativeAudio.hidden = true"), 'voice player still exposes duplicate native audio controls');
nativeMessengerAssert(str_contains($voice, "messenger-voice-player__waveform"), 'voice player waveform UI is missing');
nativeMessengerAssert(str_contains($voice, "appPath('/messenger/voice-upload')"), 'voice upload is not BASE_PATH-aware');
nativeMessengerAssert(str_contains($voice, 'window.isSecureContext === false'), 'voice recording does not explain insecure HTTP contexts');
nativeMessengerAssert(str_contains($voice, 'messenger-voice-button--unavailable'), 'voice recording control disappears instead of exposing an unavailable state');
nativeMessengerAssert(str_contains($voiceCss, '.messenger-voice-native-audio{display:none!important}'), 'native voice audio control is not visually suppressed');
nativeMessengerAssert(str_contains($voiceCss, '.messenger-message--own.messenger-message--voice .messenger-message__bubble'), 'own voice messages do not use the refreshed readable surface');

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
