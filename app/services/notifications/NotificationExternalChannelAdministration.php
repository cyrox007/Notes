<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once dirname(__DIR__, 3) . '/core/DatabaseManager.php';
require_once __DIR__ . '/SignedWebhookNotificationChannel.php';
require_once __DIR__ . '/BotNotificationChannel.php';
require_once __DIR__ . '/SmsGatewayNotificationChannel.php';
require_once __DIR__ . '/EnvironmentNotificationSecretResolver.php';

use Core\DatabaseManager;
use InvalidArgumentException;

final class NotificationExternalChannelAdministration
{
    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @return list<array<string,mixed>> */
    public function overview(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, channel, owner_user_id, label, enabled, config_json,
                    (secret_ref IS NOT NULL AND secret_ref <> '') AS has_secret
             FROM notification_channel_configs
             WHERE channel IN ('webhook','telegram','max','sms')
             ORDER BY channel,owner_user_id,id LIMIT 100"
        );
        foreach ($rows as &$row) {
            $settings = json_decode((string) ($row['config_json'] ?? ''), true);
            $row['settings'] = is_array($settings) ? $settings : [];
            unset($row['config_json']);
        }
        unset($row);
        return $rows;
    }

    /** @param array<string,mixed> $input */
    public function save(array $input): void
    {
        $channel = (string) ($input['channel'] ?? '');
        if (!in_array($channel, ['telegram', 'max', 'webhook', 'sms'], true)) {
            throw new InvalidArgumentException('Неизвестный канал доставки');
        }
        $enabled = $input['enabled'] ?? false;
        if (!is_bool($enabled)) {
            throw new InvalidArgumentException('Укажите состояние канала');
        }
        $secret = trim((string) ($input['secret_ref'] ?? ''));
        if ($secret !== '' && preg_match('/^env:[A-Z][A-Z0-9_]{1,127}$/', $secret) !== 1) {
            throw new InvalidArgumentException('Токен задаётся только ссылкой на переменную окружения');
        }

        if ($channel === 'webhook') {
            $settings = SignedWebhookNotificationChannel::configuration([
                'url' => trim((string) ($input['url'] ?? '')),
                'timeout_seconds' => 10,
            ]);
            $owner = null;
            $scope = 0;
            $label = 'Подписанный системный Webhook';
        } else {
            $owner = filter_var($input['owner_user_id'] ?? null, FILTER_VALIDATE_INT);
            if ($owner === false || $owner < 1) {
                throw new InvalidArgumentException('Укажите пользователя-получателя');
            }
            $exists = $this->db->fetchValue(
                "SELECT 1 FROM users WHERE id=:id AND is_active=1 AND account_status='active'
                 AND deletion_requested_at IS NULL AND anonymized_at IS NULL LIMIT 1",
                [':id' => $owner]
            );
            if ((int) $exists !== 1) {
                throw new InvalidArgumentException('Получатель не найден или отключён');
            }
            if ($channel === 'sms') {
                $settings = SmsGatewayNotificationChannel::configuration([
                    'transport' => 'headwind',
                    'phone' => trim((string) ($input['phone'] ?? '')),
                ]);
                $label = 'Личный SMS-шлюз';
            } else {
                $settings = $channel === 'telegram'
                    ? ['chat_id' => trim((string) ($input['chat_id'] ?? ''))]
                    : ['user_id' => trim((string) ($input['max_user_id'] ?? ''))];
                BotNotificationChannel::configuration($channel, $settings);
                $label = $channel === 'telegram' ? 'Личный Telegram' : 'Личный MAX';
            }
            $scope = $owner;
        }

        $prior = $this->db->fetchOne(
            'SELECT id,secret_ref FROM notification_channel_configs
             WHERE channel=:channel AND owner_scope=:scope AND label=:label LIMIT 1',
            [':channel' => $channel, ':scope' => $scope, ':label' => $label]
        );
        $localSms = $channel === 'sms' && ($settings['transport'] ?? '') === 'headwind';
        if ($localSms) {
            $secret = '';
        } elseif ($secret === '' && $prior !== null) {
            $secret = trim((string) ($prior['secret_ref'] ?? ''));
        }
        if ($enabled && !$localSms && !$this->secretAvailable($secret)) {
            throw new InvalidArgumentException('Секрет внешнего канала не доступен PHP. Проверьте окружение.');
        }

        $parameters = [
            ':enabled' => $enabled ? 1 : 0,
            ':config' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ':secret' => $secret === '' ? null : $secret,
        ];
        if ($prior !== null) {
            $this->db->execute(
                'UPDATE notification_channel_configs
                 SET enabled=:enabled,config_json=:config,secret_ref=:secret
                 WHERE id=:id',
                $parameters + [':id' => (int) $prior['id']]
            );
            return;
        }

        $this->db->execute(
            'INSERT INTO notification_channel_configs
             (channel,owner_user_id,owner_scope,label,enabled,config_json,secret_ref)
             VALUES (:channel,:owner,:scope,:label,:enabled,:config,:secret)',
            $parameters + [
                ':channel' => $channel,
                ':owner' => $owner,
                ':scope' => $scope,
                ':label' => $label,
            ]
        );
    }

    private function secretAvailable(string $reference): bool
    {
        if ($reference === '') {
            return false;
        }
        try {
            $secret = (new EnvironmentNotificationSecretResolver())->resolve($reference);
            return is_string($secret) && $secret !== '';
        } catch (\Throwable) {
            return false;
        }
    }
}
