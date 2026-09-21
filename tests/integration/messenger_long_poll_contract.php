<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$module = $root . '/modules/messenger';

function longPollAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] Messenger long-poll contract: {$message}\n");
        exit(1);
    }
}

function longPollSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        longPollAssert(false, 'cannot read ' . $path);
    }
    return $source;
}

$journal = longPollSource($module . '/services/MessengerEventJournal.php');
$publisher = longPollSource($module . '/services/MessengerRealtimePublisher.php');
$dispatcher = longPollSource($module . '/socket/MessengerActionDispatcher.php');
$controller = longPollSource($module . '/controllers/MessagerController.php');
$provider = longPollSource($module . '/MessengerRuntimeProvider.php');
$runtime = longPollSource($module . '/runtime.php');
$server = longPollSource($module . '/socket/NativeMessengerServer.php');
$client = longPollSource($module . '/views/script.js');
$activity = longPollSource($module . '/views/activity.js');
$view = longPollSource($module . '/views/index.php');
$licenseGuard = longPollSource($root . '/app/middlewares/EnforceLicenseMutation.php');

foreach ([
    'cursorForUserId',
    'publishToUserUid',
    'publishToUserId',
    'readSince',
    'messenger_transport_events',
    "'event_id'",
    'expires_at >= NOW()',
] as $marker) {
    longPollAssert(str_contains($journal, $marker), 'event journal missing marker: ' . $marker);
}
longPollAssert(
    str_contains($journal, "['activity', 'user_typing', 'typing_stop']")
        && str_contains($journal, 'return 15;'),
    'ephemeral activity events must expire quickly'
);

longPollAssert(
    str_contains($publisher, 'publishToUserUid')
        && str_contains($publisher, 'readSince')
        && str_contains($publisher, 'while ($connection->transportCursor < $eventId'),
    'publisher must journal first and drain missing events in cursor order'
);

foreach ([
    'ALLOWED_ROUTES',
    'READ_ONLY_ROUTES',
    "hasPermission($userId, 'messenger.use')",
    "unset($payload['user_uid'], $payload['user_id'], $payload['from_user_id'])",
    "'MaintenanceMode'",
    "'LicenseReadOnly'",
] as $marker) {
    longPollAssert(str_contains($dispatcher, $marker), 'shared dispatcher missing security marker: ' . $marker);
}

longPollAssert(str_contains($server, 'MessengerActionDispatcher'), 'native WebSocket does not use shared dispatcher');
longPollAssert(str_contains($server, 'MessengerEventJournal'), 'native WebSocket does not consume shared event journal');
longPollAssert(str_contains($server, 'pumpTransportEvents'), 'native WebSocket journal pump is missing');
longPollAssert(str_contains($server, "'transport_cursor'"), 'WebSocket authorization cursor handoff is missing');

longPollAssert(str_contains($controller, 'public function transportPoll('), 'long-poll endpoint is missing');
longPollAssert(str_contains($controller, 'public function transportSend('), 'HTTP send endpoint is missing');
longPollAssert(str_contains($controller, 'session_write_close()'), 'long-poll must release the PHP session lock');
longPollAssert(str_contains($controller, "rawPost('payload'"), 'HTTP send must preserve raw message JSON bytes');
longPollAssert(str_contains($controller, 'MessengerActionDispatcher'), 'HTTP send bypasses shared dispatcher');
longPollAssert(str_contains($controller, 'readSince('), 'HTTP send does not close cursor gaps before advancing');

longPollAssert(str_contains($provider, "'/transport/poll'"), 'transport poll route is missing');
longPollAssert(str_contains($provider, "'/transport/send'"), 'transport send route is missing');
longPollAssert(
    preg_match(
        "/'\/transport\/send'.*CSRFMiddleware::class/s",
        $provider
    ) === 1,
    'transport send route must be CSRF protected'
);
longPollAssert(
    str_contains($licenseGuard, "'/messenger/transport/send'")
        && str_contains($dispatcher, "isReadOnlyAction"),
    'global license guard/action-level fallback license boundary is incomplete'
);

foreach ([
    '/services/MessengerEventJournal.php',
    '/services/MessengerRealtimePublisher.php',
    '/socket/BufferedSocketConnection.php',
    '/socket/MessengerActionDispatcher.php',
] as $runtimeFile) {
    longPollAssert(str_contains($runtime, $runtimeFile), 'isolated runtime does not load ' . $runtimeFile);
}

foreach ([
    'data-transport-cursor',
    'transport_cursor',
] as $marker) {
    longPollAssert(str_contains($view . $controller, $marker), 'server-rendered cursor handoff missing: ' . $marker);
}

foreach ([
    'eventCursor',
    'seenEventIds',
    'startLongPoll',
    'longPollLoop',
    '/messenger/transport/poll',
    '/messenger/transport/send',
    'handleIncomingEvent',
    '&cursor=',
] as $marker) {
    longPollAssert(str_contains($client, $marker), 'browser fallback missing marker: ' . $marker);
}
longPollAssert(
    str_contains($activity, "app.sendEvent('MessangerSocket:activity'"),
    'activity path bypasses fallback-capable sendEvent'
);

$canonicalSchema = longPollSource($root . '/database/messenger_module_schema.sql');
$legacySchema = longPollSource($root . '/database/messenger_schema.sql');
$migration = longPollSource($root . '/database/migrations/20260921_messenger_transport_events.sql');
foreach ([$canonicalSchema, $legacySchema, $migration] as $schemaSource) {
    longPollAssert(
        str_contains($schemaSource, 'messenger_transport_events')
            && str_contains($schemaSource, 'idx_messenger_transport_user_cursor')
            && str_contains($schemaSource, 'expires_at'),
        'transport journal schema contract is incomplete'
    );
}

$migrationManifest = json_decode(
    longPollSource($root . '/database/migrations/manifest.json'),
    true,
    32,
    JSON_THROW_ON_ERROR
);
longPollAssert(
    in_array('20260921_messenger_transport_events.sql', $migrationManifest['migrations'] ?? [], true),
    'transport journal migration is not in global migration manifest'
);

$moduleManifest = json_decode(
    longPollSource($module . '/module.json'),
    true,
    32,
    JSON_THROW_ON_ERROR
);
longPollAssert(
    in_array('messenger_transport_events', $moduleManifest['database']['tables'] ?? [], true),
    'Messenger module does not own transport journal table'
);
longPollAssert(
    in_array(
        'database/migrations/20260921_messenger_transport_events.sql',
        $moduleManifest['database']['migrations'] ?? [],
        true
    ),
    'Messenger module does not own transport journal migration'
);

require_once $module . '/socket/SocketConnection.php';
require_once $module . '/socket/BufferedSocketConnection.php';

$buffer = new \App\Sockets\BufferedSocketConnection();
$buffer->uid = 'transport-user';
$buffer->userId = 7;
$buffer->authenticated = true;
$buffer->send('{"action":"contract","value":1}');
longPollAssert(
    $buffer->drain() === [['action' => 'contract', 'value' => 1]],
    'buffered HTTP connection does not preserve direct socket-style responses'
);

fwrite(STDOUT, "[OK] Messenger WebSocket -> long-poll fallback contract\n");
