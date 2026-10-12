<?php

declare(strict_types=1);

namespace Modules\Calendar\Services;

use Core\DatabaseManager;
use Core\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class CalendarTimeBlockService
{
    private const MAX_TITLE = 190;
    private const MAX_DESCRIPTION = 5000;
    private const MAX_LOCATION = 255;
    private const MAX_RANGE_DAYS = 370;
    private const MAX_ITEMS = 1000;

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @return list<array<string,mixed>> */
    public function listForUser(int $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->assertUserId($userId);
        $utc = new DateTimeZone('UTC');
        $from = $from->setTimezone($utc);
        $to = $to->setTimezone($utc);
        if ($to < $from || $from->diff($to)->days > self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException('Диапазон временных блоков должен быть от 0 до 370 дней');
        }

        $rows = $this->db->fetchAll(
            "SELECT uid,title,description,location,timezone,starts_at_utc,ends_at_utc
             FROM calendar_time_blocks
             WHERE owner_user_id = :user_id AND status = 'active'
               AND starts_at_utc <= :range_end
               AND ends_at_utc >= :range_start
             ORDER BY starts_at_utc ASC, id ASC
             LIMIT " . (self::MAX_ITEMS + 1),
            [
                ':user_id' => $userId,
                ':range_start' => $from->format('Y-m-d H:i:s'),
                ':range_end' => $to->format('Y-m-d H:i:s'),
            ]
        );
        if (count($rows) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('Диапазон содержит слишком много временных блоков; сузьте период');
        }
        return array_values(array_map(fn (array $row): array => $this->serialize($row), $rows));
    }

    /** @return array<string,mixed> */
    public function detailForUser(int $userId, string $uid): array
    {
        return $this->serialize($this->rowForUser($userId, $uid));
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createForUser(int $userId, array $payload): array
    {
        $this->assertUserId($userId);
        $data = $this->normalizePayload($payload);
        $uid = Uuid::v4();
        $this->db->execute(
            "INSERT INTO calendar_time_blocks (
                uid,owner_user_id,title,description,location,timezone,starts_at_utc,ends_at_utc,status
             ) VALUES (
                :uid,:owner_user_id,:title,:description,:location,:timezone,:starts_at_utc,:ends_at_utc,'active'
             )",
            [
                ':uid' => $uid,
                ':owner_user_id' => $userId,
                ':title' => $data['title'],
                ':description' => $data['description'],
                ':location' => $data['location'],
                ':timezone' => $data['timezone'],
                ':starts_at_utc' => $data['starts_at_utc'],
                ':ends_at_utc' => $data['ends_at_utc'],
            ]
        );
        return $this->detailForUser($userId, $uid);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function updateForUser(int $userId, string $uid, array $payload): array
    {
        $this->assertUserId($userId);
        $uid = $this->normalizeUid($uid);
        $data = $this->normalizePayload($payload);
        $updated = $this->db->execute(
            "UPDATE calendar_time_blocks SET
                title = :title,
                description = :description,
                location = :location,
                timezone = :timezone,
                starts_at_utc = :starts_at_utc,
                ends_at_utc = :ends_at_utc
             WHERE uid = :uid AND owner_user_id = :user_id AND status = 'active'",
            [
                ':title' => $data['title'],
                ':description' => $data['description'],
                ':location' => $data['location'],
                ':timezone' => $data['timezone'],
                ':starts_at_utc' => $data['starts_at_utc'],
                ':ends_at_utc' => $data['ends_at_utc'],
                ':uid' => $uid,
                ':user_id' => $userId,
            ]
        );
        if ($updated <= 0) {
            $this->rowForUser($userId, $uid);
        }
        return $this->detailForUser($userId, $uid);
    }

    public function cancelForUser(int $userId, string $uid): void
    {
        $this->assertUserId($userId);
        $uid = $this->normalizeUid($uid);
        $updated = $this->db->execute(
            "UPDATE calendar_time_blocks SET status = 'cancelled'
             WHERE uid = :uid AND owner_user_id = :user_id AND status = 'active'",
            [':uid' => $uid, ':user_id' => $userId]
        );
        if ($updated <= 0) {
            $this->rowForUser($userId, $uid);
        }
    }

    /** @return array<string,mixed> */
    private function rowForUser(int $userId, string $uid): array
    {
        $this->assertUserId($userId);
        $row = $this->db->fetchOne(
            "SELECT uid,title,description,location,timezone,starts_at_utc,ends_at_utc
             FROM calendar_time_blocks
             WHERE uid = :uid AND owner_user_id = :user_id AND status = 'active' LIMIT 1",
            [':uid' => $this->normalizeUid($uid), ':user_id' => $userId]
        );
        if (!is_array($row)) {
            throw new InvalidArgumentException('Временной блок не найден');
        }
        return $row;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function normalizePayload(array $payload): array
    {
        $allowed = [
            'title' => true,
            'description' => true,
            'location' => true,
            'timezone' => true,
            'starts_at' => true,
            'ends_at' => true,
        ];
        foreach (array_keys($payload) as $key) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidArgumentException('Запрос временного блока содержит неизвестное поле');
            }
        }

        $title = $this->text($payload['title'] ?? null, 'Название', 1, self::MAX_TITLE);
        $description = $this->optionalText($payload['description'] ?? '', 'Описание', self::MAX_DESCRIPTION);
        $location = $this->optionalText($payload['location'] ?? '', 'Место', self::MAX_LOCATION);
        $timezone = $this->timezone($this->text($payload['timezone'] ?? 'UTC', 'Часовой пояс', 1, 64));
        $start = $this->localDateTime($payload['starts_at'] ?? null, $timezone, 'Начало');
        $end = $this->localDateTime($payload['ends_at'] ?? null, $timezone, 'Окончание');
        if ($end <= $start) {
            throw new InvalidArgumentException('Окончание временного блока должно быть позже начала');
        }
        if ($start->diff($end)->days > 7) {
            throw new InvalidArgumentException('Один временной блок не может длиться более 7 дней');
        }

        $utc = new DateTimeZone('UTC');
        return [
            'title' => $title,
            'description' => $description,
            'location' => $location,
            'timezone' => $timezone->getName(),
            'starts_at_utc' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'ends_at_utc' => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function serialize(array $row): array
    {
        $timezone = $this->timezone((string) ($row['timezone'] ?? 'UTC'));
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable((string) $row['starts_at_utc'], $utc);
        $end = new DateTimeImmutable((string) $row['ends_at_utc'], $utc);
        return [
            'kind' => 'time_block',
            'uid' => (string) $row['uid'],
            'title' => (string) $row['title'],
            'description' => (string) ($row['description'] ?? ''),
            'location' => (string) ($row['location'] ?? ''),
            'all_day' => false,
            'timezone' => $timezone->getName(),
            'starts_at_utc' => $start->format(DATE_ATOM),
            'ends_at_utc' => $end->format(DATE_ATOM),
            'local_start' => $start->setTimezone($timezone)->format('Y-m-d\TH:i:s'),
            'local_end' => $end->setTimezone($timezone)->format('Y-m-d\TH:i:s'),
            'recurring' => false,
        ];
    }

    private function localDateTime(mixed $value, DateTimeZone $timezone, string $label): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ': требуется дата и время');
        }
        $value = trim($value);
        foreach (['!Y-m-d\TH:i:s' => 'Y-m-d\TH:i:s', '!Y-m-d\TH:i' => 'Y-m-d\TH:i'] as $format => $roundTrip) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed instanceof DateTimeImmutable
                && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
                && $parsed->format($roundTrip) === $value) {
                return $parsed;
            }
        }
        throw new InvalidArgumentException($label . ': используйте формат YYYY-MM-DDTHH:MM');
    }

    private function text(mixed $value, string $label, int $min, int $max): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ': требуется строка');
        }
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length < $min || $length > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException($label . ': недопустимое значение');
        }
        return $value;
    }

    private function optionalText(mixed $value, string $label, int $max): string
    {
        if ($value === null) {
            return '';
        }
        return $this->text((string) $value, $label, 0, $max);
    }

    private function timezone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            throw new InvalidArgumentException('Неизвестный часовой пояс временного блока');
        }
    }

    private function normalizeUid(string $uid): string
    {
        $uid = strtolower(trim($uid));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $uid) !== 1) {
            throw new InvalidArgumentException('Некорректный идентификатор временного блока');
        }
        return $uid;
    }

    private function assertUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Активная пользовательская сессия отсутствует');
        }
    }
}
