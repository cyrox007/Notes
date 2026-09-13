<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\MessengerCrypto;
use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use UUID;

final class MessengerService
{
    private const PAGE_SIZE = 50;
    private const MAX_MESSAGE_LENGTH = 4096;

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @return array<string, mixed> */
    public function getUserByUid(string $userUid): array
    {
        $user = $this->db->fetchOne(
            'SELECT id, uid, username, firstname, lastname, avatar, is_active
             FROM users
             WHERE uid = :uid AND is_active = 1
             LIMIT 1',
            [':uid' => $userUid]
        );

        if (!$user) {
            throw new DomainException('Пользователь не найден или заблокирован');
        }

        return $user;
    }

    /** @return list<array<string, mixed>> */
    public function listDialogs(string $userUid): array
    {
        $user = $this->getUserByUid($userUid);
        $userId = (int) $user['id'];

        $rows = $this->db->fetchAll(
            'SELECT
                d.id AS dialog_id,
                d.uid,
                d.type,
                d.name,
                d.avatar,
                d.updated_at,
                utd.role,
                utd.last_read_message_id,
                (
                    SELECT COUNT(*)
                    FROM messages unread
                    WHERE unread.dialog_id = d.id
                      AND unread.is_deleted = 0
                      AND unread.from_user_id <> :unread_user_id
                      AND unread.id > COALESCE(utd.last_read_message_id, 0)
                      AND NOT EXISTS (
                          SELECT 1 FROM message_user_deletions mud_u
                          WHERE mud_u.message_id = unread.id AND mud_u.user_id = :unread_hidden_user_id
                      )
                ) AS unread_count,
                lm.id AS last_message_id,
                lm.uid AS last_message_uid,
                lm.from_user_id AS last_message_from_user_id,
                lm.message AS last_message,
                lm.message_type AS last_message_type,
                lm.media_url AS last_message_media_url,
                lm.created_at AS last_message_at,
                lu.uid AS last_sender_uid,
                lu.firstname AS last_sender_firstname,
                lu.lastname AS last_sender_lastname
             FROM user_to_dialogs utd
             INNER JOIN dialogs d ON d.id = utd.dialog_id
             LEFT JOIN messages lm ON lm.id = (
                 SELECT m2.id
                 FROM messages m2
                 WHERE m2.dialog_id = d.id
                   AND m2.is_deleted = 0
                   AND NOT EXISTS (
                       SELECT 1 FROM message_user_deletions mud_l
                       WHERE mud_l.message_id = m2.id AND mud_l.user_id = :last_hidden_user_id
                   )
                 ORDER BY m2.id DESC
                 LIMIT 1
             )
             LEFT JOIN users lu ON lu.id = lm.from_user_id
             WHERE utd.user_id = :member_user_id
               AND utd.is_deleted = 0
             ORDER BY COALESCE(utd.pinned_at, "1000-01-01 00:00:00") DESC,
                      COALESCE(lm.created_at, d.updated_at) DESC',
            [
                ':unread_user_id' => $userId,
                ':unread_hidden_user_id' => $userId,
                ':last_hidden_user_id' => $userId,
                ':member_user_id' => $userId,
            ]
        );

        if ($rows === []) {
            return [];
        }

        $dialogIds = array_map(static fn (array $row): int => (int) $row['dialog_id'], $rows);
        $participants = $this->participantsForDialogIds($dialogIds);

        foreach ($rows as &$row) {
            $dialogId = (int) $row['dialog_id'];
            $members = $participants[$dialogId] ?? [];
            $row['participants'] = $members;
            $row['unread_count'] = (int) ($row['unread_count'] ?? 0);

            if ($row['type'] === 'private') {
                $partner = null;
                foreach ($members as $member) {
                    if ((int) $member['id'] !== $userId) {
                        $partner = $member;
                        break;
                    }
                }
                $row['partner'] = $partner;
                $row['title'] = $partner ? $this->displayName($partner) : 'Удалённый пользователь';
                $row['avatar'] = $partner['avatar'] ?? null;
            } else {
                $row['partner'] = null;
                $row['title'] = trim((string) ($row['name'] ?? '')) ?: 'Групповой чат';
            }

            $row['last_message_preview'] = null;
            if (!empty($row['last_message_uid'])) {
                if (($row['last_message_type'] ?? 'text') === 'text') {
                    try {
                        $row['last_message_preview'] = MessengerCrypto::decrypt(
                            (string) $row['last_message'],
                            (string) $row['last_message_uid']
                        );
                    } catch (\Throwable $e) {
                        error_log('Messenger preview decrypt failed: ' . $e->getMessage());
                        $row['last_message_preview'] = '[Не удалось расшифровать сообщение]';
                    }
                } else {
                    $row['last_message_preview'] = $this->mediaLabel((string) $row['last_message_type']);
                }
            }

            unset($row['last_message']);
        }
        unset($row);

        return $rows;
    }

    /** @return array<string, mixed> */
    public function getDialogInfo(string $userUid, string $dialogUid): array
    {
        [$user, $dialog] = $this->requireAccess($userUid, $dialogUid);
        $membersByDialog = $this->participantsForDialogIds([(int) $dialog['id']]);
        $members = $membersByDialog[(int) $dialog['id']] ?? [];

        $info = [
            'uid' => $dialog['uid'],
            'type' => $dialog['type'],
            'name' => $dialog['name'],
            'avatar' => $dialog['avatar'],
            'participants' => $members,
            'title' => trim((string) ($dialog['name'] ?? '')) ?: 'Групповой чат',
            'partner' => null,
        ];

        if ($dialog['type'] === 'private') {
            foreach ($members as $member) {
                if ((int) $member['id'] !== (int) $user['id']) {
                    $info['partner'] = $member;
                    $info['title'] = $this->displayName($member);
                    $info['avatar'] = $member['avatar'] ?? null;
                    break;
                }
            }
        }

        return $info;
    }

    /**
     * @return array{dialog: array<string,mixed>, messages: list<array<string,mixed>>, has_more: bool, last_message_id: int|null}
     */
    public function getMessages(
        string $userUid,
        string $dialogUid,
        ?int $beforeId = null,
        int $limit = self::PAGE_SIZE
    ): array {
        [$user, $dialog] = $this->requireAccess($userUid, $dialogUid);
        $limit = max(1, min(100, $limit));

        $whereBefore = '';
        $params = [
            ':dialog_id' => (int) $dialog['id'],
            ':viewer_id' => (int) $user['id'],
        ];
        if ($beforeId !== null && $beforeId > 0) {
            $whereBefore = ' AND m.id < :before_id';
            $params[':before_id'] = $beforeId;
        }

        $rows = $this->db->fetchAll(
            'SELECT
                m.id,
                m.uid,
                m.dialog_id,
                m.from_user_id,
                m.reply_to_message_id,
                m.message,
                m.message_type,
                m.media_url,
                m.meta_data,
                m.message_status,
                m.edited_at,
                m.created_at,
                u.uid AS user_uid,
                u.username,
                u.firstname,
                u.lastname,
                u.avatar,
                reply.uid AS reply_uid,
                reply.message AS reply_message,
                reply.message_type AS reply_message_type,
                reply_user.uid AS reply_user_uid,
                reply_user.firstname AS reply_user_firstname,
                reply_user.lastname AS reply_user_lastname
             FROM messages m
             INNER JOIN users u ON u.id = m.from_user_id
             LEFT JOIN messages reply ON reply.id = m.reply_to_message_id AND reply.is_deleted = 0
             LEFT JOIN users reply_user ON reply_user.id = reply.from_user_id
             WHERE m.dialog_id = :dialog_id
               AND m.is_deleted = 0
               AND NOT EXISTS (
                   SELECT 1 FROM message_user_deletions mud
                   WHERE mud.message_id = m.id AND mud.user_id = :viewer_id
               )' . $whereBefore . '
             ORDER BY m.id DESC
             LIMIT ' . $limit,
            $params
        );

        $messages = [];
        foreach (array_reverse($rows) as $row) {
            $messages[] = $this->hydrateMessage($row);
        }

        $lastMessageId = $messages === [] ? null : (int) end($messages)['id'];

        return [
            'dialog' => $this->getDialogInfo($userUid, $dialogUid),
            'messages' => $messages,
            'has_more' => count($rows) === $limit,
            'last_message_id' => $lastMessageId,
        ];
    }

    /** @return array<string, mixed> */
    public function createDialog(
        string $userUid,
        string $type,
        array $participantUids,
        ?string $name = null
    ): array {
        $creator = $this->getUserByUid($userUid);
        $type = strtolower(trim($type));
        if (!in_array($type, ['private', 'group'], true)) {
            throw new InvalidArgumentException('Неизвестный тип диалога');
        }

        $participantUids = array_values(array_unique(array_filter(
            array_map(static fn ($uid): string => trim((string) $uid), $participantUids),
            static fn (string $uid): bool => $uid !== '' && $uid !== $userUid
        )));

        if ($type === 'private' && count($participantUids) !== 1) {
            throw new InvalidArgumentException('Для личного диалога нужен один собеседник');
        }
        if ($type === 'group' && count($participantUids) < 1) {
            throw new InvalidArgumentException('Добавьте хотя бы одного участника группы');
        }

        $participants = $this->usersByUids($participantUids);
        if (count($participants) !== count($participantUids)) {
            throw new InvalidArgumentException('Один или несколько участников недоступны');
        }

        if ($type === 'private') {
            $existing = $this->findPrivateDialog((int) $creator['id'], (int) $participants[0]['id']);
            if ($existing !== null) {
                return $this->getDialogInfo($userUid, (string) $existing['uid']);
            }
        }

        $groupName = $type === 'group' ? trim((string) $name) : null;
        if ($groupName !== null && mb_strlen($groupName) > 120) {
            throw new InvalidArgumentException('Название группы слишком длинное');
        }
        if ($type === 'group' && $groupName === '') {
            $groupName = 'Новая группа';
        }

        $dialogUid = UUID::v4();
        $now = date('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $this->db->execute(
                'INSERT INTO dialogs (uid, type, name, created_by, created_at, updated_at)
                 VALUES (:uid, :type, :name, :created_by, :created_at, :updated_at)',
                [
                    ':uid' => $dialogUid,
                    ':type' => $type,
                    ':name' => $groupName,
                    ':created_by' => (int) $creator['id'],
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]
            );
            $dialogId = (int) $this->db->getPdo()->lastInsertId();

            $this->db->execute(
                'INSERT INTO user_to_dialogs (dialog_id, user_id, role, joined_at, is_deleted)
                 VALUES (:dialog_id, :user_id, :role, :joined_at, 0)',
                [
                    ':dialog_id' => $dialogId,
                    ':user_id' => (int) $creator['id'],
                    ':role' => 'owner',
                    ':joined_at' => $now,
                ]
            );

            foreach ($participants as $participant) {
                $this->db->execute(
                    'INSERT INTO user_to_dialogs (dialog_id, user_id, role, joined_at, is_deleted)
                     VALUES (:dialog_id, :user_id, :role, :joined_at, 0)',
                    [
                        ':dialog_id' => $dialogId,
                        ':user_id' => (int) $participant['id'],
                        ':role' => 'member',
                        ':joined_at' => $now,
                    ]
                );
            }

            $this->db->endTransaction(true);
        } catch (\Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }

        return $this->getDialogInfo($userUid, $dialogUid);
    }

    /** @return array<string, mixed> */
    public function sendMessage(
        string $userUid,
        string $dialogUid,
        string $message,
        ?string $replyToUid = null
    ): array {
        [$user, $dialog] = $this->requireAccess($userUid, $dialogUid);
        $message = trim($message);

        if ($message === '') {
            throw new InvalidArgumentException('Сообщение не может быть пустым');
        }
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            throw new InvalidArgumentException('Сообщение слишком длинное');
        }

        $replyToId = null;
        if ($replyToUid !== null && $replyToUid !== '') {
            $reply = $this->db->fetchOne(
                'SELECT id FROM messages
                 WHERE uid = :uid AND dialog_id = :dialog_id AND is_deleted = 0
                 LIMIT 1',
                [':uid' => $replyToUid, ':dialog_id' => (int) $dialog['id']]
            );
            if (!$reply) {
                throw new InvalidArgumentException('Сообщение для ответа не найдено');
            }
            $replyToId = (int) $reply['id'];
        }

        $messageUid = UUID::v4();
        $encrypted = MessengerCrypto::encrypt($message, $messageUid);
        $now = date('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $this->db->execute(
                'INSERT INTO messages (
                    uid, dialog_id, from_user_id, reply_to_message_id, message,
                    message_type, message_status, is_deleted, created_at, updated_at
                 ) VALUES (
                    :uid, :dialog_id, :from_user_id, :reply_to_message_id, :message,
                    :message_type, :message_status, 0, :created_at, :updated_at
                 )',
                [
                    ':uid' => $messageUid,
                    ':dialog_id' => (int) $dialog['id'],
                    ':from_user_id' => (int) $user['id'],
                    ':reply_to_message_id' => $replyToId,
                    ':message' => $encrypted,
                    ':message_type' => 'text',
                    ':message_status' => 'sent',
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]
            );
            $messageId = (int) $this->db->getPdo()->lastInsertId();
            $this->db->execute(
                'UPDATE dialogs SET updated_at = :updated_at WHERE id = :id',
                [':updated_at' => $now, ':id' => (int) $dialog['id']]
            );
            $this->db->endTransaction(true);
        } catch (\Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }

        return $this->messageById($messageId);
    }

    /** @return array<string, mixed> */
    public function editMessage(string $userUid, string $messageUid, string $newText): array
    {
        $user = $this->getUserByUid($userUid);
        $message = $this->db->fetchOne(
            'SELECT m.id, m.uid, m.dialog_id, m.from_user_id, m.is_deleted
             FROM messages m
             INNER JOIN user_to_dialogs utd
                ON utd.dialog_id = m.dialog_id AND utd.user_id = :user_id AND utd.is_deleted = 0
             WHERE m.uid = :message_uid
             LIMIT 1',
            [':user_id' => (int) $user['id'], ':message_uid' => $messageUid]
        );

        if (!$message || (int) $message['is_deleted'] === 1) {
            throw new DomainException('Сообщение не найдено');
        }
        if ((int) $message['from_user_id'] !== (int) $user['id']) {
            throw new DomainException('Можно редактировать только свои сообщения');
        }

        $newText = trim($newText);
        if ($newText === '' || mb_strlen($newText) > self::MAX_MESSAGE_LENGTH) {
            throw new InvalidArgumentException('Некорректный текст сообщения');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'UPDATE messages
             SET message = :message, edited_at = :edited_at, updated_at = :updated_at
             WHERE id = :id',
            [
                ':message' => MessengerCrypto::encrypt($newText, $messageUid),
                ':edited_at' => $now,
                ':updated_at' => $now,
                ':id' => (int) $message['id'],
            ]
        );

        return $this->messageById((int) $message['id']);
    }

    /**
     * @return array{dialog_uid:string,message_uid:string,for_all:bool}
     */
    public function deleteMessage(string $userUid, string $messageUid, bool $forAll): array
    {
        $user = $this->getUserByUid($userUid);
        $message = $this->db->fetchOne(
            'SELECT m.id, m.dialog_id, m.from_user_id, d.uid AS dialog_uid
             FROM messages m
             INNER JOIN dialogs d ON d.id = m.dialog_id
             INNER JOIN user_to_dialogs utd
                ON utd.dialog_id = m.dialog_id AND utd.user_id = :user_id AND utd.is_deleted = 0
             WHERE m.uid = :message_uid
             LIMIT 1',
            [':user_id' => (int) $user['id'], ':message_uid' => $messageUid]
        );

        if (!$message) {
            throw new DomainException('Сообщение не найдено');
        }

        if ($forAll) {
            if ((int) $message['from_user_id'] !== (int) $user['id']) {
                throw new DomainException('Удалить сообщение у всех может только автор');
            }
            $now = date('Y-m-d H:i:s');
            $this->db->execute(
                'UPDATE messages
                 SET is_deleted = 1, message = :tombstone, message_status = :status,
                     deleted_at = :deleted_at, updated_at = :updated_at
                 WHERE id = :id',
                [
                    ':tombstone' => '',
                    ':status' => 'deleted',
                    ':deleted_at' => $now,
                    ':updated_at' => $now,
                    ':id' => (int) $message['id'],
                ]
            );
        } else {
            $this->db->execute(
                'INSERT IGNORE INTO message_user_deletions (message_id, user_id, deleted_at)
                 VALUES (:message_id, :user_id, :deleted_at)',
                [
                    ':message_id' => (int) $message['id'],
                    ':user_id' => (int) $user['id'],
                    ':deleted_at' => date('Y-m-d H:i:s'),
                ]
            );
        }

        return [
            'dialog_uid' => (string) $message['dialog_uid'],
            'message_uid' => $messageUid,
            'for_all' => $forAll,
        ];
    }

    /**
     * Advance this member's read cursor only forward.
     * @return array{dialog_uid:string,user_uid:string,last_read_message_id:int}
     */
    public function markRead(string $userUid, string $dialogUid, ?string $messageUid = null): array
    {
        [$user, $dialog] = $this->requireAccess($userUid, $dialogUid);

        if ($messageUid !== null && $messageUid !== '') {
            $target = $this->db->fetchOne(
                'SELECT id FROM messages WHERE uid = :uid AND dialog_id = :dialog_id LIMIT 1',
                [':uid' => $messageUid, ':dialog_id' => (int) $dialog['id']]
            );
            if (!$target) {
                throw new InvalidArgumentException('Read cursor message does not belong to this dialog');
            }
            $lastReadId = (int) $target['id'];
        } else {
            $lastReadId = (int) ($this->db->fetchValue(
                'SELECT MAX(id) FROM messages WHERE dialog_id = :dialog_id AND is_deleted = 0',
                [':dialog_id' => (int) $dialog['id']]
            ) ?? 0);
        }

        $this->db->execute(
            'UPDATE user_to_dialogs
             SET last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), :message_id)
             WHERE dialog_id = :dialog_id AND user_id = :user_id AND is_deleted = 0',
            [
                ':message_id' => $lastReadId,
                ':dialog_id' => (int) $dialog['id'],
                ':user_id' => (int) $user['id'],
            ]
        );

        return [
            'dialog_uid' => $dialogUid,
            'user_uid' => $userUid,
            'last_read_message_id' => $lastReadId,
        ];
    }

    /** @return list<string> */
    public function participantUids(string $userUid, string $dialogUid): array
    {
        [, $dialog] = $this->requireAccess($userUid, $dialogUid);
        $rows = $this->db->fetchAll(
            'SELECT u.uid
             FROM user_to_dialogs utd
             INNER JOIN users u ON u.id = utd.user_id
             WHERE utd.dialog_id = :dialog_id AND utd.is_deleted = 0 AND u.is_active = 1',
            [':dialog_id' => (int) $dialog['id']]
        );

        return array_values(array_map(static fn (array $row): string => (string) $row['uid'], $rows));
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>} */
    private function requireAccess(string $userUid, string $dialogUid): array
    {
        $user = $this->getUserByUid($userUid);
        $dialog = $this->db->fetchOne(
            'SELECT id, uid, type, name, avatar, created_by, created_at, updated_at
             FROM dialogs WHERE uid = :uid LIMIT 1',
            [':uid' => $dialogUid]
        );
        if (!$dialog) {
            throw new DomainException('Диалог не найден');
        }

        $membership = $this->db->fetchOne(
            'SELECT id, role, last_read_message_id
             FROM user_to_dialogs
             WHERE dialog_id = :dialog_id AND user_id = :user_id AND is_deleted = 0
             LIMIT 1',
            [':dialog_id' => (int) $dialog['id'], ':user_id' => (int) $user['id']]
        );
        if (!$membership) {
            throw new DomainException('Нет доступа к диалогу');
        }

        return [$user, $dialog, $membership];
    }

    /** @return array<int, list<array<string,mixed>>> */
    private function participantsForDialogIds(array $dialogIds): array
    {
        if ($dialogIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values(array_unique($dialogIds)) as $index => $dialogId) {
            $key = ':dialog_' . $index;
            $placeholders[] = $key;
            $params[$key] = (int) $dialogId;
        }

        $rows = $this->db->fetchAll(
            'SELECT utd.dialog_id, utd.role, u.id, u.uid, u.username, u.firstname, u.lastname, u.avatar
             FROM user_to_dialogs utd
             INNER JOIN users u ON u.id = utd.user_id
             WHERE utd.dialog_id IN (' . implode(',', $placeholders) . ')
               AND utd.is_deleted = 0
             ORDER BY utd.id ASC',
            $params
        );

        $result = [];
        foreach ($rows as $row) {
            $dialogId = (int) $row['dialog_id'];
            $row['id'] = (int) $row['id'];
            $result[$dialogId][] = $row;
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function usersByUids(array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($uids as $index => $uid) {
            $key = ':uid_' . $index;
            $placeholders[] = $key;
            $params[$key] = $uid;
        }

        return $this->db->fetchAll(
            'SELECT id, uid, username, firstname, lastname, avatar
             FROM users
             WHERE uid IN (' . implode(',', $placeholders) . ') AND is_active = 1',
            $params
        );
    }

    /** @return array<string,mixed>|null */
    private function findPrivateDialog(int $firstUserId, int $secondUserId): ?array
    {
        return $this->db->fetchOne(
            'SELECT d.id, d.uid
             FROM dialogs d
             INNER JOIN user_to_dialogs first_member
                ON first_member.dialog_id = d.id
               AND first_member.user_id = :first_user_id
               AND first_member.is_deleted = 0
             INNER JOIN user_to_dialogs second_member
                ON second_member.dialog_id = d.id
               AND second_member.user_id = :second_user_id
               AND second_member.is_deleted = 0
             WHERE d.type = "private"
               AND (
                   SELECT COUNT(*) FROM user_to_dialogs all_members
                   WHERE all_members.dialog_id = d.id AND all_members.is_deleted = 0
               ) = 2
             LIMIT 1',
            [':first_user_id' => $firstUserId, ':second_user_id' => $secondUserId]
        );
    }

    /** @return array<string,mixed> */
    private function messageById(int $messageId): array
    {
        $row = $this->db->fetchOne(
            'SELECT
                m.id, m.uid, m.dialog_id, m.from_user_id, m.reply_to_message_id,
                m.message, m.message_type, m.media_url, m.meta_data,
                m.message_status, m.edited_at, m.created_at,
                u.uid AS user_uid, u.username, u.firstname, u.lastname, u.avatar,
                reply.uid AS reply_uid, reply.message AS reply_message,
                reply.message_type AS reply_message_type,
                reply_user.uid AS reply_user_uid,
                reply_user.firstname AS reply_user_firstname,
                reply_user.lastname AS reply_user_lastname
             FROM messages m
             INNER JOIN users u ON u.id = m.from_user_id
             LEFT JOIN messages reply ON reply.id = m.reply_to_message_id AND reply.is_deleted = 0
             LEFT JOIN users reply_user ON reply_user.id = reply.from_user_id
             WHERE m.id = :id AND m.is_deleted = 0
             LIMIT 1',
            [':id' => $messageId]
        );

        if (!$row) {
            throw new RuntimeException('Stored message could not be read back');
        }

        return $this->hydrateMessage($row);
    }

    /** @return array<string,mixed> */
    private function hydrateMessage(array $row): array
    {
        try {
            $plaintext = MessengerCrypto::decrypt((string) $row['message'], (string) $row['uid']);
            $decryptError = false;
        } catch (\Throwable $e) {
            error_log('Messenger decrypt failed for ' . ($row['uid'] ?? 'unknown') . ': ' . $e->getMessage());
            $plaintext = '[Не удалось расшифровать сообщение]';
            $decryptError = true;
        }

        $reply = null;
        if (!empty($row['reply_uid'])) {
            $replyText = $this->mediaLabel((string) ($row['reply_message_type'] ?? 'text'));
            if (($row['reply_message_type'] ?? 'text') === 'text') {
                try {
                    $replyText = MessengerCrypto::decrypt(
                        (string) $row['reply_message'],
                        (string) $row['reply_uid']
                    );
                } catch (\Throwable) {
                    $replyText = '[Сообщение недоступно]';
                }
            }
            $reply = [
                'uid' => (string) $row['reply_uid'],
                'message' => $replyText,
                'user_uid' => (string) ($row['reply_user_uid'] ?? ''),
                'user_name' => trim(
                    (string) ($row['reply_user_firstname'] ?? '') . ' ' .
                    (string) ($row['reply_user_lastname'] ?? '')
                ),
            ];
        }

        $meta = $row['meta_data'] ?? null;
        if (is_string($meta) && $meta !== '') {
            $decodedMeta = json_decode($meta, true);
            $meta = is_array($decodedMeta) ? $decodedMeta : null;
        }

        return [
            'id' => (int) $row['id'],
            'uid' => (string) $row['uid'],
            'dialog_id' => (int) $row['dialog_id'],
            'from_user_id' => (int) $row['from_user_id'],
            'message' => $plaintext,
            'message_type' => (string) ($row['message_type'] ?? 'text'),
            'media_url' => $row['media_url'] ?? null,
            'meta_data' => $meta,
            'message_status' => (string) ($row['message_status'] ?? 'sent'),
            'edited_at' => $row['edited_at'] ?? null,
            'created_at' => (string) $row['created_at'],
            'decrypt_error' => $decryptError,
            'user' => [
                'uid' => (string) $row['user_uid'],
                'username' => (string) ($row['username'] ?? ''),
                'firstname' => (string) ($row['firstname'] ?? ''),
                'lastname' => (string) ($row['lastname'] ?? ''),
                'avatar' => $row['avatar'] ?? null,
            ],
            'reply' => $reply,
        ];
    }

    private function displayName(array $user): string
    {
        $name = trim((string) ($user['firstname'] ?? '') . ' ' . (string) ($user['lastname'] ?? ''));
        return $name !== '' ? $name : (string) ($user['username'] ?? 'Пользователь');
    }

    private function mediaLabel(string $type): string
    {
        return match ($type) {
            'image' => '🖼 Изображение',
            'audio' => '🎵 Аудио',
            'video' => '🎬 Видео',
            'voice' => '🎙 Голосовое сообщение',
            'file' => '📎 Файл',
            'service' => 'Системное сообщение',
            default => 'Сообщение',
        };
    }
}
