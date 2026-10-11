<?php

declare(strict_types=1);

namespace Modules\Calendar;

use DateTimeImmutable;
use Modules\Calendar\Services\CalendarReminderPlanner;
use Modules\Calendar\Services\CalendarReminderPublisher;
use Modules\Calendar\Services\CalendarReminderScheduler;

final class CalendarCapability
{
    public function __construct(
        private ?CalendarReminderScheduler $scheduler = null,
        private ?CalendarReminderPublisher $publisher = null,
        private ?CalendarReminderPlanner $planner = null
    ) {
    }

    /** @param list<int> $offsetSeconds @return list<int> */
    public function scheduleOccurrenceReminders(
        int $eventId,
        DateTimeImmutable $occurrenceStart,
        array $offsetSeconds
    ): array {
        return $this->scheduler()->scheduleOccurrence($eventId, $occurrenceStart, $offsetSeconds);
    }

    /** @param list<DateTimeImmutable> $occurrences @param list<int> $offsetSeconds @return list<int> */
    public function scheduleRecurringOccurrenceReminders(
        int $eventId,
        array $occurrences,
        array $offsetSeconds
    ): array {
        return $this->scheduler()->scheduleOccurrences($eventId, $occurrences, $offsetSeconds);
    }

    /** @return array{selected:int,scheduled:int,failed:int} */
    public function maintainUpcomingReminders(int $limit = 20, ?DateTimeImmutable $now = null): array
    {
        return $this->planner()->maintainUpcoming($limit, $now);
    }

    /** @return array{locked:bool,throttled:bool,selected:int,published:int,failed:int,expired:int} */
    public function publishDueReminders(
        int $limit = 20,
        int $catchUpSeconds = 604800,
        ?DateTimeImmutable $now = null
    ): array {
        return $this->publisher()->run($limit, $catchUpSeconds, $now);
    }

    /** @return array{locked:bool,throttled:bool,selected:int,published:int,failed:int,expired:int} */
    public function runHttpFallback(?DateTimeImmutable $now = null): array
    {
        $result = $this->publisher()->runHttpFallback($now);
        if (!$result['throttled'] && !$result['locked']) {
            $this->planner()->maintainUpcoming(10, $now);
        }
        return $result;
    }

    private function scheduler(): CalendarReminderScheduler
    {
        return $this->scheduler ??= new CalendarReminderScheduler();
    }

    private function publisher(): CalendarReminderPublisher
    {
        return $this->publisher ??= new CalendarReminderPublisher();
    }

    private function planner(): CalendarReminderPlanner
    {
        return $this->planner ??= new CalendarReminderPlanner();
    }
}
