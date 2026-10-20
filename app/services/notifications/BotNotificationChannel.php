<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once __DIR__ . '/NotificationDeliveryChannel.php';
require_once __DIR__ . '/NotificationDeliveryResult.php';
require_once __DIR__ . '/NotificationHttpTransport.php';
require_once __DIR__ . '/NotificationSecretResolver.php';

use InvalidArgumentException;
use Throwable;

/**
 * Независимые адаптеры Telegram и MAX используют один безопасный транспорт,
 * но собственные официальные API и разные настройки получателя.
 */
final class BotNotificationChannel implements NotificationDeliveryChannel
{
    public function __construct(
        private string $channel,
        private NotificationSecretResolver $secrets,
        private NotificationHttpTransport $transport
    ) {
        if (!in_array($channel, ['telegram', 'max'], true)) {
            throw new InvalidArgumentException('Неизвестный мессенджер');
        }
    }

    public function name(): string
    {
        return $this->channel;
    }

    public function deliver(array $job): NotificationDeliveryResult
    {
        try {
            $config = self::configuration($this->channel, $job['config_json'] ?? '');
        } catch (InvalidArgumentException) {
            return NotificationDeliveryResult::failed('bot_config_invalid', 'Недопустимый идентификатор получателя', false);
        }

        try {
            $token = $this->secrets->resolve((string) ($job['secret_ref'] ?? ''));
            if (!is_string($token) || $token === '' || strlen($token) > 512
                || preg_match('/[\x00-\x20\x7F]/', $token) === 1) {
                return NotificationDeliveryResult::failed('bot_secret_missing', 'Недоступен токен бота', true);
            }
        } catch (Throwable) {
            return NotificationDeliveryResult::failed('bot_secret_missing', 'Недоступен токен бота', true);
        }

        // Не копируем пользовательские сообщения во внешние сети. В том числе
        // нельзя передавать содержимое будущего E2EE Messenger.
        $text = 'Notes: новое уведомление. Откройте сайт, чтобы посмотреть подробности.';
        if ($this->channel === 'telegram') {
            if (preg_match('/^[0-9]{4,20}:[A-Za-z0-9_-]{20,}$/', $token) !== 1) {
                return NotificationDeliveryResult::failed('bot_secret_invalid', 'Неверный формат Telegram-токена', false);
            }
            $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
            $headers = [];
            $payload = ['chat_id' => $config['recipient'], 'text' => $text,
                'disable_web_page_preview' => true];
        } else {
            $url = 'https://platform-api2.max.ru/messages?user_id=' . rawurlencode($config['recipient']);
            $headers = ['Authorization' => $token];
            $payload = ['text' => $text];
        }

        try {
            $response = $this->transport->postJson($url, $headers, $payload, 10);
        } catch (Throwable) {
            return NotificationDeliveryResult::failed('bot_transport_failed', 'Нет защищённого соединения с API мессенджера', true);
        }

        $status = (int) ($response['status'] ?? 0);
        $data = json_decode((string) ($response['body'] ?? ''), true);
        $success = $status >= 200 && $status < 300;
        if ($this->channel === 'telegram') {
            $success = $success && is_array($data) && ($data['ok'] ?? false) === true;
        }
        if ($success) {
            return NotificationDeliveryResult::sent($this->channel . '_accepted');
        }
        return NotificationDeliveryResult::failed(
            $this->channel . '_http_' . max(0, $status),
            'Мессенджер отклонил запрос, HTTP ' . $status,
            $status === 0 || $status === 408 || $status === 429 || $status >= 500
        );
    }

    /** @return array{recipient:string} */
    public static function configuration(string $channel, mixed $raw): array
    {
        try {
            $settings = is_array($raw) ? $raw : json_decode((string) $raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidArgumentException('Некорректные настройки получателя');
        }
        if (!is_array($settings) || array_is_list($settings)) {
            throw new InvalidArgumentException('Настройки получателя должны быть объектом');
        }
        if ($channel === 'telegram') {
            $value = (string) ($settings['chat_id'] ?? '');
            if (preg_match('/^-?[1-9][0-9]{0,18}$/', $value) !== 1) {
                throw new InvalidArgumentException('Некорректный chat_id Telegram');
            }
        } elseif ($channel === 'max') {
            $value = (string) ($settings['user_id'] ?? '');
            if (preg_match('/^[1-9][0-9]{0,18}$/', $value) !== 1) {
                throw new InvalidArgumentException('Некорректный user_id MAX');
            }
        } else {
            throw new InvalidArgumentException('Неизвестный мессенджер');
        }
        return ['recipient' => $value];
    }
}
