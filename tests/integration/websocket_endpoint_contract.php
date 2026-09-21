<?php

declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$root = dirname(__DIR__, 2);
require_once $root . '/core/WebSocketEndpoint.php';

use Core\WebSocketEndpoint;

function failWebSocketEndpoint(string $message): never
{
    fwrite(STDERR, "WebSocket endpoint contract failed: {$message}\n");
    exit(1);
}

function assertWebSocketEndpoint(bool $condition, string $message): void
{
    if (!$condition) {
        failWebSocketEndpoint($message);
    }
}

function setWebSocketEnv(array $values): void
{
    foreach (['SITEURL', 'BASE_PATH', 'WS_PUBLIC_URL', 'WS_ALLOWED_ORIGINS', 'WS_HOST', 'WS_PORT'] as $key) {
        putenv($key);
    }
    foreach ($values as $key => $value) {
        putenv($key . '=' . $value);
    }
}

setWebSocketEnv([
    'SITEURL' => 'https://example.test',
    'BASE_PATH' => '/',
    'WS_HOST' => '127.0.0.1',
    'WS_PORT' => '27800',
]);
assertWebSocketEndpoint(
    WebSocketEndpoint::publicUrl() === 'wss://example.test/ws',
    'HTTPS root install must derive same-origin /ws'
);
assertWebSocketEndpoint(WebSocketEndpoint::browserUrl() === '/ws', 'same-origin browser URL must be scheme-neutral');
assertWebSocketEndpoint(WebSocketEndpoint::proxyPath() === '/ws', 'root proxy path drifted');
assertWebSocketEndpoint(WebSocketEndpoint::proxyBackendUrl() === 'http://127.0.0.1:27800', 'loopback backend drifted');
assertWebSocketEndpoint(WebSocketEndpoint::usesSameOriginProxy(), 'root same-origin proxy was not detected');
$origins = WebSocketEndpoint::allowedOrigins();
assertWebSocketEndpoint(in_array('https://example.test', $origins, true), 'configured HTTPS origin missing');
assertWebSocketEndpoint(in_array('http://example.test', $origins, true), 'HTTP counterpart for same-origin proxy missing');

setWebSocketEnv([
    'SITEURL' => 'https://example.test',
    'BASE_PATH' => '/workspace/',
    'WS_HOST' => '127.0.0.1',
    'WS_PORT' => '27800',
]);
assertWebSocketEndpoint(
    WebSocketEndpoint::publicUrl() === 'wss://example.test/workspace/ws',
    'subdirectory install must include BASE_PATH in public WebSocket URL'
);
assertWebSocketEndpoint(WebSocketEndpoint::browserUrl() === '/workspace/ws', 'subdirectory browser path drifted');
assertWebSocketEndpoint(WebSocketEndpoint::proxyPath() === '/workspace/ws', 'subdirectory proxy path drifted');

setWebSocketEnv([
    'SITEURL' => 'http://notes.local',
    'BASE_PATH' => '/',
    'WS_HOST' => '127.0.0.1',
    'WS_PORT' => '27800',
]);
assertWebSocketEndpoint(WebSocketEndpoint::publicUrl() === 'ws://notes.local/ws', 'HTTP local URL derivation failed');
assertWebSocketEndpoint(WebSocketEndpoint::browserUrl() === '/ws', 'HTTP same-origin browser endpoint must stay relative');
$origins = WebSocketEndpoint::allowedOrigins();
assertWebSocketEndpoint(in_array('http://notes.local', $origins, true), 'HTTP origin missing');
assertWebSocketEndpoint(in_array('https://notes.local', $origins, true), 'HTTPS counterpart missing');

setWebSocketEnv([
    'SITEURL' => 'http://notes.local',
    'BASE_PATH' => '/',
    'WS_PUBLIC_URL' => 'ws://127.0.0.1:27800',
    'WS_ALLOWED_ORIGINS' => 'http://notes.local',
    'WS_HOST' => '127.0.0.1',
    'WS_PORT' => '27800',
]);
assertWebSocketEndpoint(
    WebSocketEndpoint::publicUrl() === 'ws://127.0.0.1:27800',
    'OpenServer local HTTP direct listener URL changed'
);
assertWebSocketEndpoint(
    WebSocketEndpoint::browserUrl() === 'ws://127.0.0.1:27800',
    'direct local browser endpoint must remain absolute'
);
assertWebSocketEndpoint(
    !WebSocketEndpoint::usesSameOriginProxy(),
    'direct OpenServer listener must not be classified as same-origin proxy'
);
assertWebSocketEndpoint(
    WebSocketEndpoint::allowedOrigins() === ['http://notes.local'],
    'direct listener must preserve the configured browser origin without broadening'
);

setWebSocketEnv([
    'SITEURL' => 'https://notes.local',
    'BASE_PATH' => '/',
    'WS_PUBLIC_URL' => 'wss://notes.local/ws',
    'WS_ALLOWED_ORIGINS' => 'https://notes.local',
    'WS_HOST' => '127.0.0.1',
    'WS_PORT' => '27800',
]);
assertWebSocketEndpoint(WebSocketEndpoint::publicUrl() === 'wss://notes.local/ws', 'explicit same-origin URL changed');
assertWebSocketEndpoint(WebSocketEndpoint::browserUrl() === '/ws', 'explicit same-origin browser endpoint must be relative');
assertWebSocketEndpoint(WebSocketEndpoint::usesSameOriginProxy(), 'explicit same-origin proxy not detected');
$origins = WebSocketEndpoint::allowedOrigins();
assertWebSocketEndpoint(in_array('https://notes.local', $origins, true), 'explicit allowed origin missing');
assertWebSocketEndpoint(in_array('http://notes.local', $origins, true), 'scheme counterpart was not added');

setWebSocketEnv([
    'SITEURL' => 'https://notes.local',
    'BASE_PATH' => '/',
    'WS_PUBLIC_URL' => 'wss://socket.example.test/ws',
    'WS_ALLOWED_ORIGINS' => 'https://notes.local',
    'WS_HOST' => '127.0.0.1',
    'WS_PORT' => '27800',
]);
assertWebSocketEndpoint(!WebSocketEndpoint::usesSameOriginProxy(), 'external endpoint must not be classified as same-origin proxy');
assertWebSocketEndpoint(
    WebSocketEndpoint::browserUrl() === 'wss://socket.example.test/ws',
    'external WebSocket browser endpoint must stay absolute'
);
$origins = WebSocketEndpoint::allowedOrigins();
assertWebSocketEndpoint($origins === ['https://notes.local'], 'external endpoint must not broaden origin allowlist');

setWebSocketEnv([
    'SITEURL' => 'https://notes.local',
    'BASE_PATH' => '/',
    'WS_PUBLIC_URL' => 'ws://notes.local:27800',
    'WS_HOST' => '127.0.0.1',
    'WS_PORT' => '27800',
]);
$downgradeRejected = false;
try {
    WebSocketEndpoint::publicUrl();
} catch (InvalidArgumentException) {
    $downgradeRejected = true;
}
assertWebSocketEndpoint($downgradeRejected, 'HTTPS site accepted insecure ws:// public endpoint');

setWebSocketEnv([
    'SITEURL' => 'https://notes.local',
    'BASE_PATH' => '/',
    'WS_HOST' => '0.0.0.0',
    'WS_PORT' => '27800',
]);
assertWebSocketEndpoint(
    WebSocketEndpoint::proxyBackendUrl() === 'http://127.0.0.1:27800',
    'wildcard IPv4 bind must map proxy backend to loopback'
);

setWebSocketEnv([
    'SITEURL' => 'https://notes.local',
    'BASE_PATH' => '/',
    'WS_HOST' => 'bad/host',
    'WS_PORT' => '27800',
]);
$hostRejected = false;
try {
    WebSocketEndpoint::bindHost();
} catch (InvalidArgumentException) {
    $hostRejected = true;
}
assertWebSocketEndpoint($hostRejected, 'invalid WS_HOST was accepted');

setWebSocketEnv([
    'SITEURL' => 'https://notes.local',
    'BASE_PATH' => '/',
    'WS_HOST' => '127.0.0.1',
    'WS_PORT' => '70000',
]);
$portRejected = false;
try {
    WebSocketEndpoint::port();
} catch (InvalidArgumentException) {
    $portRejected = true;
}
assertWebSocketEndpoint($portRejected, 'out-of-range WS_PORT was accepted');

$serverSource = file_get_contents($root . '/ws_server/server.php');
assertWebSocketEndpoint(is_string($serverSource), 'cannot read WebSocket launcher source');
assertWebSocketEndpoint(
    str_contains($serverSource, 'PHP_VERSION_ID < 80100'),
    'WebSocket launcher must reject unsupported CLI PHP before application bootstrap'
);
$runtimeGuardPosition = strpos($serverSource, 'PHP_VERSION_ID < 80100');
$environmentBootstrapPosition = strpos($serverSource, "require_once SITEPATH . '/core/Environment.php'");
assertWebSocketEndpoint(
    is_int($runtimeGuardPosition)
    && is_int($environmentBootstrapPosition)
    && $runtimeGuardPosition < $environmentBootstrapPosition,
    'CLI PHP version guard must run before loading PHP 8.1 application source'
);
assertWebSocketEndpoint(
    !str_contains($serverSource, 'usleep(100_000)'),
    'launcher must stay parseable on legacy CLI long enough to print the PHP 8.1 requirement'
);

assertWebSocketEndpoint(
    str_contains($serverSource, 'workspaceWsPreflight($daemon)'),
    'start/check must run the WebSocket startup preflight'
);
assertWebSocketEndpoint(
    str_contains($serverSource, "'Проверка привязки listener'"),
    'startup preflight must explain listener bind availability'
);
assertWebSocketEndpoint(
    str_contains($serverSource, "'Среда PHP CLI'"),
    'startup preflight must report the actual CLI PHP runtime'
);
assertWebSocketEndpoint(
    str_contains($serverSource, "'WebSocket URL для браузера'"),
    'startup preflight must report the browser-facing WebSocket URL'
);
assertWebSocketEndpoint(
    str_contains($serverSource, "'Reverse proxy'"),
    'startup preflight must report same-origin reverse proxy configuration'
);
assertWebSocketEndpoint(
    str_contains($serverSource, "'значение скрыто'"),
    'startup preflight must confirm WS_TICKET_SECRET without disclosing it'
);
assertWebSocketEndpoint(
    str_contains($serverSource, "'check'"),
    'launcher must provide a diagnostics-only check command'
);
$preflightCallPosition = strpos($serverSource, 'if (!workspaceWsPreflight($daemon))');
$coreBootstrapPosition = strpos($serverSource, "require_once SITEPATH . '/core.php'");
assertWebSocketEndpoint(
    is_int($preflightCallPosition)
    && is_int($coreBootstrapPosition)
    && $preflightCallPosition < $coreBootstrapPosition,
    'startup preflight must run before full application bootstrap'
);

$nativeServerSource = file_get_contents($root . '/modules/messenger/socket/NativeMessengerServer.php');
assertWebSocketEndpoint(is_string($nativeServerSource), 'cannot read native WebSocket server source');
assertWebSocketEndpoint(
    str_contains($nativeServerSource, "'[RUNNING] WebSocket-сервер"),
    'native runtime must print a bind-confirmed RUNNING message after listener creation'
);

restore_error_handler();
fwrite(STDOUT, "WebSocket endpoint contract: OK\n");
