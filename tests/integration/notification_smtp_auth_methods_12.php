<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/notifications/SocketSmtpTransport.php';

use App\Services\Notifications\SocketSmtpTransport;
use App\Services\Notifications\SmtpTransportException;

function smtpAuthAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function smtpAuthSocketPair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (!is_array($pair) || count($pair) !== 2) {
        throw new RuntimeException('Не удалось открыть локальные сокеты SMTP-теста');
    }
    foreach ($pair as $socket) {
        stream_set_timeout($socket, 2);
    }
    return $pair;
}

$capabilities = SocketSmtpTransport::authenticationMethods([
    "250-test.smtp.local\r\n",
    "250-STARTTLS\r\n",
    "250-AUTH PLAIN\r\n",
    "250 SIZE 1000\r\n",
]);
smtpAuthAssert($capabilities === ['PLAIN'], 'Сервер только с AUTH PLAIN должен быть распознан');
smtpAuthAssert(
    SocketSmtpTransport::authenticationMethods(["250-AUTH LOGIN PLAIN XOAUTH2\r\n", "250 SIZE 1000\r\n"])
    === ['LOGIN', 'PLAIN'],
    'Нужно разрешать только два известных пароляных механизма'
);
smtpAuthAssert(
    SocketSmtpTransport::authenticationMethods(["250-AUTH=plain login\r\n"])
    === ['PLAIN', 'LOGIN'],
    'Альтернативный синтаксис AUTH= должен распознаваться'
);
smtpAuthAssert(
    SocketSmtpTransport::authenticationMethods(["250-AUTH XOAUTH2\r\n"]) === [],
    'Нельзя выбирать не реализованный способ авторизации'
);
smtpAuthAssert(
    SocketSmtpTransport::authenticationMethods(["250-STARTTLS\r\n", "250 SIZE 1000\r\n"]) === [],
    'Без объявленного AUTH пароль отправлять запрещено'
);

$transport = new SocketSmtpTransport();
$auth = new ReflectionMethod(SocketSmtpTransport::class, 'authenticate');
[$client, $server] = smtpAuthSocketPair();
try {
    fwrite($server, "235 2.7.0 OK\r\n");
    $auth->invoke($transport, $client, 'username@example.test', 'пароль-ТЕСТ', ['PLAIN']);
    $command = trim((string) fgets($server));
    smtpAuthAssert(str_starts_with($command, 'AUTH PLAIN '), 'Должен выбираться AUTH PLAIN');
    $decoded = base64_decode(substr($command, strlen('AUTH PLAIN ')), true);
    smtpAuthAssert($decoded === "\0username@example.test\0пароль-ТЕСТ",
        'AUTH PLAIN обязан передавать правильное кодирование полей');
    smtpAuthAssert(!str_contains($command, 'пароль-ТЕСТ'),
        'Пароль нельзя передавать открытым текстом даже внутри TLS');
} finally {
    fclose($client);
    fclose($server);
}

[$client, $server] = smtpAuthSocketPair();
try {
    fwrite($server, "334 VXNlcm5hbWU6\r\n334 UGFzc3dvcmQ6\r\n235 2.7.0 OK\r\n");
    $auth->invoke($transport, $client, 'login@example.test', 'secret-login', ['LOGIN', 'PLAIN']);
    smtpAuthAssert(trim((string) fgets($server)) === 'AUTH LOGIN',
        'При поддержке LOGIN не нужно менять старый успешный способ');
    smtpAuthAssert(trim((string) fgets($server)) === base64_encode('login@example.test'),
        'Логин должен кодироваться в отдельной строке');
    smtpAuthAssert(trim((string) fgets($server)) === base64_encode('secret-login'),
        'Пароль LOGIN должен кодироваться отдельно');
} finally {
    fclose($client);
    fclose($server);
}

[$client, $server] = smtpAuthSocketPair();
try {
    $failure = null;
    try {
        $auth->invoke($transport, $client, 'login@example.test', 'secret', ['XOAUTH2']);
    } catch (SmtpTransportException $e) {
        $failure = $e;
    }
    smtpAuthAssert($failure instanceof SmtpTransportException, 'Неизвестный способ должен давать безопасную ошибку');
    smtpAuthAssert($failure->resultCode === 'smtp_auth_unavailable' && !$failure->retryable,
        'Без поддерживаемого AUTH нельзя бесконечно повторять отправку');
    stream_set_blocking($server, false);
    smtpAuthAssert(fread($server, 1024) === '',
        'Без поддерживаемого AUTH нельзя отправлять логин и пароль');
} finally {
    fclose($client);
    fclose($server);
}

echo "Проверены AUTH PLAIN, LOGIN и запрет передачи пароля без согласованного способа\n";
