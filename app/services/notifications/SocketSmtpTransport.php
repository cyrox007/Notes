<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once __DIR__ . '/SmtpTransport.php';
require_once __DIR__ . '/SmtpTransportException.php';

use InvalidArgumentException;

final class SocketSmtpTransport implements SmtpTransport
{
    public function send(
        array $settings,
        string $recipientEmail,
        string $subject,
        string $textBody,
        string $htmlBody,
        #[\SensitiveParameter] ?string $password
    ): void {
        $host = (string) ($settings['host'] ?? '');
        $port = (int) ($settings['port'] ?? 0);
        $encryption = (string) ($settings['encryption'] ?? 'tls');
        $timeout = (int) ($settings['timeout_seconds'] ?? 10);
        $username = (string) ($settings['username'] ?? '');
        $clientName = (string) ($settings['client_name'] ?? 'workspace.local');

        $stream = $this->connect($host, $port, $encryption, $timeout);
        try {
            $this->expect($stream, [220], 'smtp_greeting');
            $methods = $this->ehlo($stream, $clientName);

            if ($encryption === 'tls') {
                $this->startTls($stream, $host);
                $methods = $this->ehlo($stream, $clientName);
            }

            if ($username !== '') {
                if ($encryption === 'none') {
                    throw new SmtpTransportException(
                        'SMTP-авторизация без защищённого соединения запрещена',
                        'smtp_auth_insecure',
                        false
                    );
                }
                $this->authenticate($stream, $username, $password, $methods);
            }

            $fromEmail = (string) ($settings['from_email'] ?? '');
            $this->command($stream, 'MAIL FROM:<' . $fromEmail . '>', [250], 'smtp_mail_from');
            $this->command($stream, 'RCPT TO:<' . $recipientEmail . '>', [250, 251], 'smtp_recipient');
            $this->command($stream, 'DATA', [354], 'smtp_data');

            $message = $this->buildMessage(
                $settings,
                $recipientEmail,
                $subject,
                $textBody,
                $htmlBody
            );
            $this->writeAll($stream, $this->dotStuff($message) . "\r\n.\r\n");
            $this->expect($stream, [250], 'smtp_message');

            try {
                $this->command($stream, 'QUIT', [221], 'smtp_quit');
            } catch (SmtpTransportException) {
                // Письмо уже принято сервером. Ошибка QUIT не должна создавать дубль.
            }
        } finally {
            fclose($stream);
        }
    }

    /** @return resource */
    private function connect(string $host, int $port, string $encryption, int $timeout)
    {
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('SMTP host/port имеют недопустимое значение');
        }
        if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            throw new InvalidArgumentException('Неизвестный режим шифрования SMTP');
        }
        if ($timeout < 1 || $timeout > 30) {
            throw new InvalidArgumentException('SMTP timeout должен быть в диапазоне 1..30 секунд');
        }

        $endpointHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '[' . $host . ']'
            : $host;
        $scheme = $encryption === 'ssl' ? 'tls' : 'tcp';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
                'disable_compression' => true,
            ],
        ]);

        $errno = 0;
        $error = '';
        $stream = @stream_socket_client(
            $scheme . '://' . $endpointHost . ':' . $port,
            $errno,
            $error,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($stream)) {
            throw new SmtpTransportException(
                'Не удалось установить соединение с SMTP-сервером',
                'smtp_connect_failed',
                true
            );
        }

        stream_set_timeout($stream, $timeout);
        return $stream;
    }

    /**
     * После STARTTLS сервер может изменить возможности: EHLO повторяется.
     *
     * @param resource $stream
     * @return list<string>
     */
    private function ehlo($stream, string $clientName): array
    {
        $this->writeAll($stream, 'EHLO ' . $clientName . "\r\n");
        $replyLines = [];
        $this->expect($stream, [250], 'smtp_ehlo', $replyLines);
        return self::authenticationMethods($replyLines);
    }

    /**
     * Сырые ответы сервера используются только внутри SMTP-соединения.
     *
     * @param list<string> $replyLines
     * @return list<string>
     */
    public static function authenticationMethods(array $replyLines): array
    {
        $methods = [];
        foreach ($replyLines as $line) {
            if (preg_match('/^250[ -]AUTH(?:=|\\s+)(.*)$/i', rtrim($line, "\r\n"), $match) !== 1) {
                continue;
            }
            foreach (preg_split('/\\s+/', strtoupper(trim($match[1]))) ?: [] as $method) {
                if (in_array($method, ['PLAIN', 'LOGIN'], true)) {
                    $methods[$method] = true;
                }
            }
        }
        return array_keys($methods);
    }

    /** @param resource $stream */
    private function startTls($stream, string $host): void
    {
        $this->command($stream, 'STARTTLS', [220], 'smtp_starttls');
        $enabled = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($enabled !== true) {
            throw new SmtpTransportException(
                'SMTP-сервер не смог установить защищённое соединение',
                'smtp_tls_failed',
                true
            );
        }

        $params = stream_context_get_params($stream);
        $peerName = (string) ($params['options']['ssl']['peer_name'] ?? '');
        if ($peerName !== '' && $peerName !== $host) {
            throw new SmtpTransportException(
                'Имя SMTP-сервера изменилось при установке TLS',
                'smtp_tls_failed',
                false
            );
        }
    }

    /**
     * Не пробуем следующий способ после отказа сервера: повторная
     * авторизация расходует попытки входа и может блокировать учётную запись.
     *
     * @param resource $stream
     * @param list<string> $methods
     */
    private function authenticate(
        $stream,
        string $username,
        #[\SensitiveParameter] ?string $password,
        array $methods
    ): void {
        if ($password === null || $password === '') {
            throw new SmtpTransportException(
                'Для SMTP-авторизации не задан секрет',
                'smtp_secret_missing',
                true
            );
        }

        if (in_array('LOGIN', $methods, true)) {
            $this->command($stream, 'AUTH LOGIN', [334], 'smtp_auth');
            $this->command($stream, base64_encode($username), [334], 'smtp_auth');
            $this->command($stream, base64_encode($password), [235], 'smtp_auth');
            return;
        }

        if (in_array('PLAIN', $methods, true)) {
            $response = base64_encode("\0" . $username . "\0" . $password);
            $this->command($stream, 'AUTH PLAIN ' . $response, [235], 'smtp_auth');
            return;
        }

        throw new SmtpTransportException(
            'SMTP-сервер не объявил поддерживаемый способ авторизации после TLS',
            'smtp_auth_unavailable',
            false
        );
    }

    /**
     * @param resource $stream
     * @param list<int> $expectedCodes
     */
    private function command($stream, string $command, array $expectedCodes, string $resultCode): void
    {
        if (str_contains($command, "\r") || str_contains($command, "\n")) {
            throw new InvalidArgumentException('SMTP-команда содержит недопустимый перевод строки');
        }

        $this->writeAll($stream, $command . "\r\n");
        $this->expect($stream, $expectedCodes, $resultCode);
    }

    /**
     * @param resource $stream
     * @param list<int> $expectedCodes
     */
    private function expect($stream, array $expectedCodes, string $resultCode, ?array &$replyLines = null): void
    {
        $code = $this->readReplyCode($stream, $replyLines);
        if (in_array($code, $expectedCodes, true)) {
            return;
        }

        $retryable = $code >= 400 && $code < 500;
        $normalizedCode = $retryable ? 'smtp_temporary' : $resultCode . '_rejected';
        throw new SmtpTransportException(
            'SMTP-сервер отклонил операцию, код ' . $code,
            $normalizedCode,
            $retryable
        );
    }

    /** @param resource $stream */
    private function readReplyCode($stream, ?array &$replyLines = null): int
    {
        $code = null;
        for ($lineNo = 0; $lineNo < 100; ++$lineNo) {
            $line = fgets($stream, 4097);
            if ($line === false) {
                $meta = stream_get_meta_data($stream);
                $resultCode = ($meta['timed_out'] ?? false) ? 'smtp_timeout' : 'smtp_connection_lost';
                throw new SmtpTransportException(
                    'Соединение SMTP завершилось до полного ответа сервера',
                    $resultCode,
                    true
                );
            }
            if (strlen($line) > 4096) {
                throw new SmtpTransportException(
                    'SMTP-сервер вернул слишком длинную строку ответа',
                    'smtp_protocol_error',
                    false
                );
            }
            if (preg_match('/^(\d{3})([ -])/', $line, $matches) !== 1) {
                throw new SmtpTransportException(
                    'SMTP-сервер вернул ответ неизвестного формата',
                    'smtp_protocol_error',
                    false
                );
            }

            if ($replyLines !== null) {
                $replyLines[] = $line;
            }

            $currentCode = (int) $matches[1];
            $code ??= $currentCode;
            if ($currentCode !== $code) {
                throw new SmtpTransportException(
                    'SMTP-сервер изменил код внутри многострочного ответа',
                    'smtp_protocol_error',
                    false
                );
            }
            if ($matches[2] === ' ') {
                return $code;
            }
        }

        throw new SmtpTransportException(
            'SMTP-сервер вернул чрезмерно длинный многострочный ответ',
            'smtp_protocol_error',
            false
        );
    }

    /** @param resource $stream */
    private function writeAll($stream, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new SmtpTransportException(
                    'Не удалось записать данные в SMTP-соединение',
                    'smtp_connection_lost',
                    true
                );
            }
            $offset += $written;
        }
    }

    /** @param array<string,mixed> $settings */
    private function buildMessage(
        array $settings,
        string $recipientEmail,
        string $subject,
        string $textBody,
        string $htmlBody
    ): string {
        $fromEmail = (string) ($settings['from_email'] ?? '');
        $fromName = (string) ($settings['from_name'] ?? 'Workspace Organizer');
        $replyTo = (string) ($settings['reply_to'] ?? '');
        $boundary = 'wo-' . bin2hex(random_bytes(16));
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@workspace.local>',
            'From: ' . $this->mailboxHeader($fromName, $fromEmail),
            'To: <' . $recipientEmail . '>',
            'Subject: ' . $this->encodedHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        if ($replyTo !== '') {
            $headers[] = 'Reply-To: <' . $replyTo . '>';
        }

        $body = [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode($textBody), 76, "\r\n")),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode($htmlBody), 76, "\r\n")),
            '--' . $boundary . '--',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $body);
    }

    private function mailboxHeader(string $name, string $email): string
    {
        if ($name === '') {
            return '<' . $email . '>';
        }
        return $this->encodedHeader($name) . ' <' . $email . '>';
    }

    private function encodedHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function dotStuff(string $message): string
    {
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $message = preg_replace('/^\./m', '..', $message) ?? $message;
        return str_replace("\n", "\r\n", $message);
    }
}
