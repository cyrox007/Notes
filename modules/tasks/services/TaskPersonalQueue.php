<?php

declare(strict_types=1);

namespace Modules\Tasks;

use DateTimeImmutable;

/**
 * Единые границы личной рабочей очереди Tasks.
 *
 * Существующий DATETIME хранит локальное календарное время. Пока срок
 * не содержит смещения/зоны, сравнение проводится в часовой зоне PHP
 * установки. Преобразовывать старые записи в UTC здесь недопустимо.
 */
final class TaskPersonalQueue
{
    /** @return array{sql:string,params:array<string,string>} */
    public static function filter(string $name, DateTimeImmutable $now): array
    {
        $today = $now->setTime(0, 0, 0);
        $tomorrow = $today->modify('+1 day');
        $afterTomorrow = $tomorrow->modify('+1 day');

        return match ($name) {
            'today' => self::day($today, $tomorrow),
            'tomorrow' => self::day($tomorrow, $afterTomorrow),
            'week' => self::day($today, $today->modify('+7 days'), false),
            'overdue' => [
                'sql' => 't.due_date IS NOT NULL AND t.due_date < :now'
                    . " AND t.status NOT IN ('completed', 'cancelled')",
                'params' => [':now' => $now->format('Y-m-d H:i:s')],
            ],
            default => ['sql' => '', 'params' => []],
        };
    }

    /** @return array{today:string,tomorrow:string,after_tomorrow:string,now:string} */
    public static function boundaries(DateTimeImmutable $now): array
    {
        $today = $now->setTime(0, 0, 0);
        return [
            'today' => $today->format('Y-m-d H:i:s'),
            'tomorrow' => $today->modify('+1 day')->format('Y-m-d H:i:s'),
            'after_tomorrow' => $today->modify('+2 days')->format('Y-m-d H:i:s'),
            'now' => $now->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array{sql:string,params:array<string,string>} */
    private static function day(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        bool $onlyActive = true
    ): array {
        $sql = 't.due_date >= :day_start AND t.due_date < :day_end';
        if ($onlyActive) {
            $sql .= " AND t.status NOT IN ('completed', 'cancelled')";
        }

        return [
            'sql' => $sql,
            'params' => [
                ':day_start' => $start->format('Y-m-d H:i:s'),
                ':day_end' => $end->format('Y-m-d H:i:s'),
            ],
        ];
    }
}
