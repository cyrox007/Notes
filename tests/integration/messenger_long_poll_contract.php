<?php

declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$root = dirname(__DIR__, 2);

function failLongPollContract(string $message): never
{
    fwrite(STDERR, "Контракт Messenger long-poll не выполнен: {$message}\n");
    exit(1);
}

function assertLongPollContract(bool $condition, string $message): void
{
    if (!$condition) {
        failLongPollContract($message);
    }
}

$runtime = file_get_contents($root . '/modules/messenger/runtime.php');
$provider = file_get_contents($root . '/modules/messenger/MessengerRuntimeProvider.php');
$controller = file_get_contents($root . '/modules/messenger/controllers/MessengerRealtimeController.php');
$service = file_get_contents($root . '/modules/messenger/services/MessengerLongPollService.php');
$revisionService = file_get_contents($root . '/modules/messenger/services/MessengerRealtimeRevisionService.php');
$connection = file_get_contents($root . '/modules/messenger/socket/BufferedSocketConnection.php');
$server = file_get_contents($root . '/modules/messenger/socket/NativeMessengerServer.php');
$client = file_get_contents($root . '/modules/messenger/views/script.js');
$connectionUx = file_get_contents($root . '/assets/js/messenger-connection-ux.js');
$globalNotifications = file_get_contents($root . '/assets/js/messenger-global-notifications.js');
$tabCoordinator = file_get_contents($root . '/assets/js/messenger-tab-coordinator.js');
$baseView = file_get_contents($root . '/app/views/core/base.php');
$entrypoint = file_get_contents($root . '/index.php');
$messengerRunbook = file_get_contents($root . '/docs/MESSENGER_SERVER.md');
$hostingRunbook = file_get_contents($root . '/docs/HOSTING_INSTALL.md');
$deploymentCompatibility = file_get_contents($root . '/docs/DEPLOYMENT_COMPATIBILITY.md');
$operationsRunbook = file_get_contents($root . '/docs/OPERATIONS.md');
$releaseNotes = file_get_contents($root . '/docs/releases/v1.0.2.md');

foreach ([
    'runtime' => $runtime,
    'provider' => $provider,
    'controller' => $controller,
    'service' => $service,
    'revision service' => $revisionService,
    'connection' => $connection,
    'server' => $server,
    'client' => $client,
    'connection UX' => $connectionUx,
    'global notifications' => $globalNotifications,
    'tab coordinator' => $tabCoordinator,
    'base view' => $baseView,
    'application entrypoint' => $entrypoint,
    'Messenger runbook' => $messengerRunbook,
    'hosting runbook' => $hostingRunbook,
    'deployment compatibility' => $deploymentCompatibility,
    'operations runbook' => $operationsRunbook,
    '1.0.2 release notes' => $releaseNotes,
] as $label => $source) {
    assertLongPollContract(is_string($source) && $source !== '', "cannot read {$label} source");
}

assertLongPollContract(
    str_contains($runtime, "/services/MessengerLongPollService.php")
    && str_contains($runtime, "/services/MessengerRealtimeRevisionService.php")
    && str_contains($runtime, "/controllers/MessengerRealtimeController.php")
    && str_contains($runtime, "/socket/BufferedSocketConnection.php"),
    'isolated Messenger runtime does not own all fallback components'
);

assertLongPollContract(
    str_contains($provider, "'GET', '/realtime/poll'")
    && str_contains($provider, "'POST', '/realtime/action'"),
    'Messenger module does not register both fallback HTTP routes'
);

assertLongPollContract(
    str_contains($provider, 'CSRFMiddleware::class')
    && preg_match("/'\\/realtime\\/action'.*CSRFMiddleware::class/s", $provider) === 1,
    'Messenger fallback mutation route must enforce CSRF'
);

assertLongPollContract(
    str_contains($controller, 'session_write_close()'),
    'long poll must release the PHP session lock before waiting'
);
assertLongPollContract(
    str_contains($controller, "dispatchTransportMessage")
    && str_contains($controller, "'long_poll'"),
    'HTTP fallback must reuse the canonical realtime dispatcher'
);
assertLongPollContract(
    str_contains($controller, "rawPost('data'"),
    'fallback actions must preserve exact message bytes instead of HTML-sanitizing JSON text'
);
assertLongPollContract(
    str_contains($server, 'publishMutationRevision')
    && str_contains($server, 'MessengerRealtimeRevisionService')
    && str_contains($server, '->bump()')
    && !str_contains($controller, 'new MessengerRealtimeRevisionService'),
    'durable mutations must publish one shared realtime revision inside the common dispatcher'
);
assertLongPollContract(
    str_contains($service, 'MESSENGER_LONG_POLL_TIMEOUT_SECONDS')
    && str_contains($controller, 'connection_aborted()'),
    'long-poll wait must be bounded and abort-aware'
);
assertLongPollContract(
    str_contains($entrypoint, 'isMessengerLongPollRequest()')
    && str_contains($entrypoint, 'handleSuspendedMessengerLongPoll()')
    && str_contains($entrypoint, "'suspended' => true")
    && str_contains($entrypoint, "'retry_after_ms'")
    && preg_match(
        '/function handleMaintenanceMode.*?isMessengerLongPollRequest\(\).*?handleSuspendedMessengerLongPoll\(\)/s',
        $entrypoint
    ) === 1
    && preg_match(
        '/function handleSchemaUpgradeRequired.*?isMessengerLongPollRequest\(\).*?handleSuspendedMessengerLongPoll\(\)/s',
        $entrypoint
    ) === 1,
    'maintenance/schema barrier must suspend background Long Poll with HTTP 200 instead of producing background 5xx'
);
assertLongPollContract(
    str_contains($service, 'FULL_FINGERPRINT_INTERVAL_SECONDS = 5.0')
    && str_contains($service, "REVISION_SETTING_KEY = 'messenger_realtime_revision'")
    && str_contains($service, 'activityFingerprint(')
    && str_contains($controller, "request->get('revision'")
    && str_contains($controller, "request->get('activity_cursor'")
    && str_contains($client, "query.set('revision'")
    && str_contains($client, "query.set('activity_cursor'")
    && str_contains($globalNotifications, "query.set('revision'")
    && str_contains($globalNotifications, "query.set('activity_cursor'"),
    'Long Poll must use cheap revision/activity hints and retain a periodic full fingerprint safety scan'
);
assertLongPollContract(
    str_contains($controller, "'suspended' => true")
    && str_contains($controller, "'retry_after_ms' => 3000")
    && !str_contains($controller, "\$this->jsonFailure('Резервный realtime-канал временно недоступен', 503)"),
    'background Long Poll must not emit HTTP 5xx while Messenger schema is temporarily unavailable'
);

assertLongPollContract(
    str_contains($revisionService, "messenger_realtime_revision")
    && str_contains($revisionService, 'system_settings')
    && str_contains($revisionService, 'ON DUPLICATE KEY UPDATE')
    && str_contains($revisionService, 'setting_value = CAST('),
    'realtime revision bridge must use an atomic shared-database revision'
);

assertLongPollContract(
    str_contains($connection, 'extends SocketConnection')
    && str_contains($connection, 'drainPayloads'),
    'HTTP fallback adapter must implement the transport-neutral SocketConnection boundary'
);

assertLongPollContract(
    str_contains($server, 'public function dispatchTransportMessage')
    && str_contains($server, '$handlerConnections = $connections ?? $this->connections'),
    'WebSocket server must expose the same allow-listed handler dispatcher to fallback transport'
);
assertLongPollContract(
    str_contains($server, 'FALLBACK_REVISION_CHECK_INTERVAL_SECONDS')
    && str_contains($server, 'pollFallbackRevisionBridge')
    && str_contains($server, "'action' => 'sync_required'"),
    'WebSocket server must bridge durable HTTP fallback revisions to connected clients'
);

assertLongPollContract(
    str_contains($client, 'scheduleLongPollFallback(')
    && str_contains($client, 'startLongPoll(')
    && str_contains($client, 'stopLongPoll(')
    && str_contains($client, 'pauseLongPollRequest(')
    && str_contains($client, 'resumeLongPoll(')
    && str_contains($client, '/messenger/realtime/poll')
    && str_contains($client, '/messenger/realtime/action')
    && str_contains($client, 'X-CSRF-Token')
    && str_contains($client, 'getCSRFToken')
    && str_contains($client, 'this.socketAuthorized'),
    'Messenger client does not implement guarded WebSocket/long-poll failover'
);
assertLongPollContract(
    str_contains($connectionUx, 'pauseLongPollRequest')
    && str_contains($connectionUx, 'resumeLongPoll'),
    'WebSocket ticket recovery must release a long-poll worker before HTTP refresh'
);
assertLongPollContract(
    str_contains($client, 'longPollWatchdogTimer')
    && str_contains($client, 'watchdogExpired')
    && str_contains($client, 'Long Poll · переподключение…')
    && str_contains($client, "scheduleLongPollFallback('WebSocket подключается', 1200)")
    && str_contains($client, "scheduleLongPollFallback('WebSocket недоступен', 1200)"),
    'Messenger page Long Poll must recover from hung requests and take over immediately when WebSocket fails'
);
assertLongPollContract(
    str_contains($connectionUx, "app.startLongPoll?.('сеть восстановлена')")
    && str_contains($connectionUx, 'app.pauseLongPollRequest?.()'),
    'network transitions must resume Long Poll without waiting for a WebSocket reconnect'
);
assertLongPollContract(
    str_contains($client, 'markSessionUnavailable()')
    && str_contains($client, "error.code = 'session_unavailable'")
    && str_contains($client, 'this.sessionUnavailable = true')
    && str_contains($connectionUx, "'wspace:messenger-session-unavailable'")
    && str_contains($connectionUx, 'sessionUnavailable = true'),
    '401/403 must terminate both Messenger transports and stop reconnect loops'
);
assertLongPollContract(
    str_contains($globalNotifications, '/messenger/realtime/poll')
    && str_contains($globalNotifications, 'LONG_POLL_WATCHDOG_MS')
    && str_contains($globalNotifications, 'startLongPoll()')
    && str_contains($globalNotifications, 'stopLongPoll()')
    && str_contains($globalNotifications, 'scheduleLongPollFallback()')
    && str_contains($globalNotifications, "if (socketUrl === '')")
    && str_contains($globalNotifications, 'showDialogUpdate(dialog)')
    && str_contains($globalNotifications, "document.visibilityState !== 'visible'")
    && str_contains($globalNotifications, 'pauseLongPoll()'),
    'global Messenger notifications must keep working through Long Poll without holding hidden tabs'
);
assertLongPollContract(
    str_contains($tabCoordinator, 'wspace:messenger-global-transport-owner')
    && str_contains($tabCoordinator, 'BroadcastChannel')
    && str_contains($tabCoordinator, 'scheduleRenewal()')
    && str_contains($tabCoordinator, 'release()')
    && str_contains($globalNotifications, 'coordinatorFactory')
    && str_contains($globalNotifications, "type: 'request_state'")
    && str_contains($globalNotifications, 'transportEnabled = false')
    && strpos($baseView, 'messenger-tab-coordinator.js') < strpos($baseView, 'messenger-global-notifications.js'),
    'global Messenger transport must elect one visible tab and share badge state with peers'
);
assertLongPollContract(
    str_contains($client, "case 'sync_required':")
    && str_contains($client, 'syncDurableState()')
    && str_contains($client, "MessangerSocket:get_dialogs")
    && str_contains($client, "MessangerSocket:load"),
    'WebSocket clients must resync canonical durable state after a fallback revision'
);
assertLongPollContract(
    str_contains($client, "state !== 'online' && state !== 'fallback'"),
    'composer must remain usable in long-poll fallback mode'
);
assertLongPollContract(
    str_contains($connectionUx, 'app.longPollActive === true')
    && str_contains($connectionUx, "Long Poll"),
    'connection UX must keep fallback usable while WebSocket reconnects'
);

assertLongPollContract(
    str_contains($messengerRunbook, 'authenticated HTTP long poll')
    && str_contains($messengerRunbook, 'MESSENGER_LONG_POLL_TIMEOUT_SECONDS')
    && str_contains($hostingRunbook, 'HTTP long poll')
    && str_contains($deploymentCompatibility, 'HTTP long poll')
    && str_contains($releaseNotes, 'HTTP long-poll fallback'),
    'актуальная release/deployment документация должна описывать поддерживаемый HTTP fallback'
);
assertLongPollContract(
    !str_contains($operationsRunbook, 'требует запущенный Workerman')
    && !str_contains($messengerRunbook, 'realtime Messenger корректно запустить нельзя'),
    'current operations docs must not restore the pre-fallback/Workerman deployment contract'
);

restore_error_handler();
fwrite(STDOUT, "Контракт Messenger long-poll fallback: OK\n");
