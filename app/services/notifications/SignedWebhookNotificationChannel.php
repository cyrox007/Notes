<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once __DIR__ . '/NotificationDeliveryChannel.php';
require_once __DIR__ . '/NotificationDeliveryResult.php';
require_once __DIR__ . '/NotificationHttpTransport.php';
require_once __DIR__ . '/NotificationSecretResolver.php';

use InvalidArgumentException;
use Throwable;

final class SignedWebhookNotificationChannel implements NotificationDeliveryChannel
{
    public function __construct(
        private NotificationSecretResolver $secrets,
        private NotificationHttpTransport $transport
    ) {
    }

    public function name(): string
    {
        return 'webhook';
    }

    public function deliver(array $job): NotificationDeliveryResult
    {
        try {
            $config = self::configuration($job['config_json'] ?? '');
        } catch (InvalidArgumentException) {
            return NotificationDeliveryResult::failed('webhook_config_invalid', 'Некорректная конфигурация Webhook', false);
        }
        try {
            $secret = $this->secrets->resolve((string) ($job['secret_ref'] ?? ''));
            if ($secret === null || strlen($secret) < 32) {
                return NotificationDeliveryResult::failed('webhook_secret_missing', 'Для подписанного Webhook нужен секрет длиной не менее 32 символов', false);
            }
        } catch (Throwable) {
            return NotificationDeliveryResult::failed('webhook_secret_missing', 'Не найден секрет Webhook', true);
        }

        // Внешней системе не передаются тексты сообщений, заметок, файлов,
        // имена пользователей и данные E2EE-переписки.
        $payload = [
            'event' => (string) ($job['event_name'] ?? 'notification.new'),
            'version' => (int) ($job['event_version'] ?? 1),
            'notification_id' => (int) ($job['notification_id'] ?? 0),
            'category' => (string) ($job['category'] ?? 'system'),
            'importance' => (string) ($job['importance'] ?? 'normal'),
        ];
        $timestamp = (string) time();
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $timestamp . "\n" . $body, $secret);

        try {
            $response = $this->transport->postJson($config['url'], [
                'X-Notes-Timestamp' => $timestamp,
                'X-Notes-Signature' => 'sha256=' . $signature,
                'X-Notes-Delivery-Id' => (string) ($job['id'] ?? '0'),
            ], $payload, $config['timeout_seconds']);
        } catch (InvalidArgumentException) {
            return NotificationDeliveryResult::failed('webhook_endpoint_denied', 'Недопустимый адрес получателя Webhook', false);
        } catch (Throwable) {
            return NotificationDeliveryResult::failed('webhook_transport_failed', 'Не удалось выполнить защищённый Webhook-запрос', true);
        }

        $status = (int) ($response['status'] ?? 0);
        if ($status >= 200 && $status < 300) {
            return NotificationDeliveryResult::sent('webhook_accepted');
        }
        return NotificationDeliveryResult::failed(
            'webhook_http_' . max(0, $status),
            'Webhook вернул HTTP ' . $status,
            $status === 0 || $status === 408 || $status === 429 || $status >= 500
        );
    }

    /** @return array{url:string,timeout_seconds:int} */
    public static function configuration(mixed $raw): array
    {
        try {
            $data = is_array($raw) ? $raw : json_decode((string) $raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidArgumentException('Некорректный JSON Webhook');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Webhook требует объект настроек');
        }
        $url = trim((string) ($data['url'] ?? ''));
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || isset($parts['query']) || isset($parts['port'])
            || strlen($url) > 1000) {
            throw new InvalidArgumentException('Webhook допускает только внешний HTTPS-адрес без учётных данных');
        }
        $timeout = (int) ($data['timeout_seconds'] ?? 10);
        if ($timeout < 1 || $timeout > 20) {
            throw new InvalidArgumentException('Недопустимый тайм-аут Webhook');
        }
        return ['url' => $url, 'timeout_seconds' => $timeout];
    }
}
