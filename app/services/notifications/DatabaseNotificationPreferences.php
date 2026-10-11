<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once dirname(__DIR__, 3) . '/core/DatabaseManager.php';
require_once __DIR__ . '/NotificationEmailRecipientResolver.php';
require_once __DIR__ . '/DatabaseNotificationEmailRecipientResolver.php';

use Core\DatabaseManager;
use InvalidArgumentException;
use Throwable;

final class DatabaseNotificationPreferences
{
    /** @var array<string,string> */
    private const CHANNEL_COLUMNS = [
        'email' => 'email_enabled',
        'browser' => 'browser_enabled',
        'telegram' => 'telegram_enabled',
        'webhook' => 'webhook_enabled',
        'sms' => 'sms_enabled',
    ];

    /** @var array<string,true> */
    private const SUPPORTED_CHANNELS = [
        'email' => true,
    ];

    private DatabaseManager $db;
    private NotificationEmailRecipientResolver $emailRecipients;

    public function __construct(
        ?DatabaseManager $db = null,
        ?NotificationEmailRecipientResolver $emailRecipients = null
    ) {
        $this->db = $db ?? DatabaseManager::getInstance();
        $this->emailRecipients = $emailRecipients ?? new DatabaseNotificationEmailRecipientResolver($this->db);
    }

    /**
     * @return array{
     *   defaults:array{in_app_enabled:bool,external_enabled:bool,channels:array<string,bool>},
     *   channels:array<string,array{supported:bool,configured:bool,destination_ready:bool,available:bool}>,
     *   subscriptions:list<array<string,mixed>>
     * }
     */
    public function summaryForUser(int $userId): array
    {
        $this->assertEligibleUser($userId);
        $availability = $this->channelAvailability($userId);
        $rows = $this->subscriptionRows($userId);
        $categories = $this->observedCategories($userId);
        $subscriptions = [];

        foreach ($categories as $category) {
            $subscriptions[] = $this->subscriptionState(
                $category,
                $rows[$category] ?? null,
                $availability
            );
        }

        return [
            'defaults' => $this->defaultState(),
            'channels' => $availability,
            'subscriptions' => $subscriptions,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{
     *   subscription:array<string,mixed>,
     *   channels:array<string,array{supported:bool,configured:bool,destination_ready:bool,available:bool}>
     * }
     */
    public function updateForUser(int $userId, array $payload): array
    {
        $this->assertEligibleUser($userId);
        $this->assertPayloadKeys($payload);
        $category = $this->category($payload['category'] ?? null);

        $hasExternalChange = array_key_exists('external_enabled', $payload);
        $hasChannelChange = array_key_exists('channels', $payload);
        if (!$hasExternalChange && !$hasChannelChange) {
            throw new InvalidArgumentException('Нужно передать external_enabled или channels');
        }

        $channelPatch = $hasChannelChange
            ? $this->channelPatch($payload['channels'])
            : [];

        $this->db->beginTransaction();
        try {
            $current = $this->subscriptionRowForUpdate($userId, $category);
            $state = $this->mutableState($current);

            if ($hasExternalChange) {
                $state['external_enabled'] = $this->boolean($payload['external_enabled'], 'external_enabled');
            }

            foreach ($channelPatch as $channel => $enabled) {
                $state['channels'][$channel] = $enabled;
            }

            if (!$hasExternalChange && $channelPatch !== [] && in_array(true, $channelPatch, true)) {
                $state['external_enabled'] = true;
            }

            foreach (array_keys(self::CHANNEL_COLUMNS) as $channel) {
                if (!isset(self::SUPPORTED_CHANNELS[$channel])) {
                    $state['channels'][$channel] = false;
                }
            }

            $this->persist($userId, $category, $state);
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }

        $availability = $this->channelAvailability($userId);
        $row = [
            'enabled' => $state['external_enabled'] ? 1 : 0,
            'in_app_enabled' => 1,
        ];
        foreach (self::CHANNEL_COLUMNS as $channel => $column) {
            $row[$column] = $state['channels'][$channel] ? 1 : 0;
        }

        return [
            'subscription' => $this->subscriptionState($category, $row, $availability),
            'channels' => $availability,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function subscriptionRows(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT category, enabled, in_app_enabled, email_enabled, browser_enabled, '
            . 'telegram_enabled, webhook_enabled, sms_enabled '
            . 'FROM notification_subscriptions '
            . 'WHERE user_id = :user_id '
            . 'ORDER BY category ASC',
            [':user_id' => $userId]
        );

        $indexed = [];
        foreach ($rows as $row) {
            $category = (string) ($row['category'] ?? '');
            if ($category !== '') {
                $indexed[$category] = $row;
            }
        }
        return $indexed;
    }

    /** @return list<string> */
    private function observedCategories(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT s.category AS category '
            . 'FROM notification_subscriptions s '
            . 'WHERE s.user_id = :subscription_user_id '
            . 'UNION '
            . 'SELECT e.category AS category '
            . 'FROM notifications n '
            . 'INNER JOIN notification_events e ON e.id = n.event_id '
            . 'WHERE n.recipient_user_id = :notification_user_id '
            . 'ORDER BY category ASC',
            [
                ':subscription_user_id' => $userId,
                ':notification_user_id' => $userId,
            ]
        );

        $categories = [];
        foreach ($rows as $row) {
            $category = trim((string) ($row['category'] ?? ''));
            if ($category !== '') {
                $categories[] = $category;
            }
        }
        return $categories;
    }

    /** @return array<string,mixed>|null */
    private function subscriptionRowForUpdate(int $userId, string $category): ?array
    {
        return $this->db->fetchOne(
            'SELECT enabled, in_app_enabled, email_enabled, browser_enabled, telegram_enabled, webhook_enabled, sms_enabled '
            . 'FROM notification_subscriptions '
            . 'WHERE user_id = :user_id AND category = :category '
            . 'FOR UPDATE',
            [':user_id' => $userId, ':category' => $category]
        );
    }

    /** @return array{external_enabled:bool,channels:array<string,bool>} */
    private function mutableState(?array $row): array
    {
        $channels = [];
        foreach (self::CHANNEL_COLUMNS as $channel => $column) {
            $channels[$channel] = (int) ($row[$column] ?? 0) === 1;
        }

        return [
            'external_enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'channels' => $channels,
        ];
    }

    /**
     * @param array{external_enabled:bool,channels:array<string,bool>} $state
     */
    private function persist(int $userId, string $category, array $state): void
    {
        // В 1.1 встроенный центр остаётся основным и не отключается пользовательской
        // подпиской. Поле enabled управляет только внешней доставкой по категории.
        $this->db->execute(
            'INSERT INTO notification_subscriptions ('
            . 'user_id, category, enabled, in_app_enabled, email_enabled, browser_enabled, '
            . 'telegram_enabled, webhook_enabled, sms_enabled'
            . ') VALUES ('
            . ':user_id, :category, :enabled, 1, :email_enabled, :browser_enabled, '
            . ':telegram_enabled, :webhook_enabled, :sms_enabled'
            . ') ON DUPLICATE KEY UPDATE '
            . 'enabled = VALUES(enabled), '
            . 'in_app_enabled = 1, '
            . 'email_enabled = VALUES(email_enabled), '
            . 'browser_enabled = VALUES(browser_enabled), '
            . 'telegram_enabled = VALUES(telegram_enabled), '
            . 'webhook_enabled = VALUES(webhook_enabled), '
            . 'sms_enabled = VALUES(sms_enabled)',
            [
                ':user_id' => $userId,
                ':category' => $category,
                ':enabled' => $state['external_enabled'] ? 1 : 0,
                ':email_enabled' => $state['channels']['email'] ? 1 : 0,
                ':browser_enabled' => $state['channels']['browser'] ? 1 : 0,
                ':telegram_enabled' => $state['channels']['telegram'] ? 1 : 0,
                ':webhook_enabled' => $state['channels']['webhook'] ? 1 : 0,
                ':sms_enabled' => $state['channels']['sms'] ? 1 : 0,
            ]
        );
    }

    /** @return array<string,array{supported:bool,configured:bool,destination_ready:bool,available:bool}> */
    private function channelAvailability(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT channel, owner_user_id '
            . 'FROM notification_channel_configs '
            . 'WHERE enabled = 1 '
            . 'AND (owner_user_id IS NULL OR owner_user_id = :user_id) '
            . 'ORDER BY channel ASC, owner_user_id IS NULL ASC, id ASC',
            [':user_id' => $userId]
        );

        $configured = [];
        foreach ($rows as $row) {
            $channel = (string) ($row['channel'] ?? '');
            if (isset(self::CHANNEL_COLUMNS[$channel])) {
                $configured[$channel] = true;
            }
        }

        $emailReady = $this->emailRecipients->emailForUser($userId) !== null;
        $availability = [];
        foreach (array_keys(self::CHANNEL_COLUMNS) as $channel) {
            $supported = isset(self::SUPPORTED_CHANNELS[$channel]);
            $destinationReady = $channel === 'email' ? $emailReady : false;
            $isConfigured = isset($configured[$channel]);
            $availability[$channel] = [
                'supported' => $supported,
                'configured' => $isConfigured,
                'destination_ready' => $destinationReady,
                'available' => $supported && $isConfigured && $destinationReady,
            ];
        }

        return $availability;
    }

    /**
     * @param array<string,mixed>|null $row
     * @param array<string,array{supported:bool,configured:bool,destination_ready:bool,available:bool}> $availability
     * @return array<string,mixed>
     */
    private function subscriptionState(string $category, ?array $row, array $availability): array
    {
        $externalEnabled = (int) ($row['enabled'] ?? 0) === 1;
        $channels = [];
        $effective = [];

        foreach (self::CHANNEL_COLUMNS as $channel => $column) {
            $enabled = (int) ($row[$column] ?? 0) === 1;
            if (!isset(self::SUPPORTED_CHANNELS[$channel])) {
                $enabled = false;
            }
            $channels[$channel] = $enabled;
            $effective[$channel] = $externalEnabled
                && $enabled
                && (bool) ($availability[$channel]['available'] ?? false);
        }

        return [
            'category' => $category,
            'in_app_enabled' => true,
            'external_enabled' => $externalEnabled,
            'channels' => $channels,
            'effective_channels' => $effective,
        ];
    }

    /** @return array{in_app_enabled:bool,external_enabled:bool,channels:array<string,bool>} */
    private function defaultState(): array
    {
        return [
            'in_app_enabled' => true,
            'external_enabled' => false,
            'channels' => array_fill_keys(array_keys(self::CHANNEL_COLUMNS), false),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function assertPayloadKeys(array $payload): void
    {
        $allowed = ['category' => true, 'external_enabled' => true, 'channels' => true];
        foreach (array_keys($payload) as $key) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidArgumentException('Запрос содержит неизвестное поле настроек уведомлений');
            }
        }
    }

    /** @return array<string,bool> */
    private function channelPatch(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('channels должен быть объектом с переключателями каналов');
        }

        $patch = [];
        foreach ($value as $channel => $enabled) {
            if (!is_string($channel) || !isset(self::CHANNEL_COLUMNS[$channel])) {
                throw new InvalidArgumentException('Неизвестный канал уведомлений');
            }
            $enabled = $this->boolean($enabled, 'channels.' . $channel);
            if ($enabled && !isset(self::SUPPORTED_CHANNELS[$channel])) {
                throw new InvalidArgumentException('Канал ' . $channel . ' пока не поддерживается этой версией');
            }
            $patch[$channel] = $enabled;
        }
        return $patch;
    }

    private function category(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('category должен быть строкой');
        }

        $category = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($category) : strlen($category);
        if ($category === '' || $length > 80 || preg_match('/[\x00-\x1F\x7F]/u', $category) === 1) {
            throw new InvalidArgumentException('Категория уведомлений имеет недопустимое значение');
        }
        return $category;
    }

    private function boolean(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException($field . ' должен быть логическим значением');
        }
        return $value;
    }

    private function assertEligibleUser(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Идентификатор пользователя должен быть положительным');
        }

        $exists = $this->db->fetchValue(
            'SELECT id FROM users '
            . 'WHERE id = :user_id '
            . 'AND is_active = 1 '
            . "AND account_status = 'active' "
            . 'AND deletion_requested_at IS NULL '
            . 'AND anonymized_at IS NULL '
            . 'LIMIT 1',
            [':user_id' => $userId]
        );
        if ($exists === null) {
            throw new InvalidArgumentException('Пользователь недоступен для настройки уведомлений');
        }
    }
}
