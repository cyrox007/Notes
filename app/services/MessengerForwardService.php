<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\MessengerCrypto;
use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use UUID;

final class MessengerForwardService
{
    public function __construct(
        private ?DatabaseManager $db = null,
        private ?MessengerMediaService $media = null,
        private ?MessengerSavedService $saved = null
    ) {
        $this->db ??= DatabaseManager::getInstance();
        $this->media ??= new MessengerMediaService($this->db);
        $this->saved ??= new MessengerSavedService($this->db);
    }

    /** @return array<string,mixed> */
    public function save(string $actorUid, string $messageUid): array
    {
        $savedDialog = $this->saved->getOrCreate($actorUid);
        return $this->forward($actorUid, $messageUid, (string) $savedDialog['uid']);
    }

    /** @return array<string,mixed> */
    public function forward(string $actorUid, string $messageUid, string $destinationDialogUid): array
    {
        $actor = $this->activeUser($actorUid);
        $source = $this->visibleSource((int) $actor['id'], $messageUid);
        $destination = $this->destination((int) $actor['id'], $destinationDialogUid);

        $type = (string) ($source['message_type'] ?? 'text');
        if ($type === 'service') {
            throw new InvalidArgumentException('Системные сообщения нельзя пересылать');
        }

        try {
            $plaintext = MessengerCrypto::decrypt((string) $source['message'], (string) $source['uid']);
        } catch (\Throwable $e) {
            throw new RuntimeException('Не удалось расшифровать исходное сообщение', 0, $e);
        }

        $sourceMeta = $this->decodeMeta($source['meta_data'] ?? null);
        $forwardedFrom = $this->forwardOrigin($source, $sourceMeta);
        $newUid = UUID::v4();
        $now = date('Y-m-d H:i:s');
        $storedClone = null;

        $this->db->beginTransaction();
        try {
            $mediaUrl = null;
            $newMeta = ['forwarded_from' => $forwardedFrom];
            $attachmentId = null;

            if ($type !== 'text') {
                $sourceAttachmentUid = trim((string) ($sourceMeta['attachment_uid'] ?? ''));
                if ($sourceAttachmentUid === '') {
                    throw new InvalidArgumentException('У исходного media-сообщения нет защищённого вложения');
                }

                $sourceAttachment = $this->media->download((int) $actor['id'], $sourceAttachmentUid);
                if ((int) ($sourceAttachment['message_id'] ?? 0) !== (int) $source['id']) {
                    throw new InvalidArgumentException('Вложение не связано с пересылаемым сообщением');
                }

                $newAttachmentUid = UUID::v4();
                $extension = strtolower((string) ($sourceAttachment['extension'] ?? ''));
                if ($extension === '' || preg_match('/^[a-z0-9]{1,16}$/', $extension) !== 1) {
                    throw new RuntimeException('Некорректное расширение исходного вложения');
                }

                $directory = $this->storageRoot()
                    . DIRECTORY_SEPARATOR . (int) $destination['dialog_id']
                    . DIRECTORY_SEPARATOR . (int) $actor['id'];
                if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                    throw new RuntimeException('Не удалось подготовить хранилище для пересылки');
                }

                $storedClone = $directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(24)) . '.' . $extension;
                if (!copy((string) $sourceAttachment['path'], $storedClone)) {
                    throw new RuntimeException('Не удалось скопировать пересылаемое вложение');
                }
                @chmod($storedClone, 0600);

                $this->db->execute(
                    'INSERT INTO messenger_attachments (
                        uid, dialog_id, uploader_user_id, message_id,
                        original_name, stored_path, mime_type, extension,
                        media_kind, size, created_at, is_deleted
                     ) VALUES (
                        :uid, :dialog_id, :uploader_user_id, NULL,
                        :original_name, :stored_path, :mime_type, :extension,
                        :media_kind, :size, :created_at, 0
                     )',
                    [
                        ':uid' => $newAttachmentUid,
                        ':dialog_id' => (int) $destination['dialog_id'],
                        ':uploader_user_id' => (int) $actor['id'],
                        ':original_name' => (string) $sourceAttachment['original_name'],
                        ':stored_path' => $storedClone,
                        ':mime_type' => (string) $sourceAttachment['mime_type'],
                        ':extension' => $extension,
                        ':media_kind' => (string) $sourceAttachment['media_kind'],
                        ':size' => (int) $sourceAttachment['size'],
                        ':created_at' => $now,
                    ]
                );
                $attachmentId = (int) $this->db->getPdo()->lastInsertId();
                $mediaUrl = '/messenger/media/' . rawurlencode($newAttachmentUid);
                $newMeta = array_merge($newMeta, [
                    'attachment_uid' => $newAttachmentUid,
                    'name' => (string) $sourceAttachment['original_name'],
                    'mime_type' => (string) $sourceAttachment['mime_type'],
                    'extension' => $extension,
                    'size' => (int) $sourceAttachment['size'],
                ]);
            }

            $this->db->execute(
                'INSERT INTO messages (
                    uid, dialog_id, from_user_id, reply_to_message_id,
                    message, message_type, media_url, meta_data,
                    message_status, is_deleted, created_at, updated_at
                 ) VALUES (
                    :uid, :dialog_id, :from_user_id, NULL,
                    :message, :message_type, :media_url, :meta_data,
                    "sent", 0, :created_at, :updated_at
                 )',
                [
                    ':uid' => $newUid,
                    ':dialog_id' => (int) $destination['dialog_id'],
                    ':from_user_id' => (int) $actor['id'],
                    ':message' => MessengerCrypto::encrypt($plaintext, $newUid),
                    ':message_type' => $type,
                    ':media_url' => $mediaUrl,
                    ':meta_data' => json_encode($newMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]
            );
            $newMessageId = (int) $this->db->getPdo()->lastInsertId();

            if ($attachmentId !== null) {
                $affected = $this->db->execute(
                    'UPDATE messenger_attachments
                     SET message_id = :message_id
                     WHERE id = :attachment_id AND message_id IS NULL',
                    [':message_id' => $newMessageId, ':attachment_id' => $attachmentId]
                );
                if ((int) $affected !== 1) {
                    throw new RuntimeException('Не удалось связать пересланное вложение с сообщением');
                }
            }

            $this->db->execute(
                'UPDATE dialogs SET updated_at = :updated_at WHERE id = :dialog_id',
                [':updated_at' => $now, ':dialog_id' => (int) $destination['dialog_id']]
            );
            $this->db->endTransaction(true);
        } catch (\Throwable $e) {
            $this->db->endTransaction(false);
            if ($storedClone !== null && is_file($storedClone)) {
                @unlink($storedClone);
            }
            throw $e;
        }

        return [
            'id' => $newMessageId,
            'uid' => $newUid,
            'dialog_id' => (int) $destination['dialog_id'],
            'dialog_uid' => (string) $destination['dialog_uid'],
            'from_user_id' => (int) $actor['id'],
            'message' => $plaintext,
            'message_type' => $type,
            'media_url' => $mediaUrl,
            'meta_data' => $newMeta,
            'message_status' => 'sent',
            'edited_at' => null,
            'created_at' => $now,
            'decrypt_error' => false,
            'user' => [
                'uid' => (string) $actor['uid'],
                'username' => (string) ($actor['username'] ?? ''),
                'firstname' => (string) ($actor['firstname'] ?? ''),
                'lastname' => (string) ($actor['lastname'] ?? ''),
                'avatar' => $actor['avatar'] ?? null,
            ],
            'reply' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function activeUser(string $uid): array
    {
        $row = $this->db->fetchOne(
            'SELECT id, uid, username, firstname, lastname, avatar
             FROM users
             WHERE uid = :uid AND is_active = 1
             LIMIT 1',
            [':uid' => trim($uid)]
        );
        if (!$row) {
            throw new DomainException('Пользователь не найден или заблокирован');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function visibleSource(int $actorId, string $messageUid): array
    {
        $row = $this->db->fetchOne(
            'SELECT
                m.id, m.uid, m.dialog_id, m.message, m.message_type, m.meta_data,
                u.username AS source_username,
                u.firstname AS source_firstname, u.lastname AS source_lastname
             FROM messages m
             INNER JOIN users u ON u.id = m.from_user_id
             INNER JOIN user_to_dialogs utd
                ON utd.dialog_id = m.dialog_id
               AND utd.user_id = :actor_id
               AND utd.is_deleted = 0
             WHERE m.uid = :message_uid
               AND m.is_deleted = 0
               AND NOT EXISTS (
                    SELECT 1
                    FROM message_user_deletions mud
                    WHERE mud.message_id = m.id AND mud.user_id = :hidden_actor_id
               )
             LIMIT 1',
            [
                ':actor_id' => $actorId,
                ':hidden_actor_id' => $actorId,
                ':message_uid' => trim($messageUid),
            ]
        );
        if (!$row) {
            throw new DomainException('Исходное сообщение недоступно');
        }
        return $row;
    }

    /** @return array{dialog_id:int,dialog_uid:string,type:string} */
    private function destination(int $actorId, string $dialogUid): array
    {
        $row = $this->db->fetchOne(
            'SELECT d.id AS dialog_id, d.uid AS dialog_uid, d.type
             FROM dialogs d
             INNER JOIN user_to_dialogs utd
                ON utd.dialog_id = d.id
               AND utd.user_id = :actor_id
               AND utd.is_deleted = 0
             WHERE d.uid = :dialog_uid
             LIMIT 1',
            [':actor_id' => $actorId, ':dialog_uid' => trim($dialogUid)]
        );
        if (!$row) {
            throw new DomainException('Целевой диалог недоступен');
        }
        return [
            'dialog_id' => (int) $row['dialog_id'],
            'dialog_uid' => (string) $row['dialog_uid'],
            'type' => (string) $row['type'],
        ];
    }

    /** @return array<string,mixed> */
    private function decodeMeta(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $sourceMeta */
    private function forwardOrigin(array $source, array $sourceMeta): array
    {
        $existing = $sourceMeta['forwarded_from'] ?? null;
        if (is_array($existing) && !empty($existing['user_name'])) {
            return ['user_name' => mb_substr((string) $existing['user_name'], 0, 160)];
        }

        $name = trim(
            (string) ($source['source_firstname'] ?? '') . ' ' .
            (string) ($source['source_lastname'] ?? '')
        );
        if ($name === '') {
            $name = (string) ($source['source_username'] ?? 'Пользователь');
        }
        return ['user_name' => mb_substr($name, 0, 160)];
    }

    private function privateStorageRoot(): string
    {
        $configured = getenv('PRIVATE_STORAGE_PATH');
        $root = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : dirname(SITEPATH) . DIRECTORY_SEPARATOR . 'notes-private-storage';
        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    private function storageRoot(): string
    {
        return $this->privateStorageRoot() . DIRECTORY_SEPARATOR . 'messenger';
    }
}
