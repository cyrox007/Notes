<?php

declare(strict_types=1);

namespace Modules\Calendar\Services;

use Core\DatabaseManager;
use Core\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class CalendarEventService
{
    private const MAX_TITLE = 190;
    private const MAX_DESCRIPTION = 5000;
    private const MAX_LOCATION = 255;
    private const MAX_RANGE_DAYS = 370;
    private const MAX_OCCURRENCES = 1000;

    public function __construct(
        private ?DatabaseManager $db = null,
        private ?CalendarRecurrence $recurrence = null,
        private ?CalendarReminderPlanner $planner = null
    ) {
        $this->db ??= DatabaseManager::getInstance();
        $this->recurrence ??= new CalendarRecurrence();
        $this->planner ??= new CalendarReminderPlanner($this->db, recurrence: $this->recurrence);
    }

    /** @return array{events:list<array<string,mixed>>,from:string,to:string} */
    public function listForUser(int $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->assertUserId($userId);
        $utc = new DateTimeZone('UTC');
        $from = $from->setTimezone($utc);
        $to = $to->setTimezone($utc);
        if ($to < $from || $from->diff($to)->days > self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException('Диапазон календаря должен быть от 0 до 370 дней');
        }

        $rows = $this->db->fetchAll(
            'SELECT id, uid, title, description, location, is_all_day, timezone, starts_at_utc, ends_at_utc, recurrence_rule '
            . 'FROM calendar_events '
            . "WHERE owner_user_id = :user_id AND status = 'active' "
            . 'AND (recurrence_rule IS NOT NULL OR starts_at_utc <= :range_end) '
            . 'AND (recurrence_rule IS NOT NULL OR COALESCE(ends_at_utc, starts_at_utc) >= :range_start) '
            . 'ORDER BY starts_at_utc ASC, id ASC',
            [
                ':user_id' => $userId,
                ':range_start' => $from->format('Y-m-d H:i:s'),
                ':range_end' => $to->format('Y-m-d H:i:s'),
            ]
        );

        $events = [];
        foreach ($rows as $row) {
            foreach ($this->expandRow($row, $from, $to) as $occurrence) {
                $events[] = $occurrence;
                if (count($events) > self::MAX_OCCURRENCES) {
                    throw new InvalidArgumentException('Диапазон содержит слишком много экземпляров событий; сузьте период');
                }
            }
        }
        usort($events, static fn (array $a, array $b): int => [$a['starts_at_utc'], $a['uid']] <=> [$b['starts_at_utc'], $b['uid']]);

        return [
            'events' => $events,
            'from' => $from->format(DATE_ATOM),
            'to' => $to->format(DATE_ATOM),
        ];
    }

    /** @return array<string,mixed> */
    public function detailForUser(int $userId, string $uid): array
    {
        $row = $this->eventRow($userId, $uid);
        return $this->serializeBaseEvent($row);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createForUser(int $userId, array $payload): array
    {
        $this->assertUserId($userId);
        $data = $this->normalizePayload($payload);
        $uid = Uuid::v4();

        $this->db->beginTransaction();
        try {
            $this->db->execute(
                'INSERT INTO calendar_events ('
                . 'uid, owner_user_id, title, description, location, is_all_day, timezone, starts_at_utc, ends_at_utc, recurrence_rule, status'
                . ") VALUES (:uid, :owner_user_id, :title, :description, :location, :is_all_day, :timezone, :starts_at_utc, :ends_at_utc, :recurrence_rule, 'active')",
                [
                    ':uid' => $uid,
                    ':owner_user_id' => $userId,
                    ':title' => $data['title'],
                    ':description' => $data['description'],
                    ':location' => $data['location'],
                    ':is_all_day' => $data['all_day'] ? 1 : 0,
                    ':timezone' => $data['timezone'],
                    ':starts_at_utc' => $data['starts_at_utc'],
                    ':ends_at_utc' => $data['ends_at_utc'],
                    ':recurrence_rule' => $data['recurrence_rule'],
                ]
            );
            $eventId = (int) $this->db->getPdo()->lastInsertId();
            $this->replaceOffsets($eventId, $data['reminder_offsets']);
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }

        $this->planSafely($eventId);
        return $this->detailForUser($userId, $uid);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function updateForUser(int $userId, string $uid, array $payload): array
    {
        $this->assertUserId($userId);
        $data = $this->normalizePayload($payload);

        $this->db->beginTransaction();
        try {
            $row = $this->db->fetchOne(
                "SELECT id FROM calendar_events WHERE owner_user_id = :user_id AND uid = :uid AND status = 'active' FOR UPDATE",
                [':user_id' => $userId, ':uid' => $this->normalizeUid($uid)]
            );
            if (!is_array($row)) {
                throw new InvalidArgumentException('Событие Calendar не найдено');
            }
            $eventId = (int) $row['id'];
            $this->db->execute(
                'UPDATE calendar_events SET '
                . 'title = :title, description = :description, location = :location, is_all_day = :is_all_day, '
                . 'timezone = :timezone, starts_at_utc = :starts_at_utc, ends_at_utc = :ends_at_utc, recurrence_rule = :recurrence_rule '
                . 'WHERE id = :id AND owner_user_id = :user_id',
                [
                    ':title' => $data['title'],
                    ':description' => $data['description'],
                    ':location' => $data['location'],
                    ':is_all_day' => $data['all_day'] ? 1 : 0,
                    ':timezone' => $data['timezone'],
                    ':starts_at_utc' => $data['starts_at_utc'],
                    ':ends_at_utc' => $data['ends_at_utc'],
                    ':recurrence_rule' => $data['recurrence_rule'],
                    ':id' => $eventId,
                    ':user_id' => $userId,
                ]
            );
            $this->replaceOffsets($eventId, $data['reminder_offsets']);
            $this->db->execute(
                "DELETE FROM calendar_reminders WHERE event_id = :event_id AND state = 'pending'",
                [':event_id' => $eventId]
            );
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }

        $this->planSafely($eventId);
        return $this->detailForUser($userId, $uid);
    }

    public function cancelForUser(int $userId, string $uid): void
    {
        $this->assertUserId($userId);
        $uid = $this->normalizeUid($uid);
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $row = $this->db->fetchOne(
                "SELECT id FROM calendar_events WHERE owner_user_id = :user_id AND uid = :uid AND status = 'active' FOR UPDATE",
                [':user_id' => $userId, ':uid' => $uid]
            );
            if (!is_array($row)) {
                throw new InvalidArgumentException('Событие Calendar не найдено');
            }
            $eventId = (int) $row['id'];
            $this->db->execute(
                "UPDATE calendar_events SET status = 'cancelled' WHERE id = :id AND owner_user_id = :user_id",
                [':id' => $eventId, ':user_id' => $userId]
            );
            $this->db->execute(
                "UPDATE calendar_reminders SET state = 'expired', expired_at = :expired_at, last_error_code = NULL "
                . "WHERE event_id = :event_id AND state = 'pending'",
                [':expired_at' => $now, ':event_id' => $eventId]
            );
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
    }

    private function planSafely(int $eventId): void
    {
        try {
            $this->planner->refreshEvent($eventId);
        } catch (Throwable $e) {
            error_log('[Calendar] Не удалось сразу подготовить напоминания события ' . $eventId . ': ' . $e::class);
        }
    }

    /** @param array<string,mixed> $row @return list<array<string,mixed>> */
    private function expandRow(array $row, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable((string) $row['starts_at_utc'], $utc);
        $end = isset($row['ends_at_utc']) && $row['ends_at_utc'] !== null
            ? new DateTimeImmutable((string) $row['ends_at_utc'], $utc)
            : null;
        $duration = $end !== null ? max(0, $end->getTimestamp() - $start->getTimestamp()) : 0;
        $occurrences = $this->recurrence->occurrences(
            $start,
            (string) ($row['timezone'] ?? 'UTC'),
            isset($row['recurrence_rule']) ? (string) $row['recurrence_rule'] : null,
            $from,
            $to,
            500
        );

        $items = [];
        foreach ($occurrences as $occurrenceStart) {
            $occurrenceEnd = $duration > 0 ? $occurrenceStart->modify('+' . $duration . ' seconds') : null;
            if ($occurrenceStart > $to || ($occurrenceEnd ?? $occurrenceStart) < $from) {
                continue;
            }
            $items[] = $this->serializeOccurrence($row, $occurrenceStart, $occurrenceEnd);
        }
        return $items;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function serializeOccurrence(array $row, DateTimeImmutable $start, ?DateTimeImmutable $end): array
    {
        $timezone = $this->timezone((string) ($row['timezone'] ?? 'UTC'));
        $uid = (string) $row['uid'];
        return [
            'uid' => $uid,
            'occurrence_key' => $uid . ':' . $start->format('Ymd\THis\Z'),
            'title' => (string) $row['title'],
            'description' => (string) ($row['description'] ?? ''),
            'location' => (string) ($row['location'] ?? ''),
            'all_day' => (int) ($row['is_all_day'] ?? 0) === 1,
            'timezone' => $timezone->getName(),
            'starts_at_utc' => $start->format(DATE_ATOM),
            'ends_at_utc' => $end?->format(DATE_ATOM),
            'local_start' => $start->setTimezone($timezone)->format('Y-m-d\TH:i:s'),
            'local_end' => $end?->setTimezone($timezone)->format('Y-m-d\TH:i:s'),
            'recurring' => isset($row['recurrence_rule']) && trim((string) $row['recurrence_rule']) !== '',
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function serializeBaseEvent(array $row): array
    {
        $timezone = $this->timezone((string) $row['timezone']);
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable((string) $row['starts_at_utc'], $utc);
        $end = $row['ends_at_utc'] !== null ? new DateTimeImmutable((string) $row['ends_at_utc'], $utc) : null;
        return [
            'uid' => (string) $row['uid'],
            'title' => (string) $row['title'],
            'description' => (string) ($row['description'] ?? ''),
            'location' => (string) ($row['location'] ?? ''),
            'all_day' => (int) ($row['is_all_day'] ?? 0) === 1,
            'timezone' => $timezone->getName(),
            'starts_at_utc' => $start->format(DATE_ATOM),
            'ends_at_utc' => $end?->format(DATE_ATOM),
            'local_start' => $start->setTimezone($timezone)->format('Y-m-d\TH:i:s'),
            'local_end' => $end?->setTimezone($timezone)->format('Y-m-d\TH:i:s'),
            'recurrence' => $this->recurrence->decode(
                $row['recurrence_rule'] !== null ? (string) $row['recurrence_rule'] : null,
                $timezone->getName()
            ),
            'reminder_offsets' => $this->reminderOffsets((int) $row['id']),
        ];
    }

    /** @return array<string,mixed> */
    private function eventRow(int $userId, string $uid): array
    {
        $this->assertUserId($userId);
        $row = $this->db->fetchOne(
            'SELECT id, uid, title, description, location, is_all_day, timezone, starts_at_utc, ends_at_utc, recurrence_rule '
            . "FROM calendar_events WHERE owner_user_id = :user_id AND uid = :uid AND status = 'active' LIMIT 1",
            [':user_id' => $userId, ':uid' => $this->normalizeUid($uid)]
        );
        if (!is_array($row)) {
            throw new InvalidArgumentException('Событие Calendar не найдено');
        }
        return $row;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function normalizePayload(array $payload): array
    {
        $allowed = [
            'title' => true, 'description' => true, 'location' => true, 'all_day' => true,
            'timezone' => true, 'starts_at' => true, 'ends_at' => true,
            'recurrence' => true, 'reminder_offsets' => true,
        ];
        foreach (array_keys($payload) as $key) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidArgumentException('Запрос события содержит неизвестное поле');
            }
        }

        $title = $this->text($payload['title'] ?? null, 'Название', 1, self::MAX_TITLE);
        $description = $this->optionalText($payload['description'] ?? '', 'Описание', self::MAX_DESCRIPTION);
        $location = $this->optionalText($payload['location'] ?? '', 'Место', self::MAX_LOCATION);
        $allDay = $this->boolean($payload['all_day'] ?? false, 'all_day');
        $timezone = $this->timezone($this->text($payload['timezone'] ?? 'UTC', 'Часовой пояс', 1, 64));
        $start = $this->localDateTime($payload['starts_at'] ?? null, $timezone, 'Начало');
        $endRaw = $payload['ends_at'] ?? null;
        $end = $endRaw === null || $endRaw === '' ? null : $this->localDateTime($endRaw, $timezone, 'Окончание');
        if ($end !== null && $end <= $start) {
            throw new InvalidArgumentException('Окончание события должно быть позже начала');
        }
        if ($end !== null && $start->diff($end)->days > 31) {
            throw new InvalidArgumentException('Одно событие не может длиться более 31 дня');
        }

        $recurrencePayload = $payload['recurrence'] ?? null;
        if ($recurrencePayload !== null && (!is_array($recurrencePayload) || array_is_list($recurrencePayload))) {
            throw new InvalidArgumentException('recurrence должен быть JSON-объектом');
        }
        $rule = $this->recurrence->encode($recurrencePayload, $timezone);
        if (is_array($recurrencePayload) && trim((string) ($recurrencePayload['until'] ?? '')) !== '') {
            $until = (string) $recurrencePayload['until'];
            if ($until < $start->format('Y-m-d')) {
                throw new InvalidArgumentException('Повторение не может завершаться раньше начала события');
            }
        }

        $offsets = $this->normalizeOffsets($payload['reminder_offsets'] ?? []);
        $utc = new DateTimeZone('UTC');
        return [
            'title' => $title,
            'description' => $description,
            'location' => $location,
            'all_day' => $allDay,
            'timezone' => $timezone->getName(),
            'starts_at_utc' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'ends_at_utc' => $end?->setTimezone($utc)->format('Y-m-d H:i:s'),
            'recurrence_rule' => $rule,
            'reminder_offsets' => $offsets,
        ];
    }

    /** @param list<int> $offsets */
    private function replaceOffsets(int $eventId, array $offsets): void
    {
        $this->db->execute('DELETE FROM calendar_event_reminder_offsets WHERE event_id = :event_id', [':event_id' => $eventId]);
        foreach ($offsets as $offset) {
            $this->db->execute(
                'INSERT INTO calendar_event_reminder_offsets (event_id, offset_seconds) VALUES (:event_id, :offset_seconds)',
                [':event_id' => $eventId, ':offset_seconds' => $offset]
            );
        }
    }

    /** @return list<int> */
    private function reminderOffsets(int $eventId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT offset_seconds FROM calendar_event_reminder_offsets WHERE event_id = :event_id ORDER BY offset_seconds ASC',
            [':event_id' => $eventId]
        );
        return array_values(array_map(static fn (array $row): int => (int) $row['offset_seconds'], $rows));
    }

    /** @return list<int> */
    private function normalizeOffsets(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 10) {
            throw new InvalidArgumentException('reminder_offsets должен содержать не более 10 значений');
        }
        $offsets = [];
        foreach ($value as $offset) {
            if (!is_int($offset) || $offset < 0 || $offset > 2592000) {
                throw new InvalidArgumentException('Смещение напоминания должно быть от 0 до 2592000 секунд');
            }
            $offsets[$offset] = $offset;
        }
        ksort($offsets, SORT_NUMERIC);
        return array_values($offsets);
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

    private function boolean(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException($field . ' должен быть логическим значением');
        }
        return $value;
    }

    private function timezone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            throw new InvalidArgumentException('Неизвестный часовой пояс события');
        }
    }

    private function normalizeUid(string $uid): string
    {
        $uid = strtolower(trim($uid));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $uid) !== 1) {
            throw new InvalidArgumentException('Некорректный идентификатор события');
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
