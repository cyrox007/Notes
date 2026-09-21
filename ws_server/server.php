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
        'Workspace Organizer WebSocket server requires PHP CLI 8.1+; running '
        . PHP_VERSION
        . ' via '
        . PHP_BINARY
        . PHP_EOL
    );
    fwrite(
        STDERR,
        'Check the SSH/CLI PHP selection with: php -v && command -v php'
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
 * WebSocket process starts. Returns false instead of starting on any critical
 * problem and never prints secret values.
 */
function workspaceWsPreflight(bool $daemon): bool
{
    $failures = 0;

    fwrite(STDOUT, PHP_EOL . "Workspace Organizer WebSocket startup preflight\n");
    fwrite(STDOUT, "================================================\n");

    workspaceWsStartupLine('OK', 'Application root', SITEPATH);

    $envFile = SITEPATH . '/.env';
    if (!is_file($envFile)) {
        workspaceWsStartupFail(
            'Environment file',
            'missing: ' . $envFile,
            'create/configure .env before starting the WebSocket runtime'
        );
        $failures++;
    } else {
        workspaceWsStartupLine('OK', 'Environment file', $envFile . ' (loaded)');
    }

    workspaceWsStartupLine(
        'OK',
        'PHP CLI runtime',
        PHP_VERSION . ' | binary=' . PHP_BINARY . ' | sapi=' . PHP_SAPI
    );

    foreach (['mysqli', 'pdo_mysql', 'mbstring', 'json', 'fileinfo', 'sodium'] as $extension) {
        if (extension_loaded($extension)) {
            $version = phpversion($extension);
            workspaceWsStartupLine(
                'OK',
                'PHP extension ' . $extension,
                $version !== false ? 'loaded, version=' . $version : 'loaded'
            );
        } else {
            workspaceWsStartupFail(
                'PHP extension ' . $extension,
                'missing from the active CLI PHP binary',
                'enable/install ' . $extension . ' for ' . PHP_BINARY
            );
            $failures++;
        }
    }

    foreach (['stream_socket_server', 'stream_select'] as $function) {
        if (function_exists($function)) {
            workspaceWsStartupLine('OK', 'Socket API ' . $function, 'available');
        } else {
            workspaceWsStartupFail(
                'Socket API ' . $function,
                'function is unavailable in this CLI runtime',
                'use a PHP CLI build with standard stream socket support'
            );
            $failures++;
        }
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        if (function_exists('pcntl_signal')) {
            workspaceWsStartupLine('OK', 'Signal handling', 'pcntl is available');
        } else {
            workspaceWsStartupLine(
                'WARN',
                'Signal handling',
                'pcntl is unavailable; foreground runtime can start, but graceful signal handling is limited'
            );
        }

        if (function_exists('posix_kill')) {
            workspaceWsStartupLine('OK', 'Process control', 'posix_kill is available');
        } else {
            workspaceWsStartupLine(
                'WARN',
                'Process control',
                'posix_kill is unavailable; stop/restart may require the hosting process manager'
            );
        }
    }

    if ($daemon) {
        if (PHP_OS_FAMILY === 'Windows') {
            workspaceWsStartupFail(
                'Daemon mode',
                'requested with -d/--daemon on Windows',
                'run in foreground under a background process manager instead'
            );
            $failures++;
        } elseif (!function_exists('pcntl_fork')) {
            workspaceWsStartupFail(
                'Daemon mode',
                'pcntl_fork is unavailable',
                'enable pcntl or start without -d under systemd/Supervisor/hosting process manager'
            );
            $failures++;
        } else {
            workspaceWsStartupLine('OK', 'Process mode', 'daemon requested; pcntl_fork is available');
        }
    } else {
        workspaceWsStartupLine(
            'OK',
            'Process mode',
            PHP_OS_FAMILY === 'Windows'
                ? 'foreground (use the hosting/OpenServer background process manager)'
                : 'foreground (recommended for systemd/Supervisor/hosting process managers)'
        );
    }

    $ticketSecret = (string) (getenv('WS_TICKET_SECRET') ?: '');
    if (strlen($ticketSecret) < 32) {
        workspaceWsStartupFail(
            'WS_TICKET_SECRET',
            'not configured or shorter than 32 characters',
            'set a separate random WS_TICKET_SECRET with at least 32 characters in .env'
        );
        $failures++;
    } else {
        workspaceWsStartupLine('OK', 'WS_TICKET_SECRET', 'configured (' . strlen($ticketSecret) . ' characters; value hidden)');
    }

    $pidFile = workspaceWsPidFile();
    $pidDirectory = dirname($pidFile);
    if (!is_dir($pidDirectory) && !@mkdir($pidDirectory, 0700, true) && !is_dir($pidDirectory)) {
        workspaceWsStartupFail(
            'Runtime directory',
            'cannot create ' . $pidDirectory,
            'create the directory and grant the CLI user write access'
        );
        $failures++;
    } elseif (!is_writable($pidDirectory)) {
        workspaceWsStartupFail(
            'Runtime directory',
            'not writable: ' . $pidDirectory,
            'grant the CLI user write permission or change WS_PID_FILE/PRIVATE_STORAGE_PATH'
        );
        $failures++;
    } else {
        workspaceWsStartupLine('OK', 'Runtime directory', $pidDirectory . ' (writable)');
    }
    workspaceWsStartupLine('INFO', 'PID file', $pidFile);

    $logPath = (string) ini_get('error_log');
    workspaceWsStartupLine(
        'INFO',
        'Runtime log',
        $logPath !== '' ? $logPath : 'PHP error_log is not configured'
    );

    $privateStorage = trim((string) (getenv('PRIVATE_STORAGE_PATH') ?: ''));
    if ($privateStorage !== '') {
        workspaceWsStartupLine(
            is_dir($privateStorage) && is_writable($privateStorage) ? 'OK' : 'WARN',
            'Private storage',
            $privateStorage
                . (is_dir($privateStorage)
                    ? (is_writable($privateStorage) ? ' (writable)' : ' (not writable)')
                    : ' (directory does not exist yet)')
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

        workspaceWsStartupLine('OK', 'Site origin', $siteUrl);
        workspaceWsStartupLine('OK', 'Browser WebSocket URL', $publicUrl);
        workspaceWsStartupLine('OK', 'Native listener', 'tcp://' . workspaceWsFormatBindHost($bindHost) . ':' . $port);
        workspaceWsStartupLine(
            'OK',
            'Deployment mode',
            $sameOriginProxy ? 'same-origin reverse proxy' : 'direct/custom WebSocket endpoint'
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
                'Allowed WebSocket origins',
                'allowlist is empty',
                'set WS_ALLOWED_ORIGINS to the browser origin that serves Workspace Organizer'
            );
            $failures++;
        } else {
            workspaceWsStartupLine('OK', 'Allowed WebSocket origins', implode(', ', $origins));
        }
    } catch (Throwable $e) {
        workspaceWsStartupFail(
            'WebSocket configuration',
            $e->getMessage(),
            'check SITEURL, BASE_PATH, WS_HOST, WS_PORT, WS_PUBLIC_URL and WS_ALLOWED_ORIGINS in .env'
        );
        $failures++;
    }

    $maxConnections = (int) (getenv('WS_MAX_CONNECTIONS') ?: 256);
    if ($maxConnections < 1 || $maxConnections > 10000) {
        workspaceWsStartupFail(
            'Connection limit',
            'WS_MAX_CONNECTIONS=' . $maxConnections . ' is outside 1..10000',
            'set WS_MAX_CONNECTIONS to a value between 1 and 10000'
        );
        $failures++;
    } else {
        workspaceWsStartupLine('OK', 'Connection limit', (string) $maxConnections);
    }

    $maxPayloadBytes = (int) (getenv('WS_MAX_PAYLOAD_BYTES') ?: 2097152);
    if ($maxPayloadBytes < 1024 || $maxPayloadBytes > 16777216) {
        workspaceWsStartupFail(
            'Payload limit',
            'WS_MAX_PAYLOAD_BYTES=' . $maxPayloadBytes . ' is outside 1024..16777216',
            'set WS_MAX_PAYLOAD_BYTES between 1024 bytes and 16 MiB'
        );
        $failures++;
    } else {
        workspaceWsStartupLine(
            'OK',
            'Payload limit',
            $maxPayloadBytes . ' bytes (' . number_format($maxPayloadBytes / 1048576, 2, '.', '') . ' MiB)'
        );
    }

    $existingPid = workspaceWsReadPid($pidFile);
    if ($existingPid !== null && workspaceWsProcessExists($existingPid)) {
        workspaceWsStartupFail(
            'Existing WebSocket process',
            'PID ' . $existingPid . ' is already running',
            'use status/restart instead of starting a second listener'
        );
        $failures++;
    } elseif ($existingPid !== null) {
        workspaceWsStartupLine('WARN', 'Stale PID file', 'PID ' . $existingPid . ' is not running; stale file will be replaced');
    } else {
        workspaceWsStartupLine('OK', 'Existing WebSocket process', 'none detected');
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
                'Listener bind test',
                'tcp://' . workspaceWsFormatBindHost($bindHost) . ':' . $port . ' is available'
            );
        } else {
            workspaceWsStartupFail(
                'Listener bind test',
                ($errstr !== '' ? $errstr : 'unable to bind') . ' (error ' . $errno . ')',
                'check whether the port is already used, blocked, or unavailable to this hosting account'
            );
            $failures++;
        }
    }

    if ($failures > 0) {
        fwrite(STDOUT, "------------------------------------------------\n");
        workspaceWsStartupLine(
            'FAIL',
            'Startup preflight',
            $failures . ' critical problem(s) found; WebSocket server was not started'
        );
        workspaceWsStartupLine('INFO', 'Extended diagnostics', '"' . PHP_BINARY . '" bin/ws_doctor.php');
        fwrite(STDOUT, PHP_EOL);
        return false;
    }

    fwrite(STDOUT, "------------------------------------------------\n");
    workspaceWsStartupLine('OK', 'Startup preflight', 'all critical checks passed; starting WebSocket runtime');
    fwrite(STDOUT, PHP_EOL);
    return true;
}

// Process lifecycle commands must remain usable even when the application DB is
// unavailable. Load only the internal environment parser first so status/stop can
// locate the installation-specific PID file without booting the full application.
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
        // stale PID file must not permanently block startup. The listener bind
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
        throw new RuntimeException('Unable to create WebSocket runtime directory: ' . $directory);
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('WebSocket runtime directory is not writable: ' . $directory);
    }
    if (@file_put_contents($pidFile, (string) getmypid(), LOCK_EX) === false) {
        throw new RuntimeException('Unable to write WebSocket PID file: ' . $pidFile);
    }
    @chmod($pidFile, 0600);
}

function workspaceWsStop(string $pidFile): int
{
    $pid = workspaceWsReadPid($pidFile);
    if ($pid === null || !workspaceWsProcessExists($pid)) {
        @unlink($pidFile);
        fwrite(STDOUT, "WebSocket server is not running.\n");
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
        fwrite(STDERR, "Unable to signal WebSocket process {$pid}; stop it through the OS process manager.\n");
        return 1;
    }

    $deadline = microtime(true) + 8.0;
    while (microtime(true) < $deadline && workspaceWsProcessExists($pid)) {
        usleep(100000);
    }

    if (workspaceWsProcessExists($pid)) {
        fwrite(STDERR, "WebSocket process {$pid} did not stop within timeout.\n");
        return 1;
    }

    @unlink($pidFile);
    fwrite(STDOUT, "WebSocket server stopped.\n");
    return 0;
}

function workspaceWsDaemonize(): void
{
    if (PHP_OS_FAMILY === 'Windows' || !function_exists('pcntl_fork')) {
        fwrite(STDERR, "Daemon mode requires pcntl on Unix. Use foreground mode with Open Server/background process manager on Windows.\n");
        exit(2);
    }

    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Unable to fork WebSocket daemon');
    }
    if ($pid > 0) {
        fwrite(STDOUT, "WebSocket daemon starting with PID {$pid}.\n");
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
        fwrite(STDOUT, "WebSocket server is running (PID {$pid}).\n");
        exit(0);
    }
    @unlink($pidFile);
    fwrite(STDOUT, "WebSocket server is not running.\n");
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
    fwrite(STDERR, "Usage: php ws_server/server.php start [-d] | check [-d] | status | stop | restart\n");
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
        'Application bootstrap',
        'core runtime loaded; database and persisted module lifecycle initialized'
    );

    // A disabled Messenger module must not have a parallel always-on WebSocket
    // runtime. status/stop remain DB-independent above, while start/run fail
    // closed unless the isolated Messenger provider is in the effective composition.
    $moduleRuntime = \Core\ModuleRuntimeLoader::getInstance();
    if (!isset($moduleRuntime->providers()['messenger'])) {
        throw new RuntimeException('Messenger module is disabled in the effective module composition.');
    }
    workspaceWsStartupLine('OK', 'Messenger module', 'enabled in the effective runtime composition');

    $existingPid = workspaceWsReadPid($pidFile);
    if ($existingPid !== null && workspaceWsProcessExists($existingPid)) {
        throw new RuntimeException("WebSocket server is already running (PID {$existingPid}).");
    }
    @unlink($pidFile);

    if ($daemon) {
        workspaceWsDaemonize();
    }

    workspaceWsWritePid($pidFile);
    $currentPid = getmypid();
    workspaceWsStartupLine('OK', 'Process identity', 'PID ' . $currentPid . ' | pid_file=' . $pidFile);
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
        'WebSocket listener configured: tcp://%s:%d; public=%s; runtime=native',
        $host,
        $port,
        $publicUrl
    ));
    if (WebSocketEndpoint::usesSameOriginProxy()) {
        error_log(sprintf(
            'WebSocket reverse proxy required: %s -> %s',
            WebSocketEndpoint::proxyPath(),
            WebSocketEndpoint::proxyBackendUrl()
        ));
    }

    workspaceWsStartupLine(
        'INFO',
        'Starting native listener',
        'tcp://' . workspaceWsFormatBindHost($host) . ':' . $port
            . ' | browser=' . $publicUrl
            . ' | max_connections=' . $maxConnections
            . ' | max_payload=' . $maxPayloadBytes . ' bytes'
    );

    (new NativeMessengerServer(
        $host,
        $port,
        $allowedOrigins,
        $maxConnections,
        $maxPayloadBytes
    ))->run();

    workspaceWsStartupLine('INFO', 'WebSocket server', 'listener stopped normally');
} catch (Throwable $e) {
    $logPath = (string) ini_get('error_log');
    error_log('Native WebSocket server startup/runtime failure: ' . $e->getMessage());
    workspaceWsStartupFail(
        'WebSocket server',
        $e->getMessage(),
        'review the startup report above and run "' . PHP_BINARY . '" bin/ws_doctor.php'
    );
    if ($logPath !== '') {
        fwrite(STDERR, "Startup/runtime log: {$logPath}" . PHP_EOL);
    }
    fwrite(STDERR, "Run: php bin/ws_doctor.php" . PHP_EOL);
    exit(1);
}
