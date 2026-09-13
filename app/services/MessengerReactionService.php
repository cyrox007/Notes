<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;

final class MessengerReactionService
{
    /** @var array<string,string> */
    private const ALLOWED = [
        'like' => '👍',
        'heart' => '❤️',
        'laugh' => '😂',
        'wow' => '😮',
        'sad' => '😢',
        'fire' => '🔥',
    ];

    private const MAX_MESSAGE_UIDS = 100;

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /**
     * @param list<string> $messageUids
     * @return array<string,list<array{code:string,emoji:string,count:int,reacted_by_me:bool}>>
     */
    public function listForUser(string $viewerUid, array $messageUids): array
    {
        $viewer = $this->activeUser($viewerUid);
        $uids = $this->normalizeMessageUids($messageUids);
        if ($uids === []) {
            return [];
        }

        [$inSql, $inParams] = $this->inClause('message_uid', $uids);
        $params = array_merge([
            ':viewer_id' => (int) $viewer['id'],
            ':hidden_viewer_id' => (int) $viewer['id'],
        ], $inParams);

        $visibleRows = $this->db->fetchAll(
            'SELECT m.id, m.uid
             FROM messages m
             INNER JOIN user_to_dialogs utd
                ON utd.dialog_id = m.dialog_id
               AND utd.user_id = :viewer_id
               AND utd.is_deleted = 0
             WHERE m.uid IN (' . $inSql . ')
               AND m.is_deleted = 0
               AND NOT EXISTS (
                    SELECT 1
                    FROM message_user_deletions mud
                    WHERE mud.message_id = m.id
                      AND mud.user_id = :hidden_viewer_id
               )',
            $params
        );

        if ($visibleRows === []) {
            return [];
        }

        $idToUid = [];
        $result = [];
        foreach ($visibleRows as $row) {
            $id = (int) $row['id'];
            $uid = (string) $row['uid'];
            $idToUid[$id] = $uid;
            $result[$uid] = [];
        }

        [$idSql, $idParams] = $this->inClause('message_id', array_map('strval', array_keys($idToUid)));
        $reactionRows = $this->db->fetchAll(
            'SELECT
                mr.message_id,
                mr.reaction_code,
                COUNT(*) AS reaction_count,
                MAX(CASE WHEN mr.user_id = :reaction_viewer_id THEN 1 ELSE 0 END) AS reacted_by_me
             FROM message_reactions mr
             WHERE mr.message_id IN (' . $idSql . ')
               AND mr.is_active = 1
             GROUP BY mr.message_id, mr.reaction_code',
            array_merge([':reaction_viewer_id' => (int) $viewer['id']], $idParams)
        );

        $byMessage = [];
        foreach ($reactionRows as $row) {
            $messageId = (int) $row['message_id'];
            $code = (string) $row['reaction_code'];
            if (!isset($idToUid[$messageId], self::ALLOWED[$code])) {
                continue;
            }
            $byMessage[$messageId][$code] = [
                'code' => $code,
                'emoji' => self::ALLOWED[$code],
                'count' => (int) $row['reaction_count'],
                'reacted_by_me' => (int) $row['reacted_by_me'] === 1,
            ];
        }

        foreach ($idToUid as $messageId => $messageUid) {
            $summary = [];
            foreach (self::ALLOWED as $code => $emoji) {
                if (isset($byMessage[$messageId][$code])) {
                    $summary[] = $byMessage[$messageId][$code];
                }
            }
            $result[$messageUid] = $summary;
        }

        return $result;
    }

    /**
     * Atomically toggles one whitelisted reaction for the authenticated viewer.
     * @return array{message_uid:string,dialog_uid:string,reactions:list<array{code:string,emoji:string,count:int,reacted_by_me:bool}>}
     */
    public function toggle(string $viewerUid, string $messageUid, string $reactionCode): array
    {
        $viewer = $this->activeUser($viewerUid);
        $reactionCode = trim($reactionCode);
        if (!isset(self::ALLOWED[$reactionCode])) {
            throw new InvalidArgumentException('Неподдерживаемая реакция');
        }

        $message = $this->visibleMessage((int) $viewer['id'], $messageUid);
        $now = date('Y-m-d H:i:s');

        $this->db->execute(
            'INSERT INTO message_reactions (
                message_id, user_id, reaction_code, is_active, created_at, updated_at
             ) VALUES (
                :message_id, :user_id, :reaction_code, 1, :created_at, :updated_at
             )
             ON DUPLICATE KEY UPDATE
                is_active = IF(is_active = 1, 0, 1),
                updated_at = VALUES(updated_at)',
            [
                ':message_id' => (int) $message['id'],
                ':user_id' => (int) $viewer['id'],
                ':reaction_code' => $reactionCode,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );

        $summary = $this->summaryForUser($viewerUid, $messageUid);
        if ($summary === null) {
            throw new DomainException('Сообщение больше недоступно');
        }

        return [
            'message_uid' => $messageUid,
            'dialog_uid' => (string) $message['dialog_uid'],
            'reactions' => $summary,
        ];
    }

    /** @return list<array{code:string,emoji:string,count:int,reacted_by_me:bool}>|null */
    public function summaryForUser(string $viewerUid, string $messageUid): ?array
    {
        $states = $this->listForUser($viewerUid, [$messageUid]);
        return array_key_exists($messageUid, $states) ? $states[$messageUid] : null;
    }

    /** @return list<string> */
    public function participantUids(string $messageUid): array
    {
        $rows = $this->db->fetchAll(
            'SELECT u.uid
             FROM messages m
             INNER JOIN user_to_dialogs utd
                ON utd.dialog_id = m.dialog_id
               AND utd.is_deleted = 0
             INNER JOIN users u
                ON u.id = utd.user_id
               AND u.is_active = 1
             WHERE m.uid = :message_uid
               AND m.is_deleted = 0
             ORDER BY utd.id ASC',
            [':message_uid' => $messageUid]
        );

        return array_values(array_filter(array_map(
            static fn(array $row): string => (string) ($row['uid'] ?? ''),
            $rows
        )));
    }

    /** @return array<string,mixed> */
    private function activeUser(string $uid): array
    {
        $uid = trim($uid);
        if ($uid === '') {
            throw new DomainException('Пользователь не определён');
        }
        $user = $this->db->fetchOne(
            'SELECT id, uid
             FROM users
             WHERE uid = :uid AND is_active = 1
             LIMIT 1',
            [':uid' => $uid]
        );
        if (!$user) {
            throw new DomainException('Пользователь не найден или заблокирован');
        }
        return $user;
    }

    /** @return array<string,mixed> */
    private function visibleMessage(int $viewerId, string $messageUid): array
    {
        $messageUid = trim($messageUid);
        if ($messageUid === '') {
            throw new InvalidArgumentException('Не указано сообщение');
        }

        $row = $this->db->fetchOne(
            'SELECT m.id, m.uid, d.uid AS dialog_uid
             FROM messages m
             INNER JOIN dialogs d ON d.id = m.dialog_id
             INNER JOIN user_to_dialogs utd
                ON utd.dialog_id = m.dialog_id
               AND utd.user_id = :viewer_id
               AND utd.is_deleted = 0
             WHERE m.uid = :message_uid
               AND m.is_deleted = 0
               AND NOT EXISTS (
                    SELECT 1
                    FROM message_user_deletions mud
                    WHERE mud.message_id = m.id
                      AND mud.user_id = :hidden_viewer_id
               )
             LIMIT 1',
            [
                ':viewer_id' => $viewerId,
                ':hidden_viewer_id' => $viewerId,
                ':message_uid' => $messageUid,
            ]
        );
        if (!$row) {
            throw new DomainException('Сообщение недоступно');
        }
        return $row;
    }

    /** @param list<string> $uids @return list<string> */
    private function normalizeMessageUids(array $uids): array
    {
        $result = [];
        foreach ($uids as $uid) {
            $uid = trim((string) $uid);
            if ($uid === '' || isset($result[$uid])) {
                continue;
            }
            if (strlen($uid) > 64) {
                throw new InvalidArgumentException('Некорректный идентификатор сообщения');
            }
            $result[$uid] = true;
            if (count($result) > self::MAX_MESSAGE_UIDS) {
                throw new InvalidArgumentException('Слишком много сообщений в запросе реакций');
            }
        }
        return array_keys($result);
    }

    /**
     * @param list<string> $values
     * @return array{0:string,1:array<string,string>}
     */
    private function inClause(string $prefix, array $values): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($values) as $index => $value) {
            $placeholder = ':' . $prefix . '_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = (string) $value;
        }
        return [implode(',', $placeholders), $params];
    }
}
