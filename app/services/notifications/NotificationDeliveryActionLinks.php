<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once __DIR__ . '/NotificationActionTokenStore.php';

use Core\DatabaseManager;

/**
 * Выдаёт ссылку только персональному получателю при фактической отправке.
 * Ни URL, ни токен не записываются в очередь, журнал и коды результатов.
 */
final class NotificationDeliveryActionLinks
{
    public function __construct(
        private NotificationActionTokenStore $tokens,
        private string $siteUrl
    ) {
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    public function decorate(array $job): array
    {
        $channel = (string) ($job['channel'] ?? '');
        if (!in_array($channel, ['email', 'browser', 'telegram', 'max', 'sms'], true)
            || ($job['source_name'] ?? '') !== 'tasks'
            || !str_starts_with((string) ($job['event_name'] ?? ''), 'tasks.')
            || ($job['recipient_eligible'] ?? false) !== true
            || ($job['channel_enabled'] ?? false) !== true) {
            return $job;
        }

        $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
        $uid = is_array($payload) ? ($payload['task_uid'] ?? null) : null;
        $userId = (int) ($job['recipient_user_id'] ?? 0);
        $notificationId = (int) ($job['notification_id'] ?? 0);
        $base = self::httpsBase($this->siteUrl);
        if (!is_string($uid) || preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1
            || $userId <= 0 || $notificationId <= 0 || $base === null) {
            return $job;
        }

        $token = $this->tokens->issue($userId, $notificationId, 'complete_task');
        $job['action_url'] = $base . '/tasks/notification/' . $notificationId
            . '/preview#token=' . $token;
        return $job;
    }

    /** Адрес установки должен быть доверенным HTTPS-origin без query и fragment. */
    public static function httpsBase(string $siteUrl): ?string
    {
        $parts = parse_url(trim($siteUrl));
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user'], $parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || strlen($siteUrl) > 1000) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        return rtrim($siteUrl, '/');
    }
}
