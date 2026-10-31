<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * Непрозрачный транспорт для открытых ключей и пакетов Olm.
 * Никаких секретных ключей и расшифровки здесь нет.
 * Этот транспорт без проверенного клиентского движка НЕ является готовым E2EE.
 */
final class MessengerE2eeRelayService
{
    private const MAX_DEVICES = 12;
    private const MAX_PREKEYS = 100;
    private const MAX_MAILBOX = 500;
    private const MAX_ENVELOPE_BYTES = 32768;

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @return list<array<string,mixed>> */
    public function devices(int $actor, string $targetUid): array
    {
        $target = $this->targetUser($actor, $targetUid);
        $rows = $this->db->fetchAll(
            'SELECT device_id, signing_key, agreement_key, identity_json, created_at
             FROM messenger_e2ee_devices
             WHERE user_id = :uid AND revoked_at IS NULL ORDER BY id ASC LIMIT 20',
            [':uid' => $target]
        );
        return array_map(static fn (array $row): array => [
            'device_id' => (string) $row['device_id'],
            'signing_key' => (string) $row['signing_key'],
            'agreement_key' => (string) $row['agreement_key'],
            'identity' => json_decode((string) $row['identity_json'], true, 32, JSON_THROW_ON_ERROR),
            'created_at' => (string) $row['created_at'],
            'verified' => false,
        ], $rows);
    }

    /** @param array<string,mixed> $identity */
    public function register(int $actor, string $deviceId, array $identity): void
    {
        self::validateDeviceId($deviceId);
        $signing = self::publicKey($identity['signing_key'] ?? null);
        $agreement = self::publicKey($identity['agreement_key'] ?? null);
        if ($signing === $agreement) {
            throw new InvalidArgumentException('Ключи подписи и обмена должны различаться');
        }
        $encoded = json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 8192) {
            throw new InvalidArgumentException('Слишком большой открытый пакет идентичности');
        }
        $this->db->beginTransaction();
        try {
            // Сериализует одновременную регистрацию нескольких устройств одного владельца.
            $owner = $this->db->fetchOne('SELECT id FROM users WHERE id = :id FOR UPDATE', [':id' => $actor]);
            if ($owner === null) {
                throw new DomainException('Пользователь недоступен', 403);
            }
            $existing = $this->db->fetchOne(
                'SELECT id FROM messenger_e2ee_devices WHERE user_id = :uid AND device_id = :did FOR UPDATE',
                [':uid' => $actor, ':did' => $deviceId]
            );
            if ($existing !== null) {
                throw new DomainException('Повторное использование идентификатора устройства запрещено', 409);
            }
            $count = (int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM messenger_e2ee_devices WHERE user_id = :uid AND revoked_at IS NULL',
                [':uid' => $actor]
            );
            if ($count >= self::MAX_DEVICES) {
                throw new DomainException('Превышен предел доверенных устройств', 409);
            }
            $this->db->execute(
                'INSERT INTO messenger_e2ee_devices
                 (user_id, device_id, signing_key, agreement_key, identity_json)
                 VALUES (:uid, :did, :signing, :agreement, :identity)',
                [':uid' => $actor, ':did' => $deviceId,
                 ':signing' => $signing, ':agreement' => $agreement, ':identity' => $encoded]
            );
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
    }

    /** @param array<string,mixed> $keys */
    public function publishPrekeys(int $actor, string $deviceId, array $keys): int
    {
        self::validateDeviceId($deviceId);
        if (count($keys) < 1 || count($keys) > 50 || array_is_list($keys)) {
            throw new InvalidArgumentException('Нужен объект с 1–50 одноразовыми ключами');
        }
        $this->db->beginTransaction();
        try {
            $this->ownDevice($actor, $deviceId, true);
            $count = (int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM messenger_e2ee_prekeys
                 WHERE user_id = :uid AND device_id = :did AND claimed_at IS NULL',
                [':uid' => $actor, ':did' => $deviceId]
            );
            if ($count + count($keys) > self::MAX_PREKEYS) {
                throw new DomainException('Превышен предел одноразовых ключей', 409);
            }
            foreach ($keys as $keyId => $item) {
                if (!is_string($keyId)
                    || preg_match('/^signed_curve25519:[A-Za-z0-9_-]{1,64}$/D', $keyId) !== 1
                    || !is_array($item) || !isset($item['key'])
                    || !is_string($item['key']) || strlen($item['key']) > 200) {
                    throw new InvalidArgumentException('Неверный формат подписанного одноразового ключа');
                }
                self::publicKey($item['key']);
                $value = json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                if (strlen($value) > 2048) {
                    throw new InvalidArgumentException('Слишком большой одноразовый ключ');
                }
                $this->db->execute(
                    'INSERT INTO messenger_e2ee_prekeys (user_id, device_id, key_id, key_json)
                     VALUES (:uid, :did, :kid, :item)',
                    [':uid' => $actor, ':did' => $deviceId, ':kid' => $keyId, ':item' => $value]
                );
            }
            $this->db->endTransaction(true);
            return count($keys);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function claimPrekey(int $actor, string $targetUid, string $deviceId): ?array
    {
        self::validateDeviceId($deviceId);
        $target = $this->targetUser($actor, $targetUid);
        $this->db->beginTransaction();
        try {
            $device = $this->db->fetchOne(
                'SELECT identity_json FROM messenger_e2ee_devices
                 WHERE user_id = :uid AND device_id = :did AND revoked_at IS NULL FOR UPDATE',
                [':uid' => $target, ':did' => $deviceId]
            );
            if ($device === null) {
                throw new DomainException('Устройство недоступно', 404);
            }
            $key = $this->db->fetchOne(
                'SELECT id, key_id, key_json FROM messenger_e2ee_prekeys
                 WHERE user_id = :uid AND device_id = :did AND claimed_at IS NULL
                 ORDER BY id ASC LIMIT 1 FOR UPDATE',
                [':uid' => $target, ':did' => $deviceId]
            );
            if ($key === null) {
                $this->db->endTransaction(true);
                return null;
            }
            $this->db->execute(
                'UPDATE messenger_e2ee_prekeys SET claimed_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND claimed_at IS NULL',
                [':id' => $key['id']]
            );
            $this->db->endTransaction(true);
            return [
                'device_id' => $deviceId,
                'identity' => json_decode((string) $device['identity_json'], true, 32, JSON_THROW_ON_ERROR),
                'key_id' => (string) $key['key_id'],
                'key' => json_decode((string) $key['key_json'], true, 32, JSON_THROW_ON_ERROR),
            ];
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
    }

    /** @param array<string,mixed> $envelope */
    public function send(
        int $actor, string $senderDevice, string $targetUid,
        string $targetDevice, string $dialogUid, string $transactionId, array $envelope
    ): void {
        self::validateDeviceId($senderDevice);
        self::validateDeviceId($targetDevice);
        if (preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $transactionId) !== 1) {
            throw new InvalidArgumentException('Неверный идентификатор передачи');
        }
        // Разрешён только пакет Olm, без произвольных незашифрованных полей.
        if (($envelope['algorithm'] ?? null) !== 'm.olm.v1.curve25519-aes-sha2'
            || !isset($envelope['sender_key'], $envelope['ciphertext'])
            || !is_string($envelope['sender_key']) || !is_array($envelope['ciphertext'])
            || $envelope['ciphertext'] === []
            || array_diff(array_keys($envelope), ['algorithm', 'sender_key', 'ciphertext', 'device_id']) !== []) {
            throw new InvalidArgumentException('Допускаются только зашифрованные пакеты Olm');
        }
        self::publicKey($envelope['sender_key']);
        if (isset($envelope['device_id']) && $envelope['device_id'] !== $senderDevice) {
            throw new InvalidArgumentException('Устройство отправителя не совпадает');
        }
        foreach ($envelope['ciphertext'] as $key => $ciphertext) {
            if (!is_string($key) || !is_array($ciphertext)
                || !isset($ciphertext['body'], $ciphertext['type'])
                || !is_string($ciphertext['body']) || strlen($ciphertext['body']) > 24000
                || !in_array($ciphertext['type'], [0, 1], true)) {
                throw new InvalidArgumentException('Неверная структура шифротекста Olm');
            }
        }
        $encoded = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > self::MAX_ENVELOPE_BYTES) {
            throw new InvalidArgumentException('Размер шифротекста превышает лимит');
        }
        $recipient = $this->targetUser($actor, $targetUid);
        $dialog = $this->db->fetchOne(
            'SELECT d.id FROM dialogs d
             INNER JOIN user_to_dialogs s ON s.dialog_id = d.id AND s.user_id = :sender AND s.is_deleted = 0
             INNER JOIN user_to_dialogs r ON r.dialog_id = d.id AND r.user_id = :recipient AND r.is_deleted = 0
             WHERE d.uid = :dialog LIMIT 1',
            [':sender' => $actor, ':recipient' => $recipient, ':dialog' => $dialogUid]
        );
        if ($dialog === null) {
            throw new DomainException('Нет доступа к общему диалогу', 403);
        }
        $this->db->beginTransaction();
        try {
            $sender = $this->ownDevice($actor, $senderDevice, true);
            if (!hash_equals((string) $sender['agreement_key'], (string) $envelope['sender_key'])) {
                throw new DomainException('Ключ пакета не принадлежит устройству отправителя', 403);
            }
            $target = $this->db->fetchOne(
                'SELECT id FROM messenger_e2ee_devices
                 WHERE user_id = :uid AND device_id = :did AND revoked_at IS NULL FOR UPDATE',
                [':uid' => $recipient, ':did' => $targetDevice]
            );
            if ($target === null) {
                throw new DomainException('Устройство получателя отозвано или отсутствует', 409);
            }
            $exists = $this->db->fetchOne(
                'SELECT id, envelope_json, dialog_id FROM messenger_e2ee_mailbox
                 WHERE sender_user_id = :sender AND sender_device_id = :sender_device
                   AND recipient_user_id = :recipient AND recipient_device_id = :device
                   AND transaction_id = :txn LIMIT 1',
                [':sender' => $actor, ':sender_device' => $senderDevice,
                 ':recipient' => $recipient, ':device' => $targetDevice, ':txn' => $transactionId]
            );
            if ($exists !== null) {
                if (json_decode((string) $exists['envelope_json'], true, 32, JSON_THROW_ON_ERROR) != $envelope
                    || (int) $exists['dialog_id'] !== (int) $dialog['id']) {
                    throw new DomainException('Повтор передачи с изменённым содержимым запрещён', 409);
                }
                $this->db->endTransaction(true);
                return;
            }
            $count = (int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM messenger_e2ee_mailbox
                 WHERE recipient_user_id = :uid AND recipient_device_id = :did
                   AND acknowledged_at IS NULL AND expires_at > CURRENT_TIMESTAMP',
                [':uid' => $recipient, ':did' => $targetDevice]
            );
            if ($count >= self::MAX_MAILBOX) {
                throw new DomainException('Очередь устройства заполнена', 429);
            }
            $this->db->execute(
                'INSERT INTO messenger_e2ee_mailbox
                 (sender_user_id, sender_device_id, recipient_user_id, recipient_device_id,
                  dialog_id, transaction_id, envelope_json, expires_at)
                 VALUES (:sender, :sender_device, :recipient, :recipient_device,
                         :dialog, :txn, :envelope, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 14 DAY))',
                [':sender' => $actor, ':sender_device' => $senderDevice,
                 ':recipient' => $recipient, ':recipient_device' => $targetDevice,
                 ':dialog' => $dialog['id'], ':txn' => $transactionId, ':envelope' => $encoded]
            );
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function inbox(int $actor, string $deviceId, int $after = 0): array
    {
        $this->ownDevice($actor, $deviceId);
        $rows = $this->db->fetchAll(
            'SELECT m.id, m.sender_device_id, m.transaction_id, m.envelope_json,
                    u.uid AS sender_uid, d.uid AS dialog_uid
             FROM messenger_e2ee_mailbox m
             INNER JOIN users u ON u.id = m.sender_user_id
             INNER JOIN dialogs d ON d.id = m.dialog_id
             WHERE m.recipient_user_id = :uid AND m.recipient_device_id = :did
               AND m.id > :after AND m.acknowledged_at IS NULL AND m.expires_at > CURRENT_TIMESTAMP
             ORDER BY m.id ASC LIMIT 50',
            [':uid' => $actor, ':did' => $deviceId, ':after' => max(0, $after)]
        );
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'sender_user_uid' => (string) $row['sender_uid'],
            'sender_device_id' => (string) $row['sender_device_id'],
            'dialog_uid' => (string) $row['dialog_uid'],
            'transaction_id' => (string) $row['transaction_id'],
            'envelope' => json_decode((string) $row['envelope_json'], true, 32, JSON_THROW_ON_ERROR),
        ], $rows);
    }

    /** @param list<int> $ids */
    public function acknowledge(int $actor, string $deviceId, array $ids): void
    {
        $this->ownDevice($actor, $deviceId);
        if (count($ids) > 50 || $ids === []) {
            throw new InvalidArgumentException('Нужно передать от 1 до 50 идентификаторов');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new InvalidArgumentException('Некорректный идентификатор сообщения');
            }
        }
        foreach (array_unique($ids) as $id) {
            $this->db->execute(
                'UPDATE messenger_e2ee_mailbox SET acknowledged_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND recipient_user_id = :uid AND recipient_device_id = :did
                   AND acknowledged_at IS NULL',
                [':id' => $id, ':uid' => $actor, ':did' => $deviceId]
            );
        }
    }

    public function revoke(int $actor, string $deviceId): void
    {
        self::validateDeviceId($deviceId);
        $this->db->beginTransaction();
        try {
            $this->ownDevice($actor, $deviceId, true);
            $this->db->execute(
                'UPDATE messenger_e2ee_devices SET revoked_at = CURRENT_TIMESTAMP
                 WHERE user_id = :uid AND device_id = :did AND revoked_at IS NULL',
                [':uid' => $actor, ':did' => $deviceId]
            );
            $this->db->execute(
                'DELETE FROM messenger_e2ee_prekeys WHERE user_id = :uid AND device_id = :did',
                [':uid' => $actor, ':did' => $deviceId]
            );
            $this->db->execute(
                'DELETE FROM messenger_e2ee_mailbox
                 WHERE recipient_user_id = :uid AND recipient_device_id = :did',
                [':uid' => $actor, ':did' => $deviceId]
            );
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    private function ownDevice(int $userId, string $deviceId, bool $lock = false): array
    {
        $row = $this->db->fetchOne(
            'SELECT signing_key, agreement_key FROM messenger_e2ee_devices
             WHERE user_id = :uid AND device_id = :did AND revoked_at IS NULL'
               . ($lock ? ' FOR UPDATE' : ''),
            [':uid' => $userId, ':did' => $deviceId]
        );
        if ($row === null) {
            throw new DomainException('Устройство не зарегистрировано или отозвано', 403);
        }
        return $row;
    }

    private function targetUser(int $actor, string $targetUid): int
    {
        if (preg_match('/^[a-fA-F0-9-]{36}$/D', $targetUid) !== 1) {
            throw new InvalidArgumentException('Некорректный идентификатор пользователя');
        }
        $user = $this->db->fetchOne(
            'SELECT id FROM users WHERE uid = :uid AND is_active = 1 LIMIT 1',
            [':uid' => $targetUid]
        );
        if ($user === null) {
            throw new DomainException('Пользователь не найден', 404);
        }
        $target = (int) $user['id'];
        if ($actor !== $target) {
            $shared = $this->db->fetchOne(
                'SELECT 1 FROM user_to_dialogs a
                 INNER JOIN user_to_dialogs b ON b.dialog_id = a.dialog_id
                 WHERE a.user_id = :actor AND b.user_id = :target
                   AND a.is_deleted = 0 AND b.is_deleted = 0 LIMIT 1',
                [':actor' => $actor, ':target' => $target]
            );
            if ($shared === null) {
                throw new DomainException('Нет общего диалога', 403);
            }
        }
        return $target;
    }

    public static function validateDeviceId(string $value): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Некорректный идентификатор устройства');
        }
    }

    public static function publicKey(mixed $value): string
    {
        if (!is_string($value) || strlen($value) < 40 || strlen($value) > 48
            || preg_match('/^[A-Za-z0-9+\/_-]+={0,2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Некорректный открытый ключ устройства');
        }
        $raw = base64_decode(strtr($value, '-_', '+/'), true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new InvalidArgumentException('Открытый ключ должен содержать 32 байта');
        }
        return $value;
    }
}
