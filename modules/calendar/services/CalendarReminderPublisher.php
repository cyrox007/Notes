<?php

declare(strict_types=1);

namespace Modules\Calendar\Services;

use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\NotificationService;
use Core\DatabaseManager;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class CalendarReminderPublisher
{
    private const LOCK_NAME = 'workspace.calendar.reminders.publisher';
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;
    private const DEFAULT_CATCH_UP_SECONDS = 604800;
    private const MAX_CATCH_UP_SECONDS = 2592000;
    private const HTTP_LIMIT = 3;
    private const HTTP_INTERVAL_SECONDS = 60;
    private const HTTP_STATE_KEY = 'reminder_http_fallback';

    public function __construct(
        private ?DatabaseManager $db = null,
        private ?NotificationService $notifications = null
    ) {
        $this->db ??= DatabaseManager::getInstance();
        $this->notifications ??= new NotificationService();
    }

    /** @return array{locked:bool,throttled:bool,selected:int,published:int,failed:int,expired:int} */
    public function run(
        int $limit = self::DEFAULT_LIMIT,
        int $catchUpSeconds = self::DEFAULT_CATCH_UP_SECONDS,
        ?DateTimeImmutable $now = null
    ): array {
        $limit = $this->limit($limit);
        $catchUpSeconds = $this->catchUpSeconds($catchUpSeconds);
        $now = $this->utc($now);

        if (!$this->acquireLock()) {
            return $this->result(locked: true);
        }

        try {
            return $this->processLocked($limit, $catchUpSeconds, $now);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Резервный проход для shared-hosting. Обычный HTTP-запрос выполняет не
     * более трёх напоминаний и не чаще одного раза в минуту на всю установку.
     *
     * @return array{locked:bool,throttled:bool,selected:int,published:int,failed:int,expired:int}
     */
    public function runHttpFallback(?DateTimeImmutable $now = null): array
    {
        $now = $this->utc($now);
        if (!$this->httpFallbackDue($now)) {
            return $this->result(throttled: true);
        }
        if (!$this->acquireLock()) {
            return $this->result(locked: true);
        }

        try {
            if (!$this->httpFallbackDue($now)) {
                return $this->result(throttled: true);
            }
            $this->rememberHttpRun($now);
            return $this->processLocked(self::HTTP_LIMIT, self::DEFAULT_CATCH_UP_SECONDS, $now);
        } finally {
            $this->releaseLock();
        }
    }

    /** @return array{locked:bool,throttled:bool,selected:int,published:int,failed:int,expired:int} */
    private function processLocked(int $limit, int $catchUpSeconds, DateTimeImmutable $now): array
    {
        $nowSql = $now->format('Y-m-d H:i:s');
        $cutoff = $now->modify('-' . $catchUpSeconds . ' seconds')->format('Y-m-d H:i:s');
        $expired = (int) $this->db->execute(
            "UPDATE calendar_reminders r\n"
            . "INNER JOIN calendar_events e ON e.id = r.event_id\n"
            . "SET r.state = 'expired', r.expired_at = :expired_at, r.last_error_code = NULL\n"
            . "WHERE r.state = 'pending'\n"
            . "AND (r.remind_at_utc < :cutoff OR e.status <> 'active')",
            [':expired_at' => $nowSql, ':cutoff' => $cutoff]
        );

        $rows = $this->db->fetchAll(
            'SELECT r.id, r.event_id, r.occurrence_start_utc, r.remind_at_utc, r.offset_seconds, '
            . 'e.uid AS event_uid, e.owner_user_id, e.title, e.timezone '
            . 'FROM calendar_reminders r '
            . 'INNER JOIN calendar_events e ON e.id = r.event_id '
            . "WHERE r.state = 'pending' AND e.status = 'active' "
            . 'AND r.remind_at_utc >= :cutoff AND r.remind_at_utc <= :now '
            . 'ORDER BY r.remind_at_utc ASC, r.id ASC '
            . 'LIMIT ' . $limit,
            [':cutoff' => $cutoff, ':now' => $nowSql]
        );

        $published = 0;
        $failed = 0;
        foreach ($rows as $row) {
            try {
                $this->publishRow($row);
                $this->markPublished((int) $row['id'], $nowSql);
                $published++;
            } catch (Throwable) {
                $this->markFailedAttempt((int) $row['id'], $nowSql);
                $failed++;
            }
        }

        return [
            'locked' => false,
            'throttled' => false,
            'selected' => count($rows),
            'published' => $published,
            'failed' => $failed,
            'expired' => $expired,
        ];
    }

    /** @param array<string,mixed> $row */
    private function publishRow(array $row): void
    {
        $eventUid = (string) ($row['event_uid'] ?? '');
        $occurrence = new DateTimeImmutable((string) $row['occurrence_start_utc'], new DateTimeZone('UTC'));
        $remindAt = new DateTimeImmutable((string) $row['remind_at_utc'], new DateTimeZone('UTC'));
        $offset = (int) ($row['offset_seconds'] ?? 0);
        $timezone = $this->eventTimezone((string) ($row['timezone'] ?? 'UTC'));
        $localOccurrence = $occurrence->setTimezone($timezone);
        $eventTitle = $this->limitText(trim((string) ($row['title'] ?? '')), 170);
        if ($eventTitle === '') {
            $eventTitle = 'Событие календаря';
        }

        $idempotencyKey = sprintf(
            'calendar.reminder:%s:%s:%d',
            $eventUid,
            $occurrence->format('Ymd\\THis\\Z'),
            $offset
        );
        $body = sprintf(
            'Начало события: %s (%s).',
            $localOccurrence->format('d.m.Y H:i'),
            $timezone->getName()
        );

        $this->notifications->publish(new NotificationEvent(
            'calendar.reminder_due',
            1,
            $idempotencyKey,
            'calendar',
            'calendar.reminders',
            NotificationEvent::IMPORTANCE_NORMAL,
            'Напоминание: ' . $eventTitle,
            $body,
            [(int) $row['owner_user_id']],
            [
                'event_uid' => $eventUid,
                'occurrence_start_utc' => $occurrence->format('Y-m-d\\TH:i:s\\Z'),
                'timezone' => $timezone->getName(),
                'offset_seconds' => $offset,
            ],
            '/calendar?event=' . rawurlencode($eventUid),
            $remindAt
        ));
    }

    private function markPublished(int $reminderId, string $nowSql): void
    {
        $this->db->execute(
            "UPDATE calendar_reminders SET state = 'published', published_at = :published_at, "
            . 'publish_attempts = publish_attempts + 1, last_attempt_at = :last_attempt_at, '
            . 'last_error_code = NULL '
            . "WHERE id = :id AND state = 'pending'",
            [':published_at' => $nowSql, ':last_attempt_at' => $nowSql, ':id' => $reminderId]
        );
    }

    private function markFailedAttempt(int $reminderId, string $nowSql): void
    {
        $this->db->execute(
            'UPDATE calendar_reminders SET publish_attempts = publish_attempts + 1, '
            . 'last_attempt_at = :last_attempt_at, last_error_code = :last_error_code '
            . "WHERE id = :id AND state = 'pending'",
            [
                ':last_attempt_at' => $nowSql,
                ':last_error_code' => 'notification_publish_failed',
                ':id' => $reminderId,
            ]
        );
    }

    private function httpFallbackDue(DateTimeImmutable $now): bool
    {
        $last = $this->db->fetchValue(
            'SELECT last_http_run_at FROM calendar_runtime_state WHERE state_key = :state_key LIMIT 1',
            [':state_key' => self::HTTP_STATE_KEY]
        );
        if (!is_string($last) || trim($last) === '') {
            return true;
        }

        $lastRun = new DateTimeImmutable($last, new DateTimeZone('UTC'));
        return $lastRun->modify('+' . self::HTTP_INTERVAL_SECONDS . ' seconds') <= $now;
    }

    private function rememberHttpRun(DateTimeImmutable $now): void
    {
        $this->db->execute(
            'INSERT INTO calendar_runtime_state (state_key, last_http_run_at) '
            . 'VALUES (:state_key, :last_http_run_at) '
            . 'ON DUPLICATE KEY UPDATE last_http_run_at = VALUES(last_http_run_at)',
            [
                ':state_key' => self::HTTP_STATE_KEY,
                ':last_http_run_at' => $now->format('Y-m-d H:i:s'),
            ]
        );
    }

    private function acquireLock(): bool
    {
        return (int) $this->db->fetchValue(
            'SELECT GET_LOCK(:lock_name, 0)',
            [':lock_name' => self::LOCK_NAME]
        ) === 1;
    }

    private function releaseLock(): void
    {
        try {
            $this->db->fetchValue(
                'SELECT RELEASE_LOCK(:lock_name)',
                [':lock_name' => self::LOCK_NAME]
            );
        } catch (Throwable) {
            // Именованная блокировка также освобождается при закрытии соединения.
        }
    }

    private function utc(?DateTimeImmutable $value): DateTimeImmutable
    {
        return ($value ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private function eventTimezone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name !== '' ? $name : 'UTC');
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    private function limit(int $limit): int
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('Размер прохода должен быть в диапазоне 1..100');
        }
        return $limit;
    }

    private function catchUpSeconds(int $seconds): int
    {
        if ($seconds < 60 || $seconds > self::MAX_CATCH_UP_SECONDS) {
            throw new InvalidArgumentException('Окно восстановления должно быть от 60 до 2592000 секунд');
        }
        return $seconds;
    }

    private function limitText(string $text, int $limit): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit);
        }
        return substr($text, 0, $limit);
    }

    /** @return array{locked:bool,throttled:bool,selected:int,published:int,failed:int,expired:int} */
    private function result(bool $locked = false, bool $throttled = false): array
    {
        return [
            'locked' => $locked,
            'throttled' => $throttled,
            'selected' => 0,
            'published' => 0,
            'failed' => 0,
            'expired' => 0,
        ];
    }
}
