<?php

declare(strict_types=1);

namespace Modules\Defects\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Единая политика срока устранения дефектов.
 * Срок рассчитывается только при регистрации; дальнейшее изменение
 * справочников не должно переписывать сохранённую плановую дату.
 */
final class DefectPriorityPolicy
{
    public const string URGENT = 'urgent';
    public const string FIRST = 'first';
    public const string IMPORTANT = 'important';
    public const string NORMAL = 'normal';

    /** @var array<string,int> */
    private const array DAYS = [
        self::URGENT => 1,
        self::FIRST => 7,
        self::IMPORTANT => 14,
        self::NORMAL => 30,
    ];

    public static function days(string $priority): int
    {
        return self::DAYS[$priority]
            ?? throw new InvalidArgumentException('Неизвестный приоритет дефекта');
    }

    public static function deadline(DateTimeImmutable $discoveredAt, string $priority): DateTimeImmutable
    {
        return $discoveredAt->modify('+' . self::days($priority) . ' days');
    }
}
