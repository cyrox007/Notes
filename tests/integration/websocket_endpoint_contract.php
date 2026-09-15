<?php

declare(strict_types=1);

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
    foreach (['SITEURL', 'BASE_PATH', 'WS_PUBLIC_URL', 'WS_HOST', 'WS_PORT'] as $key) {
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
assertWebSocketEndpoint(WebSocketEndpoint::proxyPath() === '/ws', 'root proxy path drifted');
assertWebSocketEndpoint(WebSocketEndpoint::proxyBackendUrl() === 'http://127.0.0.1:27800', 'loopback backend drifted');
assertWebSocketEndpoint(WebSocketEndpoint::usesSameOriginProxy(), 'root same-origin proxy was not detected');

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
assertWebSocketEndpoint(WebSocketEndpoint::proxyPath() === '/workspace/ws', 'subdirectory proxy path drifted');

setWebSocketEnv([
    'SITEURL' => 'http://notes.local',
    'BASE_PATH' => '/',
    'WS_HOST' => '127.0.0.1',
    'WS_PORT' => '27800',
]);
assertWebSocketEndpoint(WebSocketEndpoint::publicUrl() === 'ws://notes.local/ws', 'HTTP local URL derivation failed');

setWebSocketEnv([
    'SITEURL' => 'https://notes.local',
    'BASE_PATH' => '/',
    'WS_PUBLIC_URL' => 'wss://notes.local/ws',
    'WS_HOST' => '127.0.0.1',
    'WS_PORT' => '27800',
]);
assertWebSocketEndpoint(WebSocketEndpoint::publicUrl() === 'wss://notes.local/ws', 'explicit same-origin URL changed');
assertWebSocketEndpoint(WebSocketEndpoint::usesSameOriginProxy(), 'explicit same-origin proxy not detected');

setWebSocketEnv([
    'SITEURL' => 'https://notes.local',
    'BASE_PATH' => '/',
    'WS_PUBLIC_URL' => 'wss://socket.example.test/ws',
    'WS_HOST' => '127.0.0.1',
    'WS_PORT' => '27800',
]);
assertWebSocketEndpoint(!WebSocketEndpoint::usesSameOriginProxy(), 'external endpoint must not be classified as same-origin proxy');

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

fwrite(STDOUT, "WebSocket endpoint contract: OK\n");
