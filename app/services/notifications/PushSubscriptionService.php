<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once dirname(__DIR__, 3) . '/core/DatabaseManager.php';
require_once __DIR__ . '/WebPushCrypto.php';

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Привязки браузерных устройств принадлежат конкретному пользователю.
 * Push endpoint является секретным адресом: не выдаём его в списках и журналах.
 */
final class PushSubscriptionService
{
    private const MAX_DEVICES = 5;

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @param array<string,mixed> $data @return array{id:int,active:int,endpoint_hash:string} */
    public function subscribe(int $userId, array $data): array
    {
        self::assertUser($userId);
        $endpoint = self::endpoint($data['endpoint'] ?? null);
        $keys = $data['keys'] ?? null;
        if (!is_array($keys) || array_is_list($keys)) {
            throw new InvalidArgumentException('Отсутствуют ключи браузерной подписки');
        }
        $p256dh = self::key($keys['p256dh'] ?? null, 65);
        $auth = self::key($keys['auth'] ?? null, 16);
        WebPushCrypto::publicFromBytes($p256dh);
        $digest = hash('sha256', $endpoint, true);
        $label = self::label($digest);

        $this->db->beginTransaction();
        try {
            $current = $this->db->fetchOne(
                'SELECT id,user_id FROM notification_push_subscriptions
                 WHERE endpoint_hash=:digest LIMIT 1 FOR UPDATE',
                [':digest' => $digest]
            );
            if ($current !== null && (int) $current['user_id'] !== $userId) {
                throw new DomainException('Браузер привязан к другой учётной записи. Удалите прежнюю подписку в настройках устройства.');
            }
            if ($current === null) {
                $count = (int) $this->db->fetchValue(
                    'SELECT COUNT(*) FROM notification_push_subscriptions
                     WHERE user_id=:uid AND revoked_at IS NULL',
                    [':uid' => $userId]
                );
                if ($count >= self::MAX_DEVICES) {
                    throw new DomainException('Достигнут предел пяти устройств. Отключите неиспользуемое устройство.');
                }
                $this->db->execute(
                    'INSERT INTO notification_push_subscriptions
                     (user_id,endpoint_hash,endpoint,p256dh,auth)
                     VALUES (:user,:digest,:endpoint,:pub,:auth)',
                    [
                        ':user' => $userId, ':digest' => $digest, ':endpoint' => $endpoint,
                        ':pub' => WebPushCrypto::base64url($p256dh),
                        ':auth' => WebPushCrypto::base64url($auth),
                    ]
                );
                $id = (int) $this->db->getPdo()->lastInsertId();
            } else {
                $id = (int) $current['id'];
                $this->db->execute(
                    'UPDATE notification_push_subscriptions
                     SET p256dh=:pub,auth=:auth,revoked_at=NULL,last_seen_at=UTC_TIMESTAMP()
                     WHERE id=:id AND user_id=:uid',
                    [
                        ':pub' => WebPushCrypto::base64url($p256dh),
                        ':auth' => WebPushCrypto::base64url($auth),
                        ':id' => $id, ':uid' => $userId,
                    ]
                );
            }

            $this->db->execute(
                'INSERT INTO notification_channel_configs
                 (channel,owner_user_id,owner_scope,label,enabled,config_json)
                 VALUES (\'browser\',:uid,:scope,:label,1,:config)
                 ON DUPLICATE KEY UPDATE enabled=1,config_json=VALUES(config_json)',
                [
                    ':uid' => $userId, ':scope' => $userId, ':label' => $label,
                    ':config' => json_encode(['subscription_id' => $id], JSON_THROW_ON_ERROR),
                ]
            );
            $count = (int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM notification_push_subscriptions
                 WHERE user_id=:uid AND revoked_at IS NULL',
                [':uid' => $userId]
            );
            $this->db->endTransaction(true);
        } catch (Throwable $error) {
            $this->db->endTransaction(false);
            throw $error;
        }

        return ['id' => $id, 'active' => $count, 'endpoint_hash' => bin2hex($digest)];
    }

    /** @return array{active:int,current:bool} */
    public function status(int $userId, ?string $endpoint = null): array
    {
        self::assertUser($userId);
        $active = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM notification_push_subscriptions
             WHERE user_id=:uid AND revoked_at IS NULL',
            [':uid' => $userId]
        );
        $current = false;
        if (is_string($endpoint) && $endpoint !== '') {
            $digest = hash('sha256', self::endpoint($endpoint), true);
            $current = (int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM notification_push_subscriptions
                 WHERE user_id=:uid AND endpoint_hash=:digest AND revoked_at IS NULL',
                [':uid' => $userId, ':digest' => $digest]
            ) > 0;
        }
        return ['active' => $active, 'current' => $current];
    }

    public function revoke(int $userId, string $endpoint): bool
    {
        self::assertUser($userId);
        return $this->revokeByHash($userId, hash('sha256', self::endpoint($endpoint)));
    }

    public function revokeByHash(int $userId, string $hex): bool
    {
        self::assertUser($userId);
        if (preg_match('/^[a-f0-9]{64}$/', $hex) !== 1) {
            throw new InvalidArgumentException('Неверный идентификатор устройства');
        }
        $digest = hex2bin($hex);
        if (!is_string($digest)) {
            throw new InvalidArgumentException('Неверный идентификатор устройства');
        }
        $this->db->beginTransaction();
        try {
            $row = $this->db->fetchOne(
                'SELECT id FROM notification_push_subscriptions
                 WHERE user_id=:uid AND endpoint_hash=:digest AND revoked_at IS NULL
                 LIMIT 1 FOR UPDATE',
                [':uid' => $userId, ':digest' => $digest]
            );
            if ($row === null) {
                $this->db->endTransaction(true);
                return false;
            }
            $this->db->execute(
                'UPDATE notification_push_subscriptions
                 SET revoked_at=UTC_TIMESTAMP() WHERE id=:id',
                [':id' => (int) $row['id']]
            );
            $this->db->execute(
                "UPDATE notification_channel_configs SET enabled=0
                 WHERE channel='browser' AND owner_user_id=:uid AND label=:label",
                [':uid' => $userId, ':label' => self::label($digest)]
            );
            $this->db->endTransaction(true);
            return true;
        } catch (Throwable $error) {
            $this->db->endTransaction(false);
            throw $error;
        }
    }

    /** @return array{endpoint:string,p256dh:string,auth:string,id:int}|null */
    public function subscriptionForJob(int $userId, int $configId): ?array
    {
        self::assertUser($userId);
        $row = $this->db->fetchOne(
            "SELECT s.id,s.endpoint,s.p256dh,s.auth
             FROM notification_channel_configs c
             JOIN notification_push_subscriptions s
               ON s.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(c.config_json,'$.subscription_id')) AS UNSIGNED)
              AND s.user_id=c.owner_user_id
             WHERE c.id=:cid AND c.channel='browser' AND c.enabled=1
               AND c.owner_user_id=:uid AND s.revoked_at IS NULL LIMIT 1",
            [':cid' => $configId, ':uid' => $userId]
        );
        if ($row === null) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'endpoint' => (string) $row['endpoint'],
            'p256dh' => (string) $row['p256dh'],
            'auth' => (string) $row['auth'],
        ];
    }

    public function revokeId(int $userId, int $id): void
    {
        self::assertUser($userId);
        $row = $this->db->fetchOne(
            'SELECT HEX(endpoint_hash) AS digest FROM notification_push_subscriptions
             WHERE id=:id AND user_id=:uid',
            [':id' => $id, ':uid' => $userId]
        );
        if ($row !== null) {
            $this->revokeByHash($userId, strtolower((string) $row['digest']));
        }
    }

    public static function endpoint(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 2048) {
            throw new InvalidArgumentException('Некорректный адрес Push-подписки');
        }
        $parts = parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || !is_string($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || isset($parts['port'])) {
            throw new InvalidArgumentException('Push-подписка допускает только HTTPS');
        }
        $host = strtolower((string) $parts['host']);
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || strlen($host) > 253 || $host === 'localhost' || str_ends_with($host, '.local')) {
            throw new InvalidArgumentException('Адрес Push-сервиса не разрешён');
        }
        return $value;
    }

    private static function key(mixed $value, int $size): string
    {
        if (!is_string($value) || strlen($value) > 160) {
            throw new InvalidArgumentException('Некорректный ключ браузера');
        }
        $bytes = WebPushCrypto::decode64($value);
        if (strlen($bytes) !== $size || ($size === 65 && $bytes[0] !== "\x04")) {
            throw new InvalidArgumentException('Неверный размер ключа Push-подписки');
        }
        return $bytes;
    }

    private static function label(string $digest): string
    {
        return 'Браузер-' . substr(bin2hex($digest), 0, 24);
    }

    private static function assertUser(int $userId): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Отсутствует активный пользователь');
        }
    }
}
