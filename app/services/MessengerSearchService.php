<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\MessengerCrypto;
use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;

final class MessengerSearchService
{
    private const DEFAULT_SCAN_LIMIT = 1000;
    private const MAX_SCAN_LIMIT = 5000;
    private const MAX_RESULTS = 50;

    public function __construct(
        private ?DatabaseManager $db = null,
        private ?MessengerService $messenger = null
    ) {
        $this->db ??= DatabaseManager::getInstance();
        $this->messenger ??= new MessengerService($this->db);
    }

    /** @return list<array<string,mixed>> */
    public function dialogs(string $userUid, string $query, int $limit = 20): array
    {
        $needle = $this->query($query);
        $limit = max(1, min(50, $limit));
        $results = [];

        foreach ($this->messenger->listDialogs($userUid) as $dialog) {
            $haystack = trim(
                (string) ($dialog['title'] ?? '') . ' ' .
                (string) ($dialog['last_message_preview'] ?? '')
            );
            if (mb_stripos($haystack, $needle, 0, 'UTF-8') === false) {
                continue;
            }

            $results[] = $dialog;
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /** @return list<array<string,mixed>> */
    public function messages(
        string $userUid,
        string $query,
        ?string $dialogUid = null,
        int $limit = 30
    ): array {
        $needle = $this->query($query);
        $limit = max(1, min(self::MAX_RESULTS, $limit));
        $user = $this->db->fetchOne(
            'SELECT id
             FROM users
             WHERE uid = :uid AND is_active = 1
             LIMIT 1',
            [':uid' => $userUid]
        );
        if (!$user) {
            throw new DomainException('Пользователь не найден или заблокирован');
        }

        $viewerId = (int) $user['id'];
        $scanLimit = $this->scanLimit();

        $params = [
            ':viewer_id' => $viewerId,
            ':hidden_viewer_id' => $viewerId,
            ':partner_viewer_id' => $viewerId,
        ];
        $dialogFilter = '';
        if ($dialogUid !== null && trim($dialogUid) !== '') {
            $dialogUid = trim($dialogUid);
            $dialogFilter = ' AND d.uid = :dialog_uid';
            $params[':dialog_uid'] = $dialogUid;
        }

        $rows = $this->db->fetchAll(
            'SELECT
                m.id,
                m.uid,
                m.message,
                m.message_type,
                m.meta_data,
                m.created_at,
                d.uid AS dialog_uid,
                d.type AS dialog_type,
                d.name AS dialog_name,
                sender.uid AS sender_uid,
                sender.username AS sender_username,
                sender.firstname AS sender_firstname,
                sender.lastname AS sender_lastname,
                CASE
                    WHEN d.type = "group" THEN COALESCE(NULLIF(TRIM(d.name), ""), "Групповой чат")
                    ELSE COALESCE((
                        SELECT NULLIF(TRIM(CONCAT_WS(" ", partner.firstname, partner.lastname)), "")
                        FROM user_to_dialogs partner_membership
                        INNER JOIN users partner ON partner.id = partner_membership.user_id
                        WHERE partner_membership.dialog_id = d.id
                          AND partner_membership.is_deleted = 0
                          AND partner.id <> :partner_viewer_id
                        ORDER BY partner_membership.id ASC
                        LIMIT 1
                    ), "Диалог")
                END AS dialog_title
             FROM messages m
             INNER JOIN user_to_dialogs viewer_membership
                ON viewer_membership.dialog_id = m.dialog_id
               AND viewer_membership.user_id = :viewer_id
               AND viewer_membership.is_deleted = 0
             INNER JOIN dialogs d ON d.id = m.dialog_id
             INNER JOIN users sender ON sender.id = m.from_user_id
             WHERE m.is_deleted = 0
               AND NOT EXISTS (
                   SELECT 1
                   FROM message_user_deletions mud
                   WHERE mud.message_id = m.id
                     AND mud.user_id = :hidden_viewer_id
               )'
             . $dialogFilter .
             ' ORDER BY m.id DESC
               LIMIT ' . $scanLimit,
            $params
        );

        $results = [];
        foreach ($rows as $row) {
            $text = '';
            try {
                $text = MessengerCrypto::decrypt((string) $row['message'], (string) $row['uid']);
            } catch (\Throwable $e) {
                error_log('Messenger search decrypt failed for ' . (string) $row['uid'] . ': ' . $e->getMessage());
                continue;
            }

            $metadata = [];
            if (!empty($row['meta_data'])) {
                $decoded = json_decode((string) $row['meta_data'], true);
                if (is_array($decoded)) {
                    $metadata = $decoded;
                }
            }

            $fileName = trim((string) ($metadata['name'] ?? ''));
            $typeLabel = $this->mediaLabel((string) ($row['message_type'] ?? 'text'));
            $haystack = trim($text . ' ' . $fileName . ' ' . $typeLabel);
            if (mb_stripos($haystack, $needle, 0, 'UTF-8') === false) {
                continue;
            }

            $previewSource = $text !== '' ? $text : ($fileName !== '' ? $fileName : $typeLabel);
            $results[] = [
                'id' => (int) $row['id'],
                'uid' => (string) $row['uid'],
                'dialog_uid' => (string) $row['dialog_uid'],
                'dialog_type' => (string) $row['dialog_type'],
                'dialog_title' => (string) ($row['dialog_title'] ?? 'Диалог'),
                'message_type' => (string) ($row['message_type'] ?? 'text'),
                'preview' => $this->excerpt($previewSource, $needle),
                'created_at' => (string) $row['created_at'],
                'user' => [
                    'uid' => (string) $row['sender_uid'],
                    'username' => (string) ($row['sender_username'] ?? ''),
                    'firstname' => (string) ($row['sender_firstname'] ?? ''),
                    'lastname' => (string) ($row['sender_lastname'] ?? ''),
                ],
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    private function query(string $query): string
    {
        $query = trim((string) preg_replace('/\s+/u', ' ', $query));
        $length = mb_strlen($query);
        if ($length < 2) {
            throw new InvalidArgumentException('Для поиска введите минимум 2 символа');
        }
        if ($length > 128) {
            throw new InvalidArgumentException('Поисковый запрос слишком длинный');
        }
        return $query;
    }

    private function scanLimit(): int
    {
        $configured = getenv('MESSENGER_SEARCH_SCAN_LIMIT');
        $value = is_string($configured) && ctype_digit($configured)
            ? (int) $configured
            : self::DEFAULT_SCAN_LIMIT;
        return max(100, min(self::MAX_SCAN_LIMIT, $value));
    }

    private function excerpt(string $text, string $needle): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return '';
        }

        $position = mb_stripos($text, $needle, 0, 'UTF-8');
        if ($position === false) {
            return mb_strlen($text) > 180 ? mb_substr($text, 0, 179) . '…' : $text;
        }

        $start = max(0, $position - 60);
        $excerpt = mb_substr($text, $start, 180);
        if ($start > 0) {
            $excerpt = '…' . ltrim($excerpt);
        }
        if ($start + 180 < mb_strlen($text)) {
            $excerpt = rtrim($excerpt) . '…';
        }
        return $excerpt;
    }

    private function mediaLabel(string $type): string
    {
        return match ($type) {
            'image' => 'Изображение фото картинка',
            'audio' => 'Аудио музыка',
            'video' => 'Видео',
            'voice' => 'Голосовое сообщение',
            'file' => 'Файл документ',
            'service' => 'Системное сообщение',
            default => '',
        };
    }
}
