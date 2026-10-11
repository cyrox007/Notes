<?php

declare(strict_types=1);

namespace Modules\Calendar\Services;

use Core\DatabaseManager;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class CalendarReminderPlanner
{
    private const HORIZON_DAYS = 60;
    private const REFILL_THRESHOLD_DAYS = 14;
    private const MAX_EVENTS_PER_PASS = 50;

    public function __construct(
        private ?DatabaseManager $db = null,
        private ?CalendarReminderScheduler $scheduler = null,
        private ?CalendarRecurrence $recurrence = null
    ) {
        $this->db ??= DatabaseManager::getInstance();
        $this->scheduler ??= new CalendarReminderScheduler($this->db);
        $this->recurrence ??= new CalendarRecurrence();
    }

    public function refreshEvent(int $eventId, ?DateTimeImmutable $now = null): int
    {
        if ($eventId <= 0) {
            throw new InvalidArgumentException('Идентификатор события должен быть положительным');
        }

        $event = $this->db->fetchOne(
            'SELECT id, starts_at_utc, timezone, recurrence_rule, status FROM calendar_events WHERE id = :id LIMIT 1',
            [':id' => $eventId]
        );
        if (!is_array($event) || (string) ($event['status'] ?? '') !== 'active') {
            return 0;
        }

        $offsetRows = $this->db->fetchAll(
            'SELECT offset_seconds FROM calendar_event_reminder_offsets WHERE event_id = :event_id ORDER BY offset_seconds ASC',
            [':event_id' => $eventId]
        );
        $offsets = array_values(array_map(static fn (array $row): int => (int) $row['offset_seconds'], $offsetRows));
        if ($offsets === []) {
            return 0;
        }

        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable((string) $event['starts_at_utc'], $utc);
        $rule = isset($event['recurrence_rule']) && trim((string) $event['recurrence_rule']) !== ''
            ? (string) $event['recurrence_rule']
            : null;

        if ($rule === null) {
            return count($this->scheduler->scheduleOccurrence($eventId, $start, $offsets));
        }

        $now = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        $from = $now->modify('-7 days');
        if ($from < $start) {
            $from = $start;
        }
        $to = $now->modify('+' . self::HORIZON_DAYS . ' days');
        $occurrences = $this->recurrence->occurrences(
            $start,
            (string) ($event['timezone'] ?? 'UTC'),
            $rule,
            $from,
            $to,
            500
        );
        if ($occurrences === []) {
            return 0;
        }

        $scheduled = 0;
        foreach (array_chunk($occurrences, 100) as $chunk) {
            $scheduled += count($this->scheduler->scheduleOccurrences($eventId, $chunk, $offsets));
        }
        return $scheduled;
    }

    /** @return array{selected:int,scheduled:int,failed:int} */
    public function maintainUpcoming(int $limit = 20, ?DateTimeImmutable $now = null): array
    {
        if ($limit < 1 || $limit > self::MAX_EVENTS_PER_PASS) {
            throw new InvalidArgumentException('За один проход допускается от 1 до 50 событий Calendar');
        }
        $utc = new DateTimeZone('UTC');
        $now = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        $threshold = $now->modify('+' . self::REFILL_THRESHOLD_DAYS . ' days')->format('Y-m-d H:i:s');
        $catchUpFloor = $now->modify('-7 days')->format('Y-m-d H:i:s');

        $rows = $this->db->fetchAll(
            'SELECT e.id '
            . 'FROM calendar_events e '
            . "WHERE e.status = 'active' "
            . 'AND EXISTS (SELECT 1 FROM calendar_event_reminder_offsets o WHERE o.event_id = e.id) '
            . 'AND ('
            . "(e.recurrence_rule IS NULL AND e.starts_at_utc >= :catch_up_floor AND NOT EXISTS (SELECT 1 FROM calendar_reminders r0 WHERE r0.event_id = e.id)) "
            . 'OR '
            . "(e.recurrence_rule IS NOT NULL AND COALESCE((SELECT MAX(r.occurrence_start_utc) FROM calendar_reminders r "
            . "WHERE r.event_id = e.id AND r.state <> 'expired'), '1970-01-01 00:00:00') < :threshold)"
            . ') ORDER BY e.id ASC LIMIT ' . $limit,
            [':threshold' => $threshold, ':catch_up_floor' => $catchUpFloor]
        );

        $scheduled = 0;
        $failed = 0;
        foreach ($rows as $row) {
            try {
                $scheduled += $this->refreshEvent((int) $row['id'], $now);
            } catch (Throwable) {
                $failed++;
            }
        }

        return ['selected' => count($rows), 'scheduled' => $scheduled, 'failed' => $failed];
    }
}
