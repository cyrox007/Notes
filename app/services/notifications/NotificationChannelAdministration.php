<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once dirname(__DIR__, 3) . '/core/DatabaseManager.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/NotificationEvent.php';
require_once __DIR__ . '/DatabaseNotificationPreferences.php';
require_once __DIR__ . '/NotificationDeliveryRuntime.php';
require_once __DIR__ . '/PushSubscriptionService.php';

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;

final class NotificationChannelAdministration
{
    private const EMAIL_LABEL = 'Основной SMTP';
    private const TEST_CATEGORY = 'diagnostics';

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $config = $this->smtpConfig();
        $jobs = $this->db->fetchAll(
            'SELECT j.id, j.state, j.channel, j.attempts, j.last_result_code,
                    j.created_at, j.updated_at, j.sent_at,
                    n.title
             FROM notification_delivery_jobs j
             JOIN notifications n ON n.id = j.notification_id
             ORDER BY j.id DESC LIMIT 20'
        );
        // Последние попытки всех каналов: только диагностические коды и
        // состояния, без адресов, текстов уведомлений и секретов транспорта.
        $attempts = $this->db->fetchAll(
            'SELECT a.job_id, j.channel, a.attempt_no, a.result_state,
                    a.result_code, a.attempted_at
             FROM notification_delivery_attempts a
             INNER JOIN notification_delivery_jobs j ON j.id = a.job_id
             ORDER BY a.id DESC LIMIT 40'
        );
        $count = $this->db->fetchAll(
            'SELECT channel,state,COUNT(*) AS total FROM notification_delivery_jobs
             GROUP BY channel,state ORDER BY channel,state'
        );
        $runtime = $this->db->fetchOne(
            "SELECT last_http_run_at FROM notification_runtime_state
             WHERE state_key = 'delivery_http_fallback' LIMIT 1"
        );
        return [
            'smtp' => $config === null ? null : [
                'enabled' => (int) $config['enabled'] === 1,
                'settings' => json_decode((string) $config['config_json'], true) ?: [],
                'secret_configured' => trim((string) ($config['secret_ref'] ?? '')) !== '',
                'secret_available' => $this->secretAvailable((string) ($config['secret_ref'] ?? '')),
            ],
            'jobs' => $jobs,
            'attempts' => $attempts,
            'counts' => $count,
            'last_http_run' => $runtime['last_http_run_at'] ?? null,
        ];
    }

    /** @param array<string,mixed> $input */
    public function saveSmtp(array $input): void
    {
        $settings = self::validateSmtp($input);
        $reference = trim((string) ($input['secret_ref'] ?? ''));
        if ($reference !== '' && preg_match('/^env:[A-Z][A-Z0-9_]{1,127}$/', $reference) !== 1) {
            throw new InvalidArgumentException('Секрет задаётся только ссылкой env:ИМЯ_ПЕРЕМЕННОЙ');
        }

        $existing = $this->smtpConfig();
        if ($reference === '' && $existing !== null) {
            $reference = (string) ($existing['secret_ref'] ?? '');
        }
        if ($settings['username'] !== '' && $reference === '') {
            throw new InvalidArgumentException('Для SMTP с авторизацией нужна ссылка на секрет');
        }
        if ($settings['username'] !== '' && !$this->secretAvailable($reference)) {
            throw new InvalidArgumentException('Указанный SMTP-секрет недоступен в окружении PHP');
        }
        $enabled = $input['enabled'] ?? false;
        if (!is_bool($enabled)) {
            throw new InvalidArgumentException('Состояние канала должно быть логическим');
        }

        $params = [
            ':enabled' => $enabled ? 1 : 0,
            ':config_json' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ':secret_ref' => $reference === '' ? null : $reference,
        ];
        if ($existing !== null) {
            $this->db->execute(
                'UPDATE notification_channel_configs
                 SET enabled = :enabled, config_json = :config_json, secret_ref = :secret_ref
                 WHERE id = :id AND channel = \'email\' AND owner_user_id IS NULL',
                $params + [':id' => (int) $existing['id']]
            );
            return;
        }
        $this->db->execute(
            'INSERT INTO notification_channel_configs
             (channel, owner_user_id, owner_scope, label, enabled, config_json, secret_ref)
             VALUES (\'email\', NULL, 0, :label, :enabled, :config_json, :secret_ref)',
            $params + [':label' => self::EMAIL_LABEL]
        );
    }

    /**
     * Пробная отправка идёт через штатный сервис и очередь; сообщение
     * доставляется только на подтверждённый адрес текущего администратора.
     *
     * @return array{event_id:int,job_id:int,state:string,attempts:int,last_result_code:?string}
     */
    public function testSmtp(int $actorId): array
    {
        return $this->testChannel($actorId, 'email');
    }

    /**
     * Все тестовые каналы, включая устройства Web Push, проходят штатную очередь.
     * Запрос проверяет только устройство и не доказывает показ сообщения.
     * Сменяются только подписки на отдельную диагностическую категорию.
     *
     * @return array{event_id:int,job_id:int,state:string,attempts:int,last_result_code:?string}
     */
    public function testChannel(int $actorId, string $channel): array
    {
        if ($actorId <= 0 || !in_array($channel, ['email', 'browser', 'telegram', 'max', 'webhook', 'sms'], true)) {
            throw new InvalidArgumentException('Недопустимый канал или получатель');
        }

        if ($channel === 'email') {
            $config = $this->smtpConfig();
            if ($config === null || (int) $config['enabled'] !== 1) {
                throw new DomainException('SMTP отключён или не настроен');
            }
            $recipient = (new DatabaseNotificationEmailRecipientResolver($this->db))->emailForUser($actorId);
            if ($recipient === null) {
                throw new DomainException('У администратора нет доступного E-mail адреса');
            }
        } else {
            // Browser Push допускается только для реально привязанных
            // устройств текущего администратора. Чужие подписки не используются.
            if ($channel === 'browser') {
                $activeDevices = (new PushSubscriptionService($this->db))->status($actorId);
                if (($activeDevices['active'] ?? 0) < 1) {
                    throw new DomainException('Сначала разрешите Push и привяжите браузер в настройках уведомлений');
                }
            }
            $config = $this->db->fetchOne(
                'SELECT id FROM notification_channel_configs
                 WHERE channel=:channel AND enabled=1
                   AND ((:is_webhook=0 AND owner_user_id = :actor)
                     OR (:is_webhook=1 AND owner_user_id IS NULL))
                 ORDER BY owner_user_id IS NULL ASC,id ASC LIMIT 1',
                [
                    ':channel' => $channel,
                    ':actor' => $actorId,
                    ':is_webhook' => $channel === 'webhook' ? 1 : 0,
                ]
            );
            if ($config === null) {
                throw new DomainException('Сначала настройте доступного получателя выбранного канала');
            }
        }

        $eventName = 'notifications.' . ($channel === 'email' ? 'smtp' : $channel) . '_test';
        $last = $this->db->fetchValue(
            'SELECT MAX(e.created_at)
             FROM notification_events e JOIN notifications n ON n.event_id=e.id
             WHERE n.recipient_user_id=:actor AND e.event_name=:event',
            [':actor' => $actorId, ':event' => $eventName]
        );
        if (is_string($last) && strtotime($last . ' UTC') > time() - 60) {
            throw new DomainException('Пробный запрос доступен один раз в минуту для каждого канала');
        }

        $channels = array_fill_keys(['email', 'browser', 'telegram', 'max', 'webhook', 'sms'], false);
        $channels[$channel] = true;
        (new DatabaseNotificationPreferences($this->db))->updateForUser($actorId, [
            'category' => self::TEST_CATEGORY,
            'external_enabled' => true,
            'channels' => $channels,
        ]);

        $published = (new NotificationService(new DatabaseNotificationStore($this->db)))->publish(
            new NotificationEvent(
                name: $eventName,
                version: 1,
                idempotencyKey: 'test-' . $channel . ':' . bin2hex(random_bytes(16)),
                source: 'core',
                category: self::TEST_CATEGORY,
                importance: 'normal',
                title: 'Проверка уведомлений Notes',
                body: 'Пробное уведомление из админпанели доставляется штатной очередью.',
                recipientUserIds: [$actorId],
                targetPath: '/admin/notifications'
            )
        );

        $job = $this->jobForEvent((int) $published['event_id'], $actorId, $channel);
        if ($job === null) {
            throw new DomainException('Событие записано, но задание не создано. Проверьте подписку и получателя.');
        }

        // Не блокируем HTTP ожиданием всей очереди: один ограниченный проход.
        (new NotificationDeliveryRuntime($this->db))->run(
            'notifications-test-' . $actorId . '-' . bin2hex(random_bytes(4)), 1
        );
        $job = $this->jobForEvent((int) $published['event_id'], $actorId, $channel);
        return [
            'event_id' => (int) $published['event_id'],
            'job_id' => (int) $job['id'],
            'state' => (string) $job['state'],
            'attempts' => (int) $job['attempts'],
            'last_result_code' => $job['last_result_code'] ?? null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function jobForEvent(int $eventId, int $actorId, string $channel): ?array
    {
        return $this->db->fetchOne(
            'SELECT j.id,j.state,j.attempts,j.last_result_code
             FROM notification_delivery_jobs j
             JOIN notifications n ON n.id=j.notification_id
             WHERE n.event_id=:event AND n.recipient_user_id=:actor
               AND j.channel=:channel ORDER BY j.id DESC LIMIT 1',
            [':event' => $eventId, ':actor' => $actorId, ':channel' => $channel]
        );
    }

    /** @return array<string,mixed>|null */
    private function smtpConfig(): ?array
    {
        return $this->db->fetchOne(
            'SELECT id,enabled,config_json,secret_ref FROM notification_channel_configs
             WHERE channel = :channel AND owner_user_id IS NULL
             ORDER BY id ASC LIMIT 1',
            [':channel' => 'email']
        );
    }

    private function secretAvailable(string $reference): bool
    {
        if ($reference === '') {
            return false;
        }
        try {
            return (new EnvironmentNotificationSecretResolver())->resolve($reference) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function validateSmtp(array $input): array
    {
        $host = trim((string) ($input['host'] ?? ''));
        $port = filter_var($input['port'] ?? null, FILTER_VALIDATE_INT);
        $encryption = (string) ($input['encryption'] ?? '');
        $user = trim((string) ($input['username'] ?? ''));
        $sender = trim((string) ($input['from_email'] ?? ''));
        $fromName = trim((string) ($input['from_name'] ?? 'Notes'));
        if ($host === '' || strlen($host) > 253
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}$/', $host) !== 1) {
            throw new InvalidArgumentException('Укажите корректный хост SMTP');
        }
        if ($port === false || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Некорректный порт SMTP');
        }
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true) || ($user !== '' && $encryption === 'none')) {
            throw new InvalidArgumentException('При SMTP-авторизации необходимо защищённое соединение');
        }
        if (filter_var($sender, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Неверный адрес отправителя');
        }
        if (strlen($user) > 190 || strlen($fromName) > 190
            || strpbrk($user . $fromName, "\r\n\0") !== false) {
            throw new InvalidArgumentException('Недопустимые символы в настройках SMTP');
        }
        return [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => $user,
            'from_email' => $sender,
            'from_name' => $fromName,
            'timeout_seconds' => 10,
            'client_name' => 'notes.local',
        ];
    }
}
