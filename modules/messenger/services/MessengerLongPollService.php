<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Core\DatabaseManager;
use JsonException;
use RuntimeException;

final class MessengerLongPollService
{
    private const DEFAULT_TIMEOUT_SECONDS = 15;
    private const MIN_TIMEOUT_SECONDS = 5;
    private const MAX_TIMEOUT_SECONDS = 25;
    private const POLL_INTERVAL_MICROSECONDS = 750000;

    public function __construct(
        private ?DatabaseManager $db = null,
        private ?Closure $fingerprintProvider = null,
        private ?Closure $clock = null,
        private ?Closure $sleeper = null
    ) {
        if ($this->db === null && $this->fingerprintProvider === null) {
            $this->db = DatabaseManager::getInstance();
        }
    }

    /**
     * Ждёт изменения устойчивого состояния Messenger, видимого пользователю.
     *
     * Отпечаток покрывает диалоги, membership/read cursors, сообщения,
     * реакции и персональные удаления. Это делает резервный транспорт
     * независимым от памяти WebSocket-процесса.
     *
     * @param callable():bool|null $aborted
     * @return array{cursor:string,changed:bool}
     */
    public function waitForChange(int $userId, string $cursor, ?callable $aborted = null): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Некорректный пользователь Long Poll');
        }

        $deadline = $this->now() + $this->timeoutSeconds();
        do {
            $next = $this->fingerprint($userId);
            if ($cursor === '' || !hash_equals($cursor, $next)) {
                return ['cursor' => $next, 'changed' => true];
            }

            if ($aborted !== null && $aborted()) {
                return ['cursor' => $next, 'changed' => false];
            }

            $this->sleep();
        } while ($this->now() < $deadline);

        // После последней паузы состояние могло измениться. Нельзя продвигать
        // cursor и одновременно сообщать changed=false: клиент тогда навсегда
        // пропустит это изменение до следующего события.
        $final = $this->fingerprint($userId);
        return [
            'cursor' => $final,
            'changed' => $cursor === '' || !hash_equals($cursor, $final),
        ];
    }

    public function fingerprint(int $userId): string
    {
        if ($this->fingerprintProvider !== null) {
            $value = ($this->fingerprintProvider)($userId);
            if (!is_string($value) || $value === '') {
                throw new RuntimeException('Провайдер отпечатка Long Poll вернул некорректное значение');
            }
            return $value;
        }

        if ($this->db === null) {
            throw new RuntimeException('База данных Long Poll не инициализирована');
        }

        $state = $this->db->fetchOne(
            'SELECT
                (
                    SELECT COALESCE(SUM(CRC32(CONCAT_WS("|",
                        d.id,d.uid,d.type,COALESCE(d.name,""),COALESCE(d.avatar,""),d.updated_at
                    ))),0)
                    FROM dialogs d
                    INNER JOIN user_to_dialogs me ON me.dialog_id = d.id
                    WHERE me.user_id = :dialog_user_id AND me.is_deleted = 0
                ) AS dialogs_state,
                (
                    SELECT COALESCE(SUM(CRC32(CONCAT_WS("|",
                        member.id,member.dialog_id,member.user_id,member.role,member.is_deleted,
                        COALESCE(member.last_delivered_message_id,0),
                        COALESCE(member.last_read_message_id,0),
                        COALESCE(member.archived_at,""),
                        COALESCE(member.muted_until,""),
                        COALESCE(member.pinned_at,"")
                    ))),0)
                    FROM user_to_dialogs member
                    WHERE member.dialog_id IN (
                        SELECT me.dialog_id
                        FROM user_to_dialogs me
                        WHERE me.user_id = :membership_user_id AND me.is_deleted = 0
                    )
                ) AS membership_state,
                (
                    SELECT COALESCE(SUM(CRC32(CONCAT_WS("|",
                        m.id,m.uid,m.is_deleted,m.message_status,
                        COALESCE(m.edited_at,""),COALESCE(m.deleted_at,""),m.updated_at,
                        CRC32(COALESCE(m.message,"")),
                        COALESCE(m.media_url,""),
                        COALESCE(CAST(m.meta_data AS CHAR),"")
                    ))),0)
                    FROM messages m
                    WHERE m.dialog_id IN (
                        SELECT me.dialog_id
                        FROM user_to_dialogs me
                        WHERE me.user_id = :message_user_id AND me.is_deleted = 0
                    )
                ) AS messages_state,
                (
                    SELECT COALESCE(SUM(CRC32(CONCAT_WS("|",
                        mr.id,mr.message_id,mr.user_id,mr.reaction_code,mr.is_active,mr.updated_at
                    ))),0)
                    FROM message_reactions mr
                    INNER JOIN messages rm ON rm.id = mr.message_id
                    WHERE rm.dialog_id IN (
                        SELECT me.dialog_id
                        FROM user_to_dialogs me
                        WHERE me.user_id = :reaction_user_id AND me.is_deleted = 0
                    )
                ) AS reactions_state,
                (
                    SELECT COALESCE(SUM(CRC32(CONCAT_WS("|",
                        mud.message_id,mud.user_id,mud.deleted_at
                    ))),0)
                    FROM message_user_deletions mud
                    WHERE mud.user_id = :deletion_user_id
                ) AS deletions_state',
            [
                ':dialog_user_id' => $userId,
                ':membership_user_id' => $userId,
                ':message_user_id' => $userId,
                ':reaction_user_id' => $userId,
                ':deletion_user_id' => $userId,
            ]
        ) ?? [];

        try {
            $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new RuntimeException('Не удалось закодировать состояние Messenger Long Poll', 0, $e);
        }

        return hash('sha256', $encoded);
    }

    private function now(): float
    {
        return $this->clock !== null ? (float) ($this->clock)() : microtime(true);
    }

    private function sleep(): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)(self::POLL_INTERVAL_MICROSECONDS);
            return;
        }

        usleep(self::POLL_INTERVAL_MICROSECONDS);
    }

    private function timeoutSeconds(): int
    {
        $raw = trim((string) (getenv('MESSENGER_LONG_POLL_TIMEOUT_SECONDS') ?: ''));
        $timeout = ctype_digit($raw) ? (int) $raw : self::DEFAULT_TIMEOUT_SECONDS;
        return max(self::MIN_TIMEOUT_SECONDS, min(self::MAX_TIMEOUT_SECONDS, $timeout));
    }
}
