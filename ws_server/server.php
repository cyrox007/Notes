<?php

declare(strict_types=1);

use App\Sockets\NativeMessengerServer;
use Core\WebSocketEndpoint;

ini_set('display_errors', '0');
if (!defined('SITEPATH')) {
    define('SITEPATH', dirname(__FILE__) . '/..');
}
error_reporting(E_ALL);
ini_set('error_log', sys_get_temp_dir() . '/workspace-organizer-ws-startup.log');

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
        usleep(100_000);
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

if (!in_array($command, ['start', 'run'], true)) {
    fwrite(STDERR, "Usage: php ws_server/server.php start [-d] | status | stop | restart\n");
    exit(2);
}

// From this point onward a real server process is being started. Keep every
// startup failure inside one diagnostic boundary: Windows/OpenServer users
// should never get a silent exit just because display_errors is disabled.
try {
    // Load the complete application stack including persisted module lifecycle state.
    require_once SITEPATH . '/core.php';

    // A disabled Messenger module must not have a parallel always-on WebSocket
    // runtime. status/stop remain DB-independent above, while start/run fail
    // closed unless the isolated Messenger provider is in the effective composition.
    $moduleRuntime = \Core\ModuleRuntimeLoader::getInstance();
    if (!isset($moduleRuntime->providers()['messenger'])) {
        throw new RuntimeException('Messenger module is disabled in the effective module composition.');
    }

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

    (new NativeMessengerServer(
        $host,
        $port,
        $allowedOrigins,
        $maxConnections,
        $maxPayloadBytes
    ))->run();
} catch (Throwable $e) {
    $logPath = (string) ini_get('error_log');
    error_log('Native WebSocket server startup/runtime failure: ' . $e->getMessage());
    fwrite(STDERR, "WebSocket server failed: " . $e->getMessage() . PHP_EOL);
    if ($logPath !== '') {
        fwrite(STDERR, "Startup/runtime log: {$logPath}" . PHP_EOL);
    }
    fwrite(STDERR, "Run: php bin/ws_doctor.php" . PHP_EOL);
    exit(1);
}
