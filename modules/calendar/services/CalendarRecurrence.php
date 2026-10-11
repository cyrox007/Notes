<?php

declare(strict_types=1);

namespace Modules\Calendar\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class CalendarRecurrence
{
    private const FREQUENCIES = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];
    private const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    /** @param array<string,mixed>|null $definition */
    public function encode(?array $definition, DateTimeZone $timezone): ?string
    {
        if ($definition === null || ($definition['frequency'] ?? 'none') === 'none') {
            return null;
        }

        $frequency = strtoupper(trim((string) ($definition['frequency'] ?? '')));
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            throw new InvalidArgumentException('Неизвестная частота повторения');
        }

        $interval = (int) ($definition['interval'] ?? 1);
        if ($interval < 1 || $interval > 365) {
            throw new InvalidArgumentException('Интервал повторения должен быть от 1 до 365');
        }

        $parts = ['FREQ=' . $frequency, 'INTERVAL=' . $interval];
        if ($frequency === 'WEEKLY') {
            $weekdays = $this->normalizeWeekdays($definition['weekdays'] ?? []);
            if ($weekdays !== []) {
                $parts[] = 'BYDAY=' . implode(',', $weekdays);
            }
        }

        $until = trim((string) ($definition['until'] ?? ''));
        if ($until !== '') {
            $untilLocal = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $until . ' 23:59:59', $timezone);
            if (!$untilLocal instanceof DateTimeImmutable || $untilLocal->format('Y-m-d') !== $until) {
                throw new InvalidArgumentException('Дата окончания повторения имеет неверный формат');
            }
            $parts[] = 'UNTIL=' . $untilLocal->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        }

        return implode(';', $parts);
    }

    /** @return array{frequency:string,interval:int,weekdays:list<string>,until:?string} */
    public function decode(?string $rule, string $timezone): array
    {
        if ($rule === null || trim($rule) === '') {
            return ['frequency' => 'none', 'interval' => 1, 'weekdays' => [], 'until' => null];
        }

        $parts = $this->parse($rule);
        $frequency = $parts['FREQ'] ?? '';
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            throw new InvalidArgumentException('Событие содержит неподдерживаемое правило повторения');
        }
        $interval = isset($parts['INTERVAL']) ? (int) $parts['INTERVAL'] : 1;
        if ($interval < 1 || $interval > 365) {
            throw new InvalidArgumentException('Событие содержит неверный интервал повторения');
        }

        $weekdays = isset($parts['BYDAY']) ? $this->normalizeWeekdays(explode(',', $parts['BYDAY'])) : [];
        $until = null;
        if (isset($parts['UNTIL'])) {
            $utc = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $parts['UNTIL'], new DateTimeZone('UTC'));
            if (!$utc instanceof DateTimeImmutable) {
                throw new InvalidArgumentException('Событие содержит неверную дату окончания повторения');
            }
            $until = $utc->setTimezone($this->timezone($timezone))->format('Y-m-d');
        }

        return [
            'frequency' => strtolower($frequency),
            'interval' => $interval,
            'weekdays' => $weekdays,
            'until' => $until,
        ];
    }

    /** @return list<DateTimeImmutable> */
    public function occurrences(
        DateTimeImmutable $eventStartUtc,
        string $timezone,
        ?string $rule,
        DateTimeImmutable $rangeStartUtc,
        DateTimeImmutable $rangeEndUtc,
        int $limit = 500
    ): array {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Лимит экземпляров повторения должен быть от 1 до 1000');
        }
        $utc = new DateTimeZone('UTC');
        $eventStartUtc = $eventStartUtc->setTimezone($utc);
        $rangeStartUtc = $rangeStartUtc->setTimezone($utc);
        $rangeEndUtc = $rangeEndUtc->setTimezone($utc);
        if ($rangeEndUtc < $rangeStartUtc) {
            throw new InvalidArgumentException('Конец диапазона календаря раньше начала');
        }

        if ($rule === null || trim($rule) === '') {
            return $eventStartUtc <= $rangeEndUtc ? [$eventStartUtc] : [];
        }

        $parts = $this->parse($rule);
        $frequency = $parts['FREQ'] ?? '';
        $interval = isset($parts['INTERVAL']) ? (int) $parts['INTERVAL'] : 1;
        if (!in_array($frequency, self::FREQUENCIES, true) || $interval < 1 || $interval > 365) {
            throw new InvalidArgumentException('Неподдерживаемое правило повторения Calendar');
        }

        $tz = $this->timezone($timezone);
        $startLocal = $eventStartUtc->setTimezone($tz);
        $startDay = new DateTimeImmutable($startLocal->format('Y-m-d') . ' 00:00:00', $tz);
        $scanStartLocal = $rangeStartUtc->setTimezone($tz)->modify('-1 day');
        if ($scanStartLocal < $startDay) {
            $scanStartLocal = $startDay;
        }
        $scanEndLocal = $rangeEndUtc->setTimezone($tz)->modify('+1 day');
        $cursor = new DateTimeImmutable($scanStartLocal->format('Y-m-d') . ' 00:00:00', $tz);
        $scanEnd = new DateTimeImmutable($scanEndLocal->format('Y-m-d') . ' 23:59:59', $tz);
        $time = $startLocal->format('H:i:s');
        $weekdays = isset($parts['BYDAY']) ? $this->normalizeWeekdays(explode(',', $parts['BYDAY'])) : [];
        if ($frequency === 'WEEKLY' && $weekdays === []) {
            $weekdays = [$this->weekdayCode($startLocal)];
        }
        $untilUtc = $this->untilUtc($parts['UNTIL'] ?? null);

        $result = [];
        while ($cursor <= $scanEnd) {
            if ($this->matches($cursor, $startDay, $frequency, $interval, $weekdays, $startLocal)) {
                $candidateLocal = new DateTimeImmutable($cursor->format('Y-m-d') . ' ' . $time, $tz);
                $candidateUtc = $candidateLocal->setTimezone($utc);
                if ($candidateUtc >= $eventStartUtc
                    && $candidateUtc <= $rangeEndUtc
                    && ($untilUtc === null || $candidateUtc <= $untilUtc)) {
                    $result[] = $candidateUtc;
                    if (count($result) >= $limit) {
                        break;
                    }
                }
            }
            $cursor = $cursor->add(new DateInterval('P1D'));
        }

        return $result;
    }

    /** @return array<string,string> */
    private function parse(string $rule): array
    {
        $parts = [];
        foreach (explode(';', strtoupper(trim($rule))) as $chunk) {
            [$key, $value] = array_pad(explode('=', $chunk, 2), 2, '');
            if ($key === '' || $value === '') {
                continue;
            }
            $parts[$key] = $value;
        }
        return $parts;
    }

    /** @return list<string> */
    private function normalizeWeekdays(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('Дни недели повторения должны быть массивом');
        }
        $normalized = [];
        foreach ($value as $day) {
            $day = strtoupper(trim((string) $day));
            if (!in_array($day, self::WEEKDAYS, true)) {
                throw new InvalidArgumentException('Указан неизвестный день недели');
            }
            $normalized[$day] = true;
        }
        return array_values(array_filter(self::WEEKDAYS, static fn (string $day): bool => isset($normalized[$day])));
    }

    /** @param list<string> $weekdays */
    private function matches(
        DateTimeImmutable $day,
        DateTimeImmutable $startDay,
        string $frequency,
        int $interval,
        array $weekdays,
        DateTimeImmutable $startLocal
    ): bool {
        if ($day < $startDay) {
            return false;
        }
        $days = (int) $startDay->diff($day)->format('%a');
        return match ($frequency) {
            'DAILY' => $days % $interval === 0,
            'WEEKLY' => $this->weeklyMatch($day, $startDay, $interval, $weekdays),
            'MONTHLY' => $this->monthlyMatch($day, $startLocal, $interval),
            'YEARLY' => $this->yearlyMatch($day, $startLocal, $interval),
            default => false,
        };
    }

    /** @param list<string> $weekdays */
    private function weeklyMatch(DateTimeImmutable $day, DateTimeImmutable $startDay, int $interval, array $weekdays): bool
    {
        $startWeek = $startDay->modify('monday this week');
        $currentWeek = $day->modify('monday this week');
        $days = (int) $startWeek->diff($currentWeek)->format('%a');
        $weeks = intdiv($days, 7);
        return $weeks % $interval === 0 && in_array($this->weekdayCode($day), $weekdays, true);
    }

    private function monthlyMatch(DateTimeImmutable $day, DateTimeImmutable $startLocal, int $interval): bool
    {
        $months = (((int) $day->format('Y') - (int) $startLocal->format('Y')) * 12)
            + ((int) $day->format('n') - (int) $startLocal->format('n'));
        return $months >= 0
            && $months % $interval === 0
            && $day->format('j') === $startLocal->format('j');
    }

    private function yearlyMatch(DateTimeImmutable $day, DateTimeImmutable $startLocal, int $interval): bool
    {
        $years = (int) $day->format('Y') - (int) $startLocal->format('Y');
        return $years >= 0
            && $years % $interval === 0
            && $day->format('m-d') === $startLocal->format('m-d');
    }

    private function weekdayCode(DateTimeImmutable $value): string
    {
        return self::WEEKDAYS[(int) $value->format('N') - 1];
    }

    private function untilUtc(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $until = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $value, new DateTimeZone('UTC'));
        if (!$until instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('Неверная дата окончания повторения');
        }
        return $until;
    }

    private function timezone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone(trim($name) !== '' ? trim($name) : 'UTC');
        } catch (Throwable) {
            throw new InvalidArgumentException('Неизвестный часовой пояс события');
        }
    }
}
