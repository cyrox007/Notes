<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/core/Environment.php';
if (is_file($root . '/.env')) {
    \Core\Environment::load($root . '/.env');
}
require_once $root . '/core/WebSocketEndpoint.php';

use Core\WebSocketEndpoint;

function wsDoctorLine(string $status, string $label, string $details = ''): void
{
    $suffix = $details !== '' ? ' — ' . $details : '';
    fwrite(STDOUT, sprintf("[%s] %s%s\n", $status, $label, $suffix));
}

function wsDoctorConnectHost(string $bindHost): string
{
    return match ($bindHost) {
        '0.0.0.0', '*' => '127.0.0.1',
        '::', '[::]' => '::1',
        default => trim($bindHost, '[]'),
    };
}

try {
    $siteUrl = WebSocketEndpoint::siteUrl();
    $publicUrl = WebSocketEndpoint::publicUrl();
    $bindHost = WebSocketEndpoint::bindHost();
    $port = WebSocketEndpoint::port();
    $proxyPath = WebSocketEndpoint::proxyPath();
    $backend = WebSocketEndpoint::proxyBackendUrl();
    $sameOriginProxy = WebSocketEndpoint::usesSameOriginProxy();
} catch (Throwable $e) {
    wsDoctorLine('FAIL', 'WebSocket configuration', $e->getMessage());
    exit(1);
}

wsDoctorLine('OK', 'SITEURL', $siteUrl);
wsDoctorLine('OK', 'Browser WebSocket URL', $publicUrl);
wsDoctorLine('OK', 'Native WebSocket listener', sprintf('tcp://%s:%d', $bindHost, $port));
wsDoctorLine('INFO', 'Deployment mode', $sameOriginProxy ? 'same-origin reverse proxy' : 'custom/external WebSocket endpoint');
if ($sameOriginProxy) {
    wsDoctorLine('INFO', 'Required proxy', $proxyPath . ' -> ' . $backend);
}

$connectHost = wsDoctorConnectHost($bindHost);
$errno = 0;
$errstr = '';
$socket = @stream_socket_client(
    sprintf('tcp://%s:%d', str_contains($connectHost, ':') ? '[' . $connectHost . ']' : $connectHost, $port),
    $errno,
    $errstr,
    1.5,
    STREAM_CLIENT_CONNECT
);
if (is_resource($socket)) {
    fclose($socket);
    wsDoctorLine('OK', 'Native WebSocket listener reachable', $connectHost . ':' . $port);
    $listenerOk = true;
} else {
    wsDoctorLine('FAIL', 'Native WebSocket listener unreachable', ($errstr !== '' ? $errstr : 'connection failed') . " ({$connectHost}:{$port})");
    $listenerOk = false;
}

if ($sameOriginProxy) {
    $host = (string) parse_url($siteUrl, PHP_URL_HOST);
    $apacheBackend = $backend . '/';
    $quotedPath = str_replace('"', '', $proxyPath);

    fwrite(STDOUT, "\nApache 2.4.47+ virtual-host extension:\n");
    fwrite(STDOUT, "--------------------------------------\n");
    fwrite(STDOUT, "ProxyPreserveHost On\n");
    fwrite(STDOUT, sprintf("ProxyPass \"%s\" \"%s\" upgrade=websocket\n", $quotedPath, $apacheBackend));
    fwrite(STDOUT, sprintf("ProxyPassReverse \"%s\" \"%s\"\n", $quotedPath, $apacheBackend));

    fwrite(STDOUT, "\nNginx server extension:\n");
    fwrite(STDOUT, "-----------------------\n");
    fwrite(STDOUT, "location {$quotedPath} {\n");
    fwrite(STDOUT, "    proxy_pass {$backend};\n");
    fwrite(STDOUT, "    proxy_http_version 1.1;\n");
    fwrite(STDOUT, "    proxy_set_header Upgrade \$http_upgrade;\n");
    fwrite(STDOUT, "    proxy_set_header Connection \"upgrade\";\n");
    fwrite(STDOUT, "    proxy_set_header Host \$http_host;\n");
    fwrite(STDOUT, "    proxy_set_header Origin \$http_origin;\n");
    fwrite(STDOUT, "    proxy_read_timeout 60s;\n");
    fwrite(STDOUT, "}\n");

    if (PHP_OS_FAMILY === 'Windows' && $host !== '') {
        $windowsRoot = str_replace('/', '\\', $root);
        fwrite(STDOUT, "\nOpen Server 6 project-local config paths:\n");
        fwrite(STDOUT, "-----------------------------------------\n");
        fwrite(STDOUT, $windowsRoot . '\\.osp\\Apache\\' . $host . ".conf\n");
        fwrite(STDOUT, $windowsRoot . '\\.osp\\Nginx\\' . $host . ".conf\n");
        fwrite(STDOUT, "After creating/editing the active web-server config, restart Open Server.\n");
    }

    fwrite(STDOUT, "\nImportant: an [OK] internal listener only proves that the native WebSocket process is alive.\n");
    fwrite(STDOUT, "The browser still needs the web server to proxy {$proxyPath} to {$backend}.\n");
}

exit($listenerOk ? 0 : 1);
