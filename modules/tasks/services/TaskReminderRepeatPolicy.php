<?php

declare(strict_types=1);

namespace Modules\Tasks;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Частота повтора считается по местным календарным датам, а не по 24 часам.
 * Первый повтор — на следующий день после срока, затем каждые N суток.
 */
final class TaskReminderRepeatPolicy
{
    /** @return list<int> */
    public static function allowed(): array
    {
        return [0, 1, 3, 7];
    }

    public static function validate(int $days): int
    {
        if (!in_array($days, self::allowed(), true)) {
            throw new InvalidArgumentException('Частота напоминаний должна быть 0, 1, 3 или 7 дней');
        }
        return $days;
    }

    public static function due(string $dueDate, string $localDate, int $intervalDays): bool
    {
        self::validate($intervalDays);
        if ($intervalDays === 0) {
            return false;
        }

        $zone = new DateTimeZone('UTC');
        $deadline = DateTimeImmutable::createFromFormat('!Y-m-d', substr($dueDate, 0, 10), $zone);
        $current = DateTimeImmutable::createFromFormat('!Y-m-d', $localDate, $zone);
        if (!$deadline instanceof DateTimeImmutable || !$current instanceof DateTimeImmutable) {
            return false;
        }

        $overdueDays = (int) $deadline->diff($current)->format('%r%a');
        return $overdueDays > 0 && ($overdueDays - 1) % $intervalDays === 0;
    }
}
