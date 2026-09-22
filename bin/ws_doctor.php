<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Эта команда доступна только из CLI.\n");
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
    wsDoctorLine('FAIL', 'Конфигурация WebSocket', $e->getMessage());
    exit(1);
}

$runtimeOk = true;
wsDoctorLine('INFO', 'PHP CLI', PHP_BINARY . ' — ' . PHP_VERSION . ' (' . PHP_SAPI . ')');
foreach (['mysqli', 'pdo_mysql', 'mbstring', 'json', 'fileinfo', 'sodium'] as $extension) {
    $loaded = extension_loaded($extension);
    wsDoctorLine($loaded ? 'OK' : 'FAIL', 'Расширение PHP ' . $extension, $loaded ? 'загружено' : 'отсутствует');
    $runtimeOk = $runtimeOk && $loaded;
}

$ticketSecret = (string) (getenv('WS_TICKET_SECRET') ?: '');
if (strlen($ticketSecret) < 32) {
    wsDoctorLine('FAIL', 'WS_TICKET_SECRET', 'должен содержать не менее 32 символов');
    $runtimeOk = false;
} else {
    wsDoctorLine('OK', 'WS_TICKET_SECRET', 'настроен');
}

$pidFile = wsDoctorPidFile($root);
wsDoctorLine('INFO', 'PID-файл', $pidFile);
$startupLog = (string) ini_get('error_log');
if ($startupLog === '' || str_contains($startupLog, 'php')) {
    $startupLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'workspace-organizer-ws-startup.log';
}
wsDoctorLine('INFO', 'Журнал запуска', $startupLog);

wsDoctorLine('OK', 'SITEURL', $siteUrl);
wsDoctorLine('OK', 'WebSocket URL для браузера', $publicUrl);
wsDoctorLine('OK', 'Внутренний WebSocket listener', sprintf('tcp://%s:%d', $bindHost, $port));
wsDoctorLine('INFO', 'Режим развёртывания', $sameOriginProxy ? 'reverse proxy в рамках того же origin' : 'прямой/пользовательский WebSocket endpoint');
$siteHostForMode = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
$publicHostForMode = strtolower((string) parse_url($publicUrl, PHP_URL_HOST));
$publicPortForMode = (int) (parse_url($publicUrl, PHP_URL_PORT) ?? 0);
$openServerSameHostDirect = PHP_OS_FAMILY === 'Windows'
    && strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME)) === 'http'
    && $siteHostForMode !== ''
    && $siteHostForMode === $publicHostForMode
    && $publicPortForMode === $port;
if ($openServerSameHostDirect) {
    wsDoctorLine(
        'OK',
        'Прямой режим OpenServer',
        'браузер подключается к ' . $publicUrl . '; hostname разрешается локально, поэтому WebSocket proxy Apache/Nginx не требуется'
    );
}
if (!$sameOriginProxy && PHP_OS_FAMILY === 'Windows') {
    $siteScheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));
    $siteHost = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
    $publicScheme = strtolower((string) parse_url($publicUrl, PHP_URL_SCHEME));
    $publicHost = strtolower((string) parse_url($publicUrl, PHP_URL_HOST));
    if ($publicScheme === 'ws' && in_array($publicHost, ['127.0.0.1', 'localhost', '::1'], true)) {
        if ($siteScheme === 'http' && in_array($siteHost, ['127.0.0.1', 'localhost', '::1'], true)) {
            wsDoctorLine('OK', 'Loopback-режим браузера', 'страница и WebSocket endpoint используют доверенный loopback-адрес');
        } else {
            wsDoctorLine(
                'WARN',
                'Прямой loopback-режим браузера',
                'ограничения Chromium Local Network Access могут блокировать WebSocket-подключения с пользовательских/небезопасных origin к loopback; предпочтительно использовать same-origin proxy /ws'
            );
        }
    }
}
if ($sameOriginProxy) {
    wsDoctorLine('INFO', 'Требуемый proxy', $proxyPath . ' -> ' . $backend);
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
    wsDoctorLine('OK', 'Внутренний WebSocket listener доступен', $connectHost . ':' . $port);
    $listenerOk = true;
} else {
    wsDoctorLine('FAIL', 'Внутренний WebSocket listener недоступен', ($errstr !== '' ? $errstr : 'не удалось подключиться') . " ({$connectHost}:{$port})");
    $listenerOk = false;
    $startCommand = '"' . PHP_BINARY . '" ws_server/server.php start';
    wsDoctorLine('INFO', 'Команда запуска', $startCommand);
    if (PHP_OS_FAMILY === 'Windows') {
        wsDoctorLine('INFO', 'Поведение Windows', 'start работает в foreground; исправный сервер оставляет этот терминал/процесс активным');
        wsDoctorLine('INFO', 'Фоновый запуск PowerShell', 'Start-Process -FilePath "' . PHP_BINARY . '" -ArgumentList "ws_server/server.php","start" -WorkingDirectory "' . $root . '"');
    }
}

$legacyOpenServerLayout = PHP_OS_FAMILY === 'Windows'
    && preg_match('#(?:^|[\\\\/])domains[\\\\/]#i', $root) === 1;

if ($legacyOpenServerLayout) {
    wsDoctorLine(
        'WARN',
        'Обнаружена устаревшая структура OpenServer',
        'путь проекта использует domains\\...; локальные proxy-конфиги .osp из Open Server 6 здесь не применяются'
    );
    $siteScheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));
    if ($siteScheme === 'http' && !$openServerSameHostDirect) {
        $siteHost = (string) parse_url($siteUrl, PHP_URL_HOST);
        wsDoctorLine(
            'INFO',
            'Рекомендуемый локальный режим OSPanel 5.x',
            'задайте WS_PUBLIC_URL=ws://' . $siteHost . ':' . $port . ' и WS_ALLOWED_ORIGINS=' . $siteUrl . '; это позволяет обойти настройку Apache proxy и обращение к loopback через другой host'
        );
    } elseif ($siteScheme === 'https' && $sameOriginProxy) {
        wsDoctorLine(
            'INFO',
            'HTTPS-режим OpenServer',
            'оставьте ' . $proxyPath . ' и настройте WebSocket proxy Apache/Nginx на ' . $backend
        );
    }
}

if ($sameOriginProxy) {
    $host = (string) parse_url($siteUrl, PHP_URL_HOST);
    $apacheBackend = $backend . '/';
    $quotedPath = str_replace('"', '', $proxyPath);

    fwrite(STDOUT, "\nКонфигурация VirtualHost для Apache 2.4.47+:\n");
    fwrite(STDOUT, "--------------------------------------\n");
    fwrite(STDOUT, "ProxyPreserveHost On\n");
    fwrite(STDOUT, sprintf("ProxyPass \"%s\" \"%s\" upgrade=websocket\n", $quotedPath, $apacheBackend));
    fwrite(STDOUT, sprintf("ProxyPassReverse \"%s\" \"%s\"\n", $quotedPath, $apacheBackend));

    fwrite(STDOUT, "\nКонфигурация server для Nginx:\n");
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

    if (PHP_OS_FAMILY === 'Windows' && $host !== '' && !$legacyOpenServerLayout) {
        $windowsRoot = str_replace('/', '\\', $root);
        fwrite(STDOUT, "\nЛокальные пути конфигурации проекта Open Server 6:\n");
        fwrite(STDOUT, "-----------------------------------------\n");
        fwrite(STDOUT, $windowsRoot . '\\.osp\\Apache\\' . $host . ".conf\n");
        fwrite(STDOUT, $windowsRoot . '\\.osp\\Nginx\\' . $host . ".conf\n");
        fwrite(STDOUT, "После создания или изменения активной конфигурации веб-сервера перезапустите Open Server.\n");
    } elseif ($legacyOpenServerLayout) {
        fwrite(STDOUT, "\nОбнаружен legacy OpenServer/OSPanel 5.x: локальные пути конфигурации .osp здесь намеренно не показываются.\n");
    }

    fwrite(STDOUT, "\nВажно: [OK] у внутреннего listener означает только то, что native WebSocket-процесс запущен.\n");
    fwrite(STDOUT, "Для подключения браузера веб-сервер всё равно должен проксировать {$proxyPath} на {$backend}.\n");
}

exit(($listenerOk && $runtimeOk) ? 0 : 1);
