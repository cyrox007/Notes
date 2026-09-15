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

echo "[OK] native Messenger view contract\n";
