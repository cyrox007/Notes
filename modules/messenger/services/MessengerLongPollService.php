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
    private const FULL_FINGERPRINT_INTERVAL_SECONDS = 5.0;
    private const REVISION_SETTING_KEY = 'messenger_realtime_revision';

    public function __construct(
        private ?DatabaseManager $db = null,
        private ?Closure $fingerprintProvider = null,
        private ?Closure $clock = null,
        private ?Closure $sleeper = null,
        private ?Closure $revisionProvider = null,
        private ?Closure $activityFingerprintProvider = null
    ) {
        if ($this->db === null && $this->fingerprintProvider === null) {
            $this->db = DatabaseManager::getInstance();
        }
    }

    /**
     * Ждёт изменения состояния Messenger, видимого пользователю.
     *
     * На каждом коротком тике читаются только общая realtime-ревизия и
     * короткоживущая activity-таблица. Полный fingerprint выполняется при
     * изменении сигнала и периодически как страховка для редких записей,
     * прошедших в обход realtime-dispatcher.
     *
     * @param callable():bool|null $aborted
     * @return array{cursor:string,changed:bool,revision:int,activity_cursor:string}
     */
    public function waitForChange(
        int $userId,
        string $cursor,
        ?callable $aborted = null,
        ?int $revision = null,
        string $activityCursor = '',
        ?int $requestedTimeoutSeconds = null
    ): array {
        if ($userId <= 0) {
            throw new RuntimeException('Некорректный пользователь Long Poll');
        }

        $deadline = $this->now() + $this->timeoutSeconds($requestedTimeoutSeconds);
        $lastFullCheckAt = $this->now();
        $knownRevision = $revision ?? $this->revision();
        $knownActivity = $activityCursor !== ''
            ? $activityCursor
            : $this->activityFingerprint($userId);
        $needsBaseline = $revision === null || $activityCursor === '';

        if ($cursor === '') {
            $next = $this->fingerprint($userId);
            return [
                'cursor' => $next,
                'changed' => true,
                'revision' => $knownRevision,
                'activity_cursor' => $knownActivity,
            ];
        }

        do {
            $nextRevision = $this->revision();
            $nextActivity = $this->activityFingerprint($userId);
            $now = $this->now();
            $signalChanged = $nextRevision !== $knownRevision
                || !hash_equals($knownActivity, $nextActivity);
            $fullScanDue = ($now - $lastFullCheckAt) >= self::FULL_FINGERPRINT_INTERVAL_SECONDS;

            if ($needsBaseline || $signalChanged || $fullScanDue) {
                $next = $this->fingerprint($userId);
                $changed = !hash_equals($cursor, $next);

                $knownRevision = $nextRevision;
                $knownActivity = $nextActivity;
                $lastFullCheckAt = $now;
                $needsBaseline = false;

                if ($changed) {
                    return [
                        'cursor' => $next,
                        'changed' => true,
                        'revision' => $knownRevision,
                        'activity_cursor' => $knownActivity,
                    ];
                }
            }

            if ($aborted !== null && $aborted()) {
                return [
                    'cursor' => $cursor,
                    'changed' => false,
                    'revision' => $knownRevision,
                    'activity_cursor' => $knownActivity,
                ];
            }

            $this->sleep();
        } while ($this->now() < $deadline);

        // Финальная полная сверка закрывает изменение на границе timeout и
        // сохраняет совместимость с путями записи, которые ещё не публикуют
        // realtime revision.
        $final = $this->fingerprint($userId);
        $finalRevision = $this->revision();
        $finalActivity = $this->activityFingerprint($userId);

        return [
            'cursor' => $final,
            'changed' => !hash_equals($cursor, $final),
            'revision' => $finalRevision,
            'activity_cursor' => $finalActivity,
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
                ) AS deletions_state,
                (
                    SELECT COALESCE(SUM(CRC32(CONCAT_WS("|",
                        ma.dialog_id,ma.user_id,ma.activity,
                        DATE_FORMAT(ma.expires_at, "%Y-%m-%d %H:%i:%s.%f")
                    ))),0)
                    FROM messenger_activity ma
                    WHERE ma.dialog_id IN (
                        SELECT me.dialog_id
                        FROM user_to_dialogs me
                        WHERE me.user_id = :activity_user_id AND me.is_deleted = 0
                    )
                      AND ma.expires_at > CURRENT_TIMESTAMP(3)
                ) AS activity_state',
            [
                ':dialog_user_id' => $userId,
                ':membership_user_id' => $userId,
                ':message_user_id' => $userId,
                ':reaction_user_id' => $userId,
                ':deletion_user_id' => $userId,
                ':activity_user_id' => $userId,
            ]
        ) ?? [];

        try {
            $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new RuntimeException('Не удалось закодировать состояние Messenger Long Poll', 0, $e);
        }

        return hash('sha256', $encoded);
    }

    private function revision(): int
    {
        if ($this->revisionProvider !== null) {
            $value = ($this->revisionProvider)();
            return is_int($value) && $value >= 0 ? $value : 0;
        }

        if ($this->db === null) {
            return 0;
        }

        $row = $this->db->fetchOne(
            'SELECT setting_value
             FROM system_settings
             WHERE setting_key = :setting_key
             LIMIT 1',
            [':setting_key' => self::REVISION_SETTING_KEY]
        );

        $value = trim((string) ($row['setting_value'] ?? '0'));
        return ctype_digit($value) ? (int) $value : 0;
    }

    private function activityFingerprint(int $userId): string
    {
        if ($this->activityFingerprintProvider !== null) {
            $value = ($this->activityFingerprintProvider)($userId);
            return is_string($value) && $value !== '' ? $value : hash('sha256', '');
        }

        if ($this->db === null) {
            return hash('sha256', '');
        }

        $rows = $this->db->fetchAll(
            'SELECT
                ma.dialog_id,
                ma.user_id,
                ma.activity,
                DATE_FORMAT(ma.expires_at, "%Y-%m-%d %H:%i:%s.%f") AS expires_at
             FROM messenger_activity ma
             INNER JOIN user_to_dialogs me
                ON me.dialog_id = ma.dialog_id
               AND me.user_id = :user_id
               AND me.is_deleted = 0
             WHERE ma.expires_at > CURRENT_TIMESTAMP(3)
             ORDER BY ma.dialog_id, ma.user_id, ma.activity',
            [':user_id' => $userId]
        );

        try {
            $encoded = json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new RuntimeException('Не удалось закодировать activity-состояние Messenger', 0, $e);
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

    private function timeoutSeconds(?int $requestedTimeoutSeconds = null): int
    {
        if ($requestedTimeoutSeconds !== null) {
            return max(
                self::MIN_TIMEOUT_SECONDS,
                min(self::MAX_TIMEOUT_SECONDS, $requestedTimeoutSeconds)
            );
        }

        $raw = trim((string) (getenv('MESSENGER_LONG_POLL_TIMEOUT_SECONDS') ?: ''));
        $timeout = ctype_digit($raw) ? (int) $raw : self::DEFAULT_TIMEOUT_SECONDS;
        return max(self::MIN_TIMEOUT_SECONDS, min(self::MAX_TIMEOUT_SECONDS, $timeout));
    }
}
