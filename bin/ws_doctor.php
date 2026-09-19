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

function wsDoctorPidFile(string $root): string
{
    $configured = trim((string) (getenv('WS_PID_FILE') ?: ''));
    if ($configured !== '') {
        return $configured;
    }

    $privateStorage = trim((string) (getenv('PRIVATE_STORAGE_PATH') ?: ''));
    if ($privateStorage !== '') {
        return rtrim($privateStorage, '/\\') . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ws-server.pid';
    }

    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'workspace-organizer-ws.pid';
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

$runtimeOk = true;
wsDoctorLine('INFO', 'PHP CLI', PHP_BINARY . ' — ' . PHP_VERSION . ' (' . PHP_SAPI . ')');
foreach (['mysqli', 'pdo_mysql', 'mbstring', 'json', 'fileinfo', 'sodium'] as $extension) {
    $loaded = extension_loaded($extension);
    wsDoctorLine($loaded ? 'OK' : 'FAIL', 'PHP extension ' . $extension, $loaded ? 'loaded' : 'missing');
    $runtimeOk = $runtimeOk && $loaded;
}

$ticketSecret = (string) (getenv('WS_TICKET_SECRET') ?: '');
if (strlen($ticketSecret) < 32) {
    wsDoctorLine('FAIL', 'WS_TICKET_SECRET', 'must contain at least 32 characters');
    $runtimeOk = false;
} else {
    wsDoctorLine('OK', 'WS_TICKET_SECRET', 'configured');
}

$pidFile = wsDoctorPidFile($root);
wsDoctorLine('INFO', 'PID file', $pidFile);
$startupLog = (string) ini_get('error_log');
if ($startupLog === '' || str_contains($startupLog, 'php')) {
    $startupLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'workspace-organizer-ws-startup.log';
}
wsDoctorLine('INFO', 'Startup log', $startupLog);

wsDoctorLine('OK', 'SITEURL', $siteUrl);
wsDoctorLine('OK', 'Browser WebSocket URL', $publicUrl);
wsDoctorLine('OK', 'Native WebSocket listener', sprintf('tcp://%s:%d', $bindHost, $port));
wsDoctorLine('INFO', 'Deployment mode', $sameOriginProxy ? 'same-origin reverse proxy' : 'direct/custom WebSocket endpoint');
if (!$sameOriginProxy && PHP_OS_FAMILY === 'Windows') {
    $siteScheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));
    $publicScheme = strtolower((string) parse_url($publicUrl, PHP_URL_SCHEME));
    $publicHost = strtolower((string) parse_url($publicUrl, PHP_URL_HOST));
    if ($siteScheme === 'http' && $publicScheme === 'ws' && in_array($publicHost, ['127.0.0.1', 'localhost', '::1'], true)) {
        wsDoctorLine('OK', 'OpenServer local HTTP mode', 'browser connects directly to the loopback native listener; Apache WebSocket proxy is not required');
    }
}
if ($sameOriginProxy) {
    wsDoctorLine('INFO', 'Required proxy', $proxyPath . ' -> ' . $backend);

    $siteScheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));
    $siteHost = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
    $looksLikeOpenServerLocal = PHP_OS_FAMILY === 'Windows'
        && $siteScheme === 'http'
        && (
            in_array($siteHost, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($siteHost, '.local')
        );

    if ($looksLikeOpenServerLocal) {
        wsDoctorLine(
            'WARN',
            'OpenServer/OSPanel local HTTP',
            'same-origin /ws needs a configured proxy; for same-machine OSPanel 5.x use WS_PUBLIC_URL=ws://127.0.0.1:' . $port
        );
        wsDoctorLine(
            'INFO',
            'After changing WS_PUBLIC_URL',
            'restart the HTTP environment and the native WebSocket process, then reload Messenger'
        );
    }
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
    $startCommand = '"' . PHP_BINARY . '" ws_server/server.php start';
    wsDoctorLine('INFO', 'Start command', $startCommand);
    if (PHP_OS_FAMILY === 'Windows') {
        wsDoctorLine('INFO', 'Windows behavior', 'start runs in the foreground; a healthy server keeps that terminal/process alive');
        wsDoctorLine('INFO', 'PowerShell background launch', 'Start-Process -FilePath "' . PHP_BINARY . '" -ArgumentList "ws_server/server.php","start" -WorkingDirectory "' . $root . '"');
    }
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

exit(($listenerOk && $runtimeOk) ? 0 : 1);
