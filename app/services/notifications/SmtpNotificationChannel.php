<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once __DIR__ . '/NotificationDeliveryChannel.php';
require_once __DIR__ . '/NotificationDeliveryResult.php';
require_once __DIR__ . '/NotificationEmailRecipientResolver.php';
require_once __DIR__ . '/NotificationSecretResolver.php';
require_once __DIR__ . '/SmtpTransport.php';
require_once __DIR__ . '/SmtpTransportException.php';

use InvalidArgumentException;
use JsonException;
use Throwable;

final class SmtpNotificationChannel implements NotificationDeliveryChannel
{
    public function __construct(
        private NotificationEmailRecipientResolver $recipients,
        private NotificationSecretResolver $secrets,
        private SmtpTransport $transport,
        private string $baseUrl = ''
    ) {
        $this->baseUrl = $this->normalizedBaseUrl($baseUrl);
    }

    public function name(): string
    {
        return 'email';
    }

    public function deliver(array $job): NotificationDeliveryResult
    {
        $userId = (int) ($job['recipient_user_id'] ?? 0);
        if ($userId <= 0) {
            return NotificationDeliveryResult::failed(
                'smtp_recipient_missing',
                'В задании SMTP отсутствует идентификатор получателя',
                false
            );
        }

        $recipientEmail = $this->recipients->emailForUser($userId);
        if ($recipientEmail === null) {
            return NotificationDeliveryResult::failed(
                'smtp_recipient_unavailable',
                'У получателя нет доступного E-mail адреса',
                false
            );
        }

        try {
            $settings = $this->settingsFromJob($job);
        } catch (InvalidArgumentException|JsonException $e) {
            return NotificationDeliveryResult::failed('smtp_config_invalid', $e->getMessage(), false);
        }

        try {
            $password = $this->secrets->resolve(
                isset($job['secret_ref']) ? (string) $job['secret_ref'] : null
            );
        } catch (Throwable) {
            return NotificationDeliveryResult::failed(
                'smtp_secret_unavailable',
                'Секрет SMTP недоступен через настроенную ссылку',
                true
            );
        }

        if ($settings['username'] !== '' && ($password === null || $password === '')) {
            return NotificationDeliveryResult::failed(
                'smtp_secret_unavailable',
                'Для SMTP-авторизации не найден пароль',
                true
            );
        }

        [$textBody, $htmlBody] = $this->messageBodies($job);
        try {
            $this->transport->send(
                $settings,
                $recipientEmail,
                (string) ($job['title'] ?? 'Уведомление'),
                $textBody,
                $htmlBody,
                $password
            );
        } catch (SmtpTransportException $e) {
            return NotificationDeliveryResult::failed(
                $e->resultCode,
                $e->getMessage(),
                $e->retryable
            );
        } catch (Throwable $e) {
            return NotificationDeliveryResult::failed(
                'smtp_transport_exception',
                'SMTP-транспорт завершился исключением типа ' . $e::class,
                true
            );
        }

        return NotificationDeliveryResult::sent('smtp_sent');
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     * @throws JsonException
     */
    private function settingsFromJob(array $job): array
    {
        $raw = $job['config_json'] ?? null;
        $config = is_array($raw)
            ? $raw
            : json_decode((string) $raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($config)) {
            throw new InvalidArgumentException('Конфигурация SMTP должна быть JSON-объектом');
        }

        foreach (['password', 'pass', 'secret', 'token', 'api_key'] as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $config)) {
                throw new InvalidArgumentException(
                    'Секрет SMTP нельзя хранить в config_json; используйте secret_ref'
                );
            }
        }

        $host = trim((string) ($config['host'] ?? ''));
        $port = (int) ($config['port'] ?? 587);
        $encryption = strtolower(trim((string) ($config['encryption'] ?? 'tls')));
        $username = trim((string) ($config['username'] ?? ''));
        $fromEmail = trim((string) ($config['from_email'] ?? ''));
        $fromName = trim((string) ($config['from_name'] ?? 'Workspace Organizer'));
        $replyTo = trim((string) ($config['reply_to'] ?? ''));
        $timeout = (int) ($config['timeout_seconds'] ?? 10);
        $clientName = trim((string) ($config['client_name'] ?? 'workspace.local'));

        $this->assertHost($host);
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Порт SMTP должен быть в диапазоне 1..65535');
        }
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            throw new InvalidArgumentException('Режим SMTP должен быть tls, ssl или none');
        }
        if ($username !== '' && $encryption === 'none') {
            throw new InvalidArgumentException('SMTP-авторизация без TLS запрещена');
        }
        if ($fromEmail === '' || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Адрес отправителя SMTP имеет недопустимый формат');
        }
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Reply-To SMTP имеет недопустимый формат');
        }
        if ($timeout < 1 || $timeout > 30) {
            throw new InvalidArgumentException('SMTP timeout должен быть в диапазоне 1..30 секунд');
        }

        $this->assertHeaderValue($username, 'SMTP username');
        $this->assertHeaderValue($fromName, 'Имя отправителя SMTP');
        $this->assertClientName($clientName);

        return [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => $username,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'reply_to' => $replyTo,
            'timeout_seconds' => $timeout,
            'client_name' => $clientName,
        ];
    }

    /**
     * @param array<string,mixed> $job
     * @return array{0:string,1:string}
     */
    private function messageBodies(array $job): array
    {
        $body = trim((string) ($job['body'] ?? ''));
        $title = trim((string) ($job['title'] ?? 'Уведомление'));
        $targetUrl = $this->targetUrl((string) ($job['target_path'] ?? ''));

        $text = $body;
        if ($targetUrl !== null) {
            $text .= ($text === '' ? '' : "\n\n") . 'Открыть в Workspace Organizer: ' . $targetUrl;
        }

        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeBody = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $html = '<!doctype html><html><body>'
            . '<h2>' . $safeTitle . '</h2>'
            . '<p>' . $safeBody . '</p>';
        if ($targetUrl !== null) {
            $safeUrl = htmlspecialchars($targetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<p><a href="' . $safeUrl . '">Открыть в Workspace Organizer</a></p>';
        }
        $html .= '</body></html>';

        return [$text, $html];
    }

    private function targetUrl(string $targetPath): ?string
    {
        if ($this->baseUrl === '' || $targetPath === '' || !str_starts_with($targetPath, '/')) {
            return null;
        }
        if (str_starts_with($targetPath, '//') || str_contains($targetPath, "\0")) {
            return null;
        }
        return $this->baseUrl . $targetPath;
    }

    private function normalizedBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return '';
        }
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return '';
        }
        if (trim((string) ($parts['host'] ?? '')) === '') {
            return '';
        }
        return $baseUrl;
    }

    private function assertHost(string $host): void
    {
        if ($host === '' || strlen($host) > 253) {
            throw new InvalidArgumentException('SMTP host имеет недопустимую длину');
        }
        if (preg_match('/[\x00-\x20\x7F]/', $host) === 1) {
            throw new InvalidArgumentException('SMTP host содержит недопустимые символы');
        }
    }

    private function assertHeaderValue(string $value, string $field): void
    {
        if (str_contains($value, "\r") || str_contains($value, "\n") || str_contains($value, "\0")) {
            throw new InvalidArgumentException($field . ' содержит недопустимые управляющие символы');
        }
        if (strlen($value) > 190) {
            throw new InvalidArgumentException($field . ' имеет недопустимую длину');
        }
    }

    private function assertClientName(string $clientName): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,189}$/', $clientName) !== 1) {
            throw new InvalidArgumentException('Имя SMTP-клиента имеет недопустимый формат');
        }
    }
}
