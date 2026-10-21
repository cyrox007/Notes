<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once __DIR__ . '/NotificationDeliveryChannel.php';
require_once __DIR__ . '/NotificationDeliveryResult.php';
require_once __DIR__ . '/PushSubscriptionService.php';
require_once __DIR__ . '/WebPushCrypto.php';
require_once __DIR__ . '/WebPushHttpTransport.php';

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class WebPushNotificationChannel implements NotificationDeliveryChannel
{
    public function __construct(
        private PushSubscriptionService $subscriptions,
        private WebPushHttpTransport $transport
    ) {
    }

    public function name(): string
    {
        return 'browser';
    }

    public function deliver(array $job): NotificationDeliveryResult
    {
        $userId = (int) ($job['recipient_user_id'] ?? 0);
        $configId = (int) ($job['channel_config_id'] ?? 0);
        if ($userId <= 0 || $configId <= 0) {
            return NotificationDeliveryResult::failed(
                'push_recipient_invalid', 'Не удалось определить получателя Push', false
            );
        }
        try {
            $device = $this->subscriptions->subscriptionForJob($userId, $configId);
        } catch (Throwable) {
            return NotificationDeliveryResult::failed(
                'push_subscription_unavailable', 'Не удалось проверить устройство', true
            );
        }
        if ($device === null) {
            return NotificationDeliveryResult::failed(
                'push_subscription_revoked', 'Устройство отключено или не принадлежит пользователю', false
            );
        }

        $actionUrl = (string) ($job['action_url'] ?? '');
        $actionPath = $actionUrl !== '' ? (string) parse_url($actionUrl, PHP_URL_PATH) : '';
        $actionFragment = $actionUrl !== '' ? (string) parse_url($actionUrl, PHP_URL_FRAGMENT) : '';
        $path = $actionPath !== '' && $actionFragment !== ''
            ? self::safePath($actionPath) . '#' . $actionFragment
            : self::safePath((string) ($job['target_path'] ?? ''));
        $notificationId = (int) ($job['notification_id'] ?? 0);
        try {
            $payload = json_encode(
                ['id' => max(0, $notificationId), 'path' => $path],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
            $encrypted = WebPushCrypto::encrypt(
                $payload,
                WebPushCrypto::decode64($device['p256dh']),
                WebPushCrypto::decode64($device['auth'])
            );
            $headers = WebPushCrypto::headers($device['endpoint']);
            $status = $this->transport->send($device['endpoint'], $encrypted, $headers);
        } catch (InvalidArgumentException) {
            return NotificationDeliveryResult::failed(
                'push_subscription_invalid', 'Ключ браузера или endpoint повреждён', false
            );
        } catch (Throwable) {
            return NotificationDeliveryResult::failed(
                'push_transport_failed', 'Web Push не доставлен: проверьте VAPID, сеть и TLS', true
            );
        }

        if ($status >= 200 && $status < 300) {
            // Push-сервис подтвердил приём, а не показ на экране устройства.
            return NotificationDeliveryResult::sent('push_accepted');
        }
        if ($status === 404 || $status === 410) {
            try {
                $this->subscriptions->revokeId($userId, $device['id']);
            } catch (Throwable) {
                // Задание всё равно не должно повторяться на истёкший endpoint.
            }
            return NotificationDeliveryResult::failed(
                'push_expired', 'Браузерная подписка отозвана push-сервисом', false
            );
        }
        return NotificationDeliveryResult::failed(
            'push_http_' . max(0, $status),
            'Push-сервис отклонил доставку (HTTP ' . $status . ')',
            $status === 0 || $status === 408 || $status === 429 || $status >= 500
        );
    }

    private static function safePath(string $value): string
    {
        if ($value === '' || strlen($value) > 500
            || !str_starts_with($value, '/') || str_starts_with($value, '//')
            || strpbrk($value, "\\\0\r\n") !== false) {
            return '/';
        }

        $base = '/' . trim((string) (getenv('BASE_PATH') ?: ''), '/');
        if ($base === '/') {
            return $value;
        }
        if ($value === $base || str_starts_with($value, $base . '/')) {
            return $value;
        }
        return $base . $value;
    }
}
