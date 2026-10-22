<?php

declare(strict_types=1);

namespace Modules\Tasks;

use Core\DatabaseManager;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Часовой пояс задаёт пользователь, а не браузер или заголовок запроса.
 * Старые DATETIME остаются локальными календарными значениями установки.
 */
final class TaskUserTimezoneService
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    public function forUser(int $userId): DateTimeZone
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Требуется пользователь');
        }
        $value = $this->db->fetchValue(
            'SELECT timezone FROM task_user_preferences WHERE user_id=:user',
            [':user' => $userId]
        );
        if (!is_string($value) || $value === '') {
            return new DateTimeZone(date_default_timezone_get());
        }
        return self::validate($value);
    }

    public function save(int $userId, string $timezone): string
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Требуется пользователь');
        }
        $selected = self::validate($timezone)->getName();
        $this->db->execute(
            'INSERT INTO task_user_preferences(user_id,timezone)
             VALUES(:user,:timezone)
             ON DUPLICATE KEY UPDATE timezone=VALUES(timezone)',
            [':user' => $userId, ':timezone' => $selected]
        );
        return $selected;
    }

    public function nowFor(int $userId, ?DateTimeImmutable $instant = null): DateTimeImmutable
    {
        $zone = $this->forUser($userId);
        $instant ??= new DateTimeImmutable('now');
        return $instant->setTimezone($zone);
    }

    private static function validate(string $name): DateTimeZone
    {
        if ($name === '' || strlen($name) > 64
            || !in_array($name, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException('Укажите существующий часовой пояс, например Europe/Moscow');
        }
        return new DateTimeZone($name);
    }
}
