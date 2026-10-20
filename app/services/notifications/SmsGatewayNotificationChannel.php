<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once __DIR__ . '/NotificationDeliveryChannel.php';
require_once __DIR__ . '/NotificationDeliveryResult.php';
require_once __DIR__ . '/NotificationSecretResolver.php';
require_once __DIR__ . '/NotificationHttpTransport.php';
require_once __DIR__ . '/SignedWebhookNotificationChannel.php';

use InvalidArgumentException;
use Throwable;

/**
 * Исходящая доставка на разрешённый отдельный SMS-шлюз по HTTPS.
 * Ядро не подключается к GSM-модему и не принимает SMS-команды.
 */
final class SmsGatewayNotificationChannel implements NotificationDeliveryChannel
{
    public function __construct(
        private NotificationSecretResolver $secrets,
        private NotificationHttpTransport $transport
    ) {
    }

    public function name(): string
    {
        return 'sms';
    }

    public function deliver(array $job): NotificationDeliveryResult
    {
        try {
            $config = self::configuration($job['config_json'] ?? '');
        } catch (InvalidArgumentException) {
            return NotificationDeliveryResult::failed(
                'sms_config_invalid', 'SMS-шлюз или номер получателя настроен неверно', false
            );
        }

        try {
            $secret = $this->secrets->resolve((string) ($job['secret_ref'] ?? ''));
            if (!is_string($secret) || strlen($secret) < 24
                || strlen($secret) > 512 || preg_match('/[\\x00-\\x20\\x7f]/', $secret)) {
                return NotificationDeliveryResult::failed(
                    'sms_secret_missing', 'Недоступен секрет SMS-шлюза', true
                );
            }
        } catch (Throwable) {
            return NotificationDeliveryResult::failed(
                'sms_secret_missing', 'Недоступен секрет SMS-шлюза', true
            );
        }

        // SMS содержит только обезличенный сигнал. Тексты переписки,
        // задач и других объектов не должны покидать Notes.
        $payload = [
            'recipient' => $config['phone'],
            'text' => 'Notes: новое уведомление. Откройте приложение для подробностей.',
            'delivery_id' => (string) ($job['id'] ?? '0'),
        ];
        try {
            $response = $this->transport->postJson(
                $config['url'],
                [
                    'Authorization' => 'Bearer ' . $secret,
                    'X-Notes-Delivery-Id' => (string) ($job['id'] ?? '0'),
                ],
                $payload,
                $config['timeout_seconds']
            );
        } catch (InvalidArgumentException) {
            return NotificationDeliveryResult::failed(
                'sms_endpoint_denied', 'Адрес SMS-шлюза запрещён политикой HTTPS', false
            );
        } catch (Throwable) {
            return NotificationDeliveryResult::failed(
                'sms_transport_failed', 'Не удалось связаться с SMS-шлюзом', true
            );
        }

        $status = (int) ($response['status'] ?? 0);
        if ($status >= 200 && $status < 300) {
            // Подтверждено лишь принятие шлюзом, а не получение телефоном.
            return NotificationDeliveryResult::sent('sms_gateway_accepted');
        }
        return NotificationDeliveryResult::failed(
            'sms_http_' . max(0, $status),
            'SMS-шлюз не принял сообщение, HTTP ' . $status,
            $status === 0 || $status === 408 || $status === 429 || $status >= 500
        );
    }

    /** @return array{url:string,phone:string,timeout_seconds:int} */
    public static function configuration(mixed $raw): array
    {
        try {
            $data = is_array($raw) ? $raw : json_decode((string) $raw, true, 12, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidArgumentException('Неверные параметры SMS-шлюза');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Параметры SMS-шлюза должны быть объектом');
        }

        $base = SignedWebhookNotificationChannel::configuration([
            'url' => $data['url'] ?? '',
            'timeout_seconds' => $data['timeout_seconds'] ?? 10,
        ]);
        $phone = trim((string) ($data['phone'] ?? ''));
        if (preg_match('/^\\+[1-9][0-9]{7,14}$/', $phone) !== 1) {
            throw new InvalidArgumentException('Номер SMS должен соответствовать E.164');
        }
        return [
            'url' => $base['url'],
            'phone' => $phone,
            'timeout_seconds' => $base['timeout_seconds'],
        ];
    }
}
