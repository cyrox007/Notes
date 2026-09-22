<?php

declare(strict_types=1);

use App\Sockets\NativeMessengerServer;
use Core\WebSocketEndpoint;

// Keep this guard before loading any application source. The supported runtime
// uses PHP 8.1 language features, but this launcher intentionally remains
// parseable on common legacy CLI versions so a hosting shell with the wrong PHP
// binary reports a useful diagnostic instead of an unrelated syntax error.
if (PHP_VERSION_ID < 80100) {
    fwrite(
        STDERR,
        'Для WebSocket-сервера Workspace Organizer требуется PHP CLI 8.1+; сейчас используется '
        . PHP_VERSION
        . ' через '
        . PHP_BINARY
        . PHP_EOL
    );
    fwrite(
        STDERR,
        'Проверьте выбранную версию PHP для SSH/CLI командой: php -v && command -v php'
        . PHP_EOL
    );
    exit(2);
}

ini_set('display_errors', '0');
if (!defined('SITEPATH')) {
    define('SITEPATH', dirname(__FILE__) . '/..');
}
error_reporting(E_ALL);
ini_set('error_log', sys_get_temp_dir() . '/workspace-organizer-ws-startup.log');


function workspaceWsStartupLine(string $status, string $label, string $details = ''): void
{
    $suffix = $details !== '' ? ' — ' . $details : '';
    fwrite(STDOUT, sprintf("[%s] %s%s\n", $status, $label, $suffix));
}

function workspaceWsStartupFail(string $label, string $reason, ?string $action = null): void
{
    workspaceWsStartupLine('FAIL', $label, $reason);
    if ($action !== null && $action !== '') {
        workspaceWsStartupLine('FIX', $label, $action);
    }
}

function workspaceWsFormatBindHost(string $host): string
{
    $host = trim($host, '[]');
    return str_contains($host, ':') ? '[' . $host . ']' : $host;
}

/**
 * Validate everything that can be checked safely before the long-running
 * WebSocket-процесс starts. Returns false instead of starting on any critical
 * problem and never prints secret values.
 */
function workspaceWsPreflight(bool $daemon): bool
{
    $failures = 0;

    fwrite(STDOUT, PHP_EOL . "Предварительная проверка запуска WebSocket Workspace Organizer\n");
    fwrite(STDOUT, "================================================\n");

    workspaceWsStartupLine('OK', 'Корень приложения', SITEPATH);

    $envFile = SITEPATH . '/.env';
    if (!is_file($envFile)) {
        workspaceWsStartupFail(
            'Файл окружения',
            'не найден: ' . $envFile,
            'создайте и настройте .env перед запуском WebSocket'
        );
        $failures++;
    } else {
        workspaceWsStartupLine('OK', 'Файл окружения', $envFile . ' (загружен)');
    }

    workspaceWsStartupLine(
        'OK',
        'Среда PHP CLI',
        PHP_VERSION . ' | binary=' . PHP_BINARY . ' | sapi=' . PHP_SAPI
    );

    foreach (['mysqli', 'pdo_mysql', 'mbstring', 'json', 'fileinfo', 'sodium'] as $extension) {
        if (extension_loaded($extension)) {
            $version = phpversion($extension);
            workspaceWsStartupLine(
                'OK',
                'Расширение PHP ' . $extension,
                $version !== false ? 'загружено, версия=' . $version : 'загружено'
            );
        } else {
            workspaceWsStartupFail(
                'Расширение PHP ' . $extension,
                'отсутствует в активном CLI-интерпретаторе PHP',
                'включите/установите ' . $extension . ' для ' . PHP_BINARY
            );
            $failures++;
        }
    }

    foreach (['stream_socket_server', 'stream_select'] as $function) {
        if (function_exists($function)) {
            workspaceWsStartupLine('OK', 'Функция Socket API ' . $function, 'доступна');
        } else {
            workspaceWsStartupFail(
                'Функция Socket API ' . $function,
                'функция недоступна в этой среде PHP CLI',
                'используйте сборку PHP CLI со стандартной поддержкой stream socket'
            );
            $failures++;
        }
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        if (function_exists('pcntl_signal')) {
            workspaceWsStartupLine('OK', 'Обработка сигналов', 'pcntl доступен');
        } else {
            workspaceWsStartupLine(
                'WARN',
                'Обработка сигналов',
                'pcntl недоступен; запуск в foreground возможен, но корректная обработка сигналов ограничена'
            );
        }

        if (function_exists('posix_kill')) {
            workspaceWsStartupLine('OK', 'Управление процессом', 'posix_kill доступен');
        } else {
            workspaceWsStartupLine(
                'WARN',
                'Управление процессом',
                'posix_kill недоступен; для stop/restart может потребоваться менеджер процессов хостинга'
            );
        }
    }

    if ($daemon) {
        if (PHP_OS_FAMILY === 'Windows') {
            workspaceWsStartupFail(
                'Режим демона',
                'параметр -d/--daemon запрошен в Windows',
                'запускайте в foreground через менеджер фоновых процессов'
            );
            $failures++;
        } elseif (!function_exists('pcntl_fork')) {
            workspaceWsStartupFail(
                'Режим демона',
                'pcntl_fork недоступен',
                'включите pcntl или запускайте без -d через systemd/Supervisor/менеджер процессов хостинга'
            );
            $failures++;
        } else {
            workspaceWsStartupLine('OK', 'Режим процесса', 'запрошен daemon-режим; pcntl_fork доступен');
        }
    } else {
        workspaceWsStartupLine(
            'OK',
            'Режим процесса',
            PHP_OS_FAMILY === 'Windows'
                ? 'foreground (используйте менеджер фоновых процессов хостинга/OpenServer)'
                : 'foreground (рекомендуется для systemd/Supervisor/менеджера процессов хостинга)'
        );
    }

    $ticketSecret = (string) (getenv('WS_TICKET_SECRET') ?: '');
    if (strlen($ticketSecret) < 32) {
        workspaceWsStartupFail(
            'WS_TICKET_SECRET',
            'не настроен или короче 32 символов',
            'задайте в .env отдельный случайный WS_TICKET_SECRET длиной не менее 32 символов'
        );
        $failures++;
    } else {
        workspaceWsStartupLine('OK', 'WS_TICKET_SECRET', 'настроен (' . strlen($ticketSecret) . ' символов; значение скрыто)');
    }

    $pidFile = workspaceWsPidFile();
    $pidDirectory = dirname($pidFile);
    if (!is_dir($pidDirectory) && !@mkdir($pidDirectory, 0700, true) && !is_dir($pidDirectory)) {
        workspaceWsStartupFail(
            'Каталог runtime',
            'не удалось создать ' . $pidDirectory,
            'создайте каталог и предоставьте пользователю CLI права на запись'
        );
        $failures++;
    } elseif (!is_writable($pidDirectory)) {
        workspaceWsStartupFail(
            'Каталог runtime',
            'нет прав на запись: ' . $pidDirectory,
            'предоставьте пользователю CLI права на запись или измените WS_PID_FILE/PRIVATE_STORAGE_PATH'
        );
        $failures++;
    } else {
        workspaceWsStartupLine('OK', 'Каталог runtime', $pidDirectory . ' (доступен для записи)');
    }
    workspaceWsStartupLine('INFO', 'PID-файл', $pidFile);

    $logPath = (string) ini_get('error_log');
    workspaceWsStartupLine(
        'INFO',
        'Журнал runtime',
        $logPath !== '' ? $logPath : 'PHP error_log не настроен'
    );

    $privateStorage = trim((string) (getenv('PRIVATE_STORAGE_PATH') ?: ''));
    if ($privateStorage !== '') {
        workspaceWsStartupLine(
            is_dir($privateStorage) && is_writable($privateStorage) ? 'OK' : 'WARN',
            'Приватное хранилище',
            $privateStorage
                . (is_dir($privateStorage)
                    ? (is_writable($privateStorage) ? ' (доступен для записи)' : ' (нет прав на запись)')
                    : ' (каталог пока не существует)')
        );
    }

    $bindHost = null;
    $port = null;
    try {
        require_once SITEPATH . '/core/WebSocketEndpoint.php';

        $siteUrl = WebSocketEndpoint::siteUrl();
        $publicUrl = WebSocketEndpoint::publicUrl();
        $bindHost = WebSocketEndpoint::bindHost();
        $port = WebSocketEndpoint::port();
        $origins = WebSocketEndpoint::allowedOrigins();
        $sameOriginProxy = WebSocketEndpoint::usesSameOriginProxy();

        workspaceWsStartupLine('OK', 'Origin сайта', $siteUrl);
        workspaceWsStartupLine('OK', 'WebSocket URL для браузера', $publicUrl);
        workspaceWsStartupLine('OK', 'Внутренний listener', 'tcp://' . workspaceWsFormatBindHost($bindHost) . ':' . $port);
        workspaceWsStartupLine(
            'OK',
            'Режим развёртывания',
            $sameOriginProxy ? 'reverse proxy в рамках того же origin' : 'прямой/пользовательский WebSocket endpoint'
        );

        if ($sameOriginProxy) {
            workspaceWsStartupLine(
                'INFO',
                'Reverse proxy',
                WebSocketEndpoint::proxyPath() . ' -> ' . WebSocketEndpoint::proxyBackendUrl()
            );
        }

        if ($origins === []) {
            workspaceWsStartupFail(
                'Разрешённые WebSocket origin',
                'список разрешённых origin пуст',
                'задайте WS_ALLOWED_ORIGINS равным origin, с которого браузер открывает Workspace Organizer'
            );
            $failures++;
        } else {
            workspaceWsStartupLine('OK', 'Разрешённые WebSocket origin', implode(', ', $origins));
        }
    } catch (Throwable $e) {
        workspaceWsStartupFail(
            'Конфигурация WebSocket',
            $e->getMessage(),
            'проверьте SITEURL, BASE_PATH, WS_HOST, WS_PORT, WS_PUBLIC_URL и WS_ALLOWED_ORIGINS в .env'
        );
        $failures++;
    }

    $maxConnections = (int) (getenv('WS_MAX_CONNECTIONS') ?: 256);
    if ($maxConnections < 1 || $maxConnections > 10000) {
        workspaceWsStartupFail(
            'Лимит соединений',
            'WS_MAX_CONNECTIONS=' . $maxConnections . ' вне диапазона 1..10000',
            'задайте WS_MAX_CONNECTIONS в диапазоне от 1 до 10000'
        );
        $failures++;
    } else {
        workspaceWsStartupLine('OK', 'Лимит соединений', (string) $maxConnections);
    }

    $maxPayloadBytes = (int) (getenv('WS_MAX_PAYLOAD_BYTES') ?: 2097152);
    if ($maxPayloadBytes < 1024 || $maxPayloadBytes > 16777216) {
        workspaceWsStartupFail(
            'Лимит payload',
            'WS_MAX_PAYLOAD_BYTES=' . $maxPayloadBytes . ' вне диапазона 1024..16777216',
            'задайте WS_MAX_PAYLOAD_BYTES от 1024 байт до 16 MiB'
        );
        $failures++;
    } else {
        workspaceWsStartupLine(
            'OK',
            'Лимит payload',
            $maxPayloadBytes . ' байт (' . number_format($maxPayloadBytes / 1048576, 2, '.', '') . ' MiB)'
        );
    }

    $existingPid = workspaceWsReadPid($pidFile);
    if ($existingPid !== null && workspaceWsProcessExists($existingPid)) {
        workspaceWsStartupFail(
            'Существующий WebSocket-процесс',
            'PID ' . $existingPid . ' уже запущен',
            'используйте status/restart вместо запуска второго listener'
        );
        $failures++;
    } elseif ($existingPid !== null) {
        workspaceWsStartupLine('WARN', 'Устаревший PID-файл', 'PID ' . $existingPid . ' не запущен; устаревший PID-файл будет заменён');
    } else {
        workspaceWsStartupLine('OK', 'Существующий WebSocket-процесс', 'не обнаружен');
    }

    if ($bindHost !== null && $port !== null && function_exists('stream_socket_server')) {
        $errno = 0;
        $errstr = '';
        $probe = @stream_socket_server(
            'tcp://' . workspaceWsFormatBindHost($bindHost) . ':' . $port,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if (is_resource($probe)) {
            fclose($probe);
            workspaceWsStartupLine(
                'OK',
                'Проверка привязки listener',
                'tcp://' . workspaceWsFormatBindHost($bindHost) . ':' . $port . ' доступен'
            );
        } else {
            workspaceWsStartupFail(
                'Проверка привязки listener',
                ($errstr !== '' ? $errstr : 'не удалось привязать listener') . ' (ошибка ' . $errno . ')',
                'проверьте, не занят ли порт, не заблокирован ли он и доступен ли он для этой учётной записи хостинга'
            );
            $failures++;
        }
    }

    if ($failures > 0) {
        fwrite(STDOUT, "------------------------------------------------\n");
        workspaceWsStartupLine(
            'FAIL',
            'Предварительная проверка запуска',
            'обнаружено критических проблем: ' . $failures . '; WebSocket-сервер не запущен'
        );
        workspaceWsStartupLine('INFO', 'Расширенная диагностика', '"' . PHP_BINARY . '" bin/ws_doctor.php');
        fwrite(STDOUT, PHP_EOL);
        return false;
    }

    fwrite(STDOUT, "------------------------------------------------\n");
    workspaceWsStartupLine('OK', 'Предварительная проверка запуска', 'все критические проверки пройдены; запускается WebSocket runtime');
    fwrite(STDOUT, PHP_EOL);
    return true;
}

// Process lifecycle commands must remain usable even when the application DB is
// unavailable. Load only the internal environment parser first so status/stop can
// locate the installation-specific PID-файл without booting the full application.
require_once SITEPATH . '/core/Environment.php';
if (is_file(SITEPATH . '/.env')) {
    \Core\Environment::load(SITEPATH . '/.env');
}

$configuredLog = trim((string) (getenv('LOG_FILE') ?: ''));
if ($configuredLog !== '') {
    $logDirectory = dirname($configuredLog);
    if ((is_dir($logDirectory) || @mkdir($logDirectory, 0700, true)) && is_writable($logDirectory)) {
        ini_set('error_log', $configuredLog);
    }
}

function workspaceWsPidFile(): string
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

function workspaceWsReadPid(string $pidFile): ?int
{
    if (!is_file($pidFile)) {
        return null;
    }
    $value = trim((string) @file_get_contents($pidFile));
    if ($value === '' || !ctype_digit($value)) {
        return null;
    }
    $pid = (int) $value;
    return $pid > 0 ? $pid : null;
}

function workspaceWsProcessExists(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    if (PHP_OS_FAMILY === 'Linux' && is_dir('/proc/' . $pid)) {
        return true;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        if (function_exists('exec')) {
            $output = [];
            $status = 1;
            @exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH', $output, $status);
            return $status === 0 && isset($output[0]) && str_contains($output[0], (string) $pid);
        }

        // Some Windows/OpenServer CLI profiles disable exec(). In that case a
        // stale PID-файл must not permanently block startup. The listener bind
        // remains the authoritative duplicate-process guard.
        return false;
    }

    // Unknown process API on other platforms: be conservative.
    return true;
}

function workspaceWsWritePid(string $pidFile): void
{
    $directory = dirname($pidFile);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать runtime-каталог WebSocket: ' . $directory);
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('Нет прав на запись в runtime-каталог WebSocket: ' . $directory);
    }
    if (@file_put_contents($pidFile, (string) getmypid(), LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать PID-файл WebSocket: ' . $pidFile);
    }
    @chmod($pidFile, 0600);
}

function workspaceWsStop(string $pidFile): int
{
    $pid = workspaceWsReadPid($pidFile);
    if ($pid === null || !workspaceWsProcessExists($pid)) {
        @unlink($pidFile);
        fwrite(STDOUT, "WebSocket-сервер не запущен.\n");
        return 0;
    }

    $sent = false;
    if (function_exists('posix_kill') && defined('SIGTERM')) {
        $sent = @posix_kill($pid, SIGTERM);
    } elseif (PHP_OS_FAMILY === 'Windows' && function_exists('exec')) {
        $output = [];
        $status = 1;
        @exec('taskkill /PID ' . $pid . ' /T /F', $output, $status);
        $sent = $status === 0;
    }

    if (!$sent) {
        fwrite(STDERR, "Не удалось отправить сигнал WebSocket-процессу {$pid}; остановите его через менеджер процессов ОС.\n");
        return 1;
    }

    $deadline = microtime(true) + 8.0;
    while (microtime(true) < $deadline && workspaceWsProcessExists($pid)) {
        usleep(100000);
    }

    if (workspaceWsProcessExists($pid)) {
        fwrite(STDERR, "WebSocket-процесс {$pid} не остановился за отведённое время.\n");
        return 1;
    }

    @unlink($pidFile);
    fwrite(STDOUT, "WebSocket-сервер остановлен.\n");
    return 0;
}

function workspaceWsDaemonize(): void
{
    if (PHP_OS_FAMILY === 'Windows' || !function_exists('pcntl_fork')) {
        fwrite(STDERR, "Daemon-режим требует pcntl в Unix. В Windows используйте foreground через Open Server/менеджер фоновых процессов.\n");
        exit(2);
    }

    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Не удалось создать дочерний процесс WebSocket-демона');
    }
    if ($pid > 0) {
        fwrite(STDOUT, "WebSocket-демон запускается с PID {$pid}.\n");
        exit(0);
    }

    if (function_exists('posix_setsid')) {
        @posix_setsid();
    }
}

$command = strtolower((string) ($argv[1] ?? 'start'));
$daemon = in_array('-d', $argv, true) || in_array('--daemon', $argv, true);
$pidFile = workspaceWsPidFile();

if ($command === 'status') {
    $pid = workspaceWsReadPid($pidFile);
    if ($pid !== null && workspaceWsProcessExists($pid)) {
        fwrite(STDOUT, "WebSocket-сервер запущен (PID {$pid}).\n");
        exit(0);
    }
    @unlink($pidFile);
    fwrite(STDOUT, "WebSocket-сервер не запущен.\n");
    exit(1);
}

if ($command === 'stop') {
    exit(workspaceWsStop($pidFile));
}

if ($command === 'restart') {
    $stopStatus = workspaceWsStop($pidFile);
    if ($stopStatus !== 0) {
        exit($stopStatus);
    }
    $command = 'start';
}

if (!in_array($command, ['start', 'run', 'check'], true)) {
    fwrite(STDERR, "Использование: php ws_server/server.php start [-d] | check [-d] | status | stop | restart\n");
    exit(2);
}

if (!workspaceWsPreflight($daemon)) {
    exit(1);
}

if ($command === 'check') {
    exit(0);
}

// From this point onward a real server process is being started. Keep every
// startup failure inside one diagnostic boundary: Windows/OpenServer users
// should never get a silent exit just because display_errors is disabled.
try {
    // Load the complete application stack including persisted module lifecycle state.
    require_once SITEPATH . '/core.php';
    workspaceWsStartupLine(
        'OK',
        'Инициализация приложения',
        'core runtime загружен; база данных и сохранённое состояние модулей инициализированы'
    );

    // A disabled Модуль Messenger must not have a parallel always-on WebSocket
    // runtime. status/stop remain DB-independent above, while start/run fail
    // closed unless the isolated Messenger provider is in the effective composition.
    $moduleRuntime = \Core\ModuleRuntimeLoader::getInstance();
    if (!isset($moduleRuntime->providers()['messenger'])) {
        throw new RuntimeException('Модуль Messenger отключён в текущей конфигурации модулей.');
    }
    workspaceWsStartupLine('OK', 'Модуль Messenger', 'включён в текущей runtime-конфигурации');

    $existingPid = workspaceWsReadPid($pidFile);
    if ($existingPid !== null && workspaceWsProcessExists($existingPid)) {
        throw new RuntimeException("WebSocket-сервер уже запущен (PID {$existingPid}).");
    }
    @unlink($pidFile);

    if ($daemon) {
        workspaceWsDaemonize();
    }

    workspaceWsWritePid($pidFile);
    $currentPid = getmypid();
    workspaceWsStartupLine('OK', 'Процесс', 'PID ' . $currentPid . ' | pid_file=' . $pidFile);
    register_shutdown_function(static function () use ($pidFile, $currentPid): void {
        if (workspaceWsReadPid($pidFile) === $currentPid) {
            @unlink($pidFile);
        }
    });

    $host = WebSocketEndpoint::bindHost();
    $port = WebSocketEndpoint::port();
    $publicUrl = WebSocketEndpoint::publicUrl();
    $allowedOrigins = WebSocketEndpoint::allowedOrigins();
    $maxConnections = (int) (getenv('WS_MAX_CONNECTIONS') ?: 256);
    $maxPayloadBytes = (int) (getenv('WS_MAX_PAYLOAD_BYTES') ?: \App\Sockets\SocketFrameCodec::DEFAULT_MAX_PAYLOAD_BYTES);

    error_log(sprintf(
        'WebSocket listener настроен: tcp://%s:%d; public=%s; runtime=native',
        $host,
        $port,
        $publicUrl
    ));
    if (WebSocketEndpoint::usesSameOriginProxy()) {
        error_log(sprintf(
            'Требуется WebSocket reverse proxy: %s -> %s',
            WebSocketEndpoint::proxyPath(),
            WebSocketEndpoint::proxyBackendUrl()
        ));
    }

    workspaceWsStartupLine(
        'INFO',
        'Запуск внутреннего listener',
        'tcp://' . workspaceWsFormatBindHost($host) . ':' . $port
            . ' | browser=' . $publicUrl
            . ' | max_connections=' . $maxConnections
            . ' | max_payload=' . $maxPayloadBytes . ' байт'
    );

    (new NativeMessengerServer(
        $host,
        $port,
        $allowedOrigins,
        $maxConnections,
        $maxPayloadBytes
    ))->run();

    workspaceWsStartupLine('INFO', 'WebSocket-сервер', 'listener штатно остановлен');
} catch (Throwable $e) {
    $logPath = (string) ini_get('error_log');
    error_log('Ошибка запуска/работы native WebSocket-сервера: ' . $e->getMessage());
    workspaceWsStartupFail(
        'WebSocket-сервер',
        $e->getMessage(),
        'проверьте отчёт запуска выше и выполните "' . PHP_BINARY . '" bin/ws_doctor.php'
    );
    if ($logPath !== '') {
        fwrite(STDERR, "Журнал запуска/runtime: {$logPath}" . PHP_EOL);
    }
    fwrite(STDERR, "Запустите: php bin/ws_doctor.php" . PHP_EOL);
    exit(1);
}
