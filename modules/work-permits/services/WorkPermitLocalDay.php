<?php

declare(strict_types=1);

namespace Modules\WorkPermits\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Граница рабочего календарного дня определяется по часовому поясу
 * организации, зафиксированному при выдаче наряда. Хранимые времена — UTC.
 */
final class WorkPermitLocalDay
{
    public static function validate(mixed $name): string
    {
        if (!is_string($name) || strlen($name) > 64
            || !in_array($name, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException('Укажите действительный часовой пояс IANA');
        }
        // Псевдонимы с устаревшими правилами не допускаются.
        if ($name !== 'UTC' && !in_array($name, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Выберите современный часовой пояс IANA');
        }
        return $name;
    }

    public static function date(string $utc, string $zone): string
    {
        return self::moment($utc, $zone)->format('Y-m-d');
    }

    /** Время объекта для печати: дата и явное смещение даже в повторяющийся час. */
    public static function forPrint(string $utc, string $zone): string
    {
        $moment = self::moment($utc, $zone);
        return $moment->format('d.m.Y H:i') . ' (UTC' . $moment->format('P') . ')';
    }

    private static function moment(string $utc, string $zone): DateTimeImmutable
    {
        $timezone = new DateTimeZone(self::validate($zone));
        $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));
        if (!$moment || $moment->format('Y-m-d H:i:s') !== $utc) {
            throw new InvalidArgumentException('Некорректная дата и время UTC');
        }
        return $moment->setTimezone($timezone);
    }

    public static function sameDay(string $firstUtc, string $secondUtc, string $zone): bool
    {
        return self::date($firstUtc, $zone) === self::date($secondUtc, $zone);
    }
}
