<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/notifications/SmtpTransport.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/SmtpTransportException.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/SocketSmtpTransport.php';

use App\Services\Notifications\SocketSmtpTransport;
use App\Services\Notifications\SmtpTransportException;

function smtpSocketAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function smtpSocketStartServer(string $mode): array
{
    $dir = sys_get_temp_dir() . '/workspace-smtp-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать каталог тестового SMTP-сервера');
    }

    $serverFile = $dir . '/server.php';
    $portFile = $dir . '/port.txt';
    $messageFile = $dir . '/message.eml';
    $script = <<<'PHP'
<?php
$portFile = $argv[1];
$messageFile = $argv[2];
$mode = $argv[3];
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!is_resource($server)) {
    fwrite(STDERR, "Не удалось запустить тестовый SMTP: {$error}\n");
    exit(2);
}
$address = stream_socket_get_name($server, false);
$port = (int) substr((string) strrchr($address, ':'), 1);
file_put_contents($portFile, (string) $port, LOCK_EX);
$conn = stream_socket_accept($server, 10);
if (!is_resource($conn)) {
    exit(3);
}
stream_set_timeout($conn, 10);
fwrite($conn, "220 test-smtp ESMTP\r\n");
$data = false;
$message = '';
while (($line = fgets($conn)) !== false) {
    $trimmed = rtrim($line, "\r\n");
    if ($data) {
        if ($trimmed === '.') {
            file_put_contents($messageFile, $message, LOCK_EX);
            fwrite($conn, "250 2.0.0 queued\r\n");
            $data = false;
            continue;
        }
        $message .= $line;
        continue;
    }
    if (str_starts_with($trimmed, 'EHLO ')) {
        fwrite($conn, "250-test-smtp\r\n250 8BITMIME\r\n");
        continue;
    }
    if (str_starts_with($trimmed, 'MAIL FROM:')) {
        fwrite($conn, "250 2.1.0 sender ok\r\n");
        continue;
    }
    if (str_starts_with($trimmed, 'RCPT TO:')) {
        fwrite($conn, $mode === 'temporary' ? "450 4.2.0 temporary\r\n" : "250 2.1.5 recipient ok\r\n");
        continue;
    }
    if ($trimmed === 'DATA') {
        fwrite($conn, "354 end with dot\r\n");
        $data = true;
        continue;
    }
    if ($trimmed === 'QUIT') {
        fwrite($conn, "221 2.0.0 bye\r\n");
        break;
    }
    fwrite($conn, "500 5.5.1 unexpected\r\n");
}
fclose($conn);
fclose($server);
PHP;
    file_put_contents($serverFile, $script, LOCK_EX);

    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $serverFile, $portFile, $messageFile, $mode],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Не удалось запустить дочерний тестовый SMTP-сервер');
    }
    fclose($pipes[0]);

    $port = 0;
    for ($attempt = 0; $attempt < 100; ++$attempt) {
        if (is_file($portFile)) {
            $port = (int) trim((string) file_get_contents($portFile));
            if ($port > 0) {
                break;
            }
        }
        usleep(20_000);
    }
    if ($port <= 0) {
        proc_terminate($process);
        throw new RuntimeException('Тестовый SMTP-сервер не сообщил порт');
    }

    return [$process, $pipes, $dir, $port, $messageFile];
}

function smtpSocketStopServer($process, array $pipes, string $dir): void
{
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            stream_get_contents($pipe);
            fclose($pipe);
        }
    }
    if (is_resource($process)) {
        proc_close($process);
    }
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

$settings = [
    'host' => '127.0.0.1',
    'port' => 0,
    'encryption' => 'none',
    'username' => '',
    'from_email' => 'notifications@example.test',
    'from_name' => 'Workspace Organizer',
    'reply_to' => '',
    'timeout_seconds' => 5,
    'client_name' => 'workspace.test',
];
$transport = new SocketSmtpTransport();

[$process, $pipes, $dir, $port, $messageFile] = smtpSocketStartServer('success');
try {
    $successSettings = $settings;
    $successSettings['port'] = $port;
    $transport->send(
        $successSettings,
        'user@example.test',
        'Проверка SMTP',
        'Текстовая версия письма',
        '<p>HTML версия письма</p>',
        null
    );
    $message = (string) file_get_contents($messageFile);
    smtpSocketAssert(str_contains($message, 'To: <user@example.test>'), 'SMTP должен передать адрес получателя');
    smtpSocketAssert(str_contains($message, 'Content-Type: multipart/alternative'), 'SMTP должен формировать multipart/alternative');
    smtpSocketAssert(str_contains($message, base64_encode('Текстовая версия письма')), 'SMTP должен передать текстовую альтернативу');
    smtpSocketAssert(str_contains($message, base64_encode('<p>HTML версия письма</p>')), 'SMTP должен передать HTML-альтернативу');
} finally {
    smtpSocketStopServer($process, $pipes, $dir);
}

[$process, $pipes, $dir, $port] = smtpSocketStartServer('temporary');
try {
    $temporarySettings = $settings;
    $temporarySettings['port'] = $port;
    $caught = null;
    try {
        $transport->send(
            $temporarySettings,
            'user@example.test',
            'Проверка временной ошибки',
            'Текст',
            '<p>Текст</p>',
            null
        );
    } catch (SmtpTransportException $e) {
        $caught = $e;
    }
    smtpSocketAssert($caught instanceof SmtpTransportException, 'SMTP 450 должен превращаться в контролируемую ошибку');
    smtpSocketAssert($caught->resultCode === 'smtp_temporary' && $caught->retryable === true, 'SMTP 4xx должен быть повторяемой ошибкой');
} finally {
    smtpSocketStopServer($process, $pipes, $dir);
}

fwrite(STDOUT, "[OK] сокетный SMTP-контракт уведомлений 1.1.0\n");
