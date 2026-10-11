<?php

declare(strict_types=1);

namespace Modules\Calendar\Services;

use Core\DatabaseManager;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class CalendarReminderScheduler
{
    private const MAX_OFFSETS_PER_OCCURRENCE = 10;
    private const MAX_OCCURRENCES_PER_CALL = 100;
    private const MAX_OFFSET_SECONDS = 2592000;

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @param list<int> $offsetSeconds @return list<int> */
    public function scheduleOccurrence(
        int $eventId,
        DateTimeImmutable $occurrenceStart,
        array $offsetSeconds
    ): array {
        return $this->scheduleOccurrences($eventId, [$occurrenceStart], $offsetSeconds);
    }

    /**
     * При повторяющемся событии планировщик календаря передаёт сюда уже
     * вычисленные конкретные экземпляры серии. Благодаря occurrence_start_utc
     * напоминания каждого экземпляра дедуплицируются независимо.
     *
     * @param list<DateTimeImmutable> $occurrences
     * @param list<int> $offsetSeconds
     * @return list<int>
     */
    public function scheduleOccurrences(int $eventId, array $occurrences, array $offsetSeconds): array
    {
        if ($eventId <= 0) {
            throw new InvalidArgumentException('Идентификатор события должен быть положительным');
        }
        if ($occurrences === [] || count($occurrences) > self::MAX_OCCURRENCES_PER_CALL) {
            throw new InvalidArgumentException('За один вызов допускается от 1 до 100 экземпляров события');
        }

        $offsets = $this->normalizeOffsets($offsetSeconds);
        $eventExists = $this->db->fetchValue(
            "SELECT id FROM calendar_events WHERE id = :event_id AND status = 'active' LIMIT 1",
            [':event_id' => $eventId]
        );
        if ($eventExists === null) {
            throw new InvalidArgumentException('Активное событие Calendar не найдено');
        }

        $utc = new DateTimeZone('UTC');
        $occurrenceSql = [];
        foreach ($occurrences as $occurrence) {
            if (!$occurrence instanceof DateTimeImmutable) {
                throw new InvalidArgumentException('Экземпляр события должен быть DateTimeImmutable');
            }
            $normalized = $occurrence->setTimezone($utc)->format('Y-m-d H:i:s');
            $occurrenceSql[$normalized] = $normalized;
        }

        $ids = [];
        $this->db->beginTransaction();
        try {
            foreach ($occurrenceSql as $occurrence) {
                $occurrenceTime = new DateTimeImmutable($occurrence, $utc);
                foreach ($offsets as $offset) {
                    $remindAt = $occurrenceTime->modify('-' . $offset . ' seconds')->format('Y-m-d H:i:s');
                    $this->db->execute(
                        'INSERT INTO calendar_reminders ('
                        . 'event_id, occurrence_start_utc, remind_at_utc, offset_seconds, state'
                        . ') VALUES ('
                        . ':event_id, :occurrence_start_utc, :remind_at_utc, :offset_seconds, \'pending\''
                        . ') ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
                        [
                            ':event_id' => $eventId,
                            ':occurrence_start_utc' => $occurrence,
                            ':remind_at_utc' => $remindAt,
                            ':offset_seconds' => $offset,
                        ]
                    );
                    $ids[(int) $this->db->getPdo()->lastInsertId()] = true;
                }
            }
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }

        return array_map('intval', array_keys($ids));
    }

    /** @param list<int> $offsetSeconds @return list<int> */
    private function normalizeOffsets(array $offsetSeconds): array
    {
        if ($offsetSeconds === [] || count($offsetSeconds) > self::MAX_OFFSETS_PER_OCCURRENCE) {
            throw new InvalidArgumentException('Для одного экземпляра допускается от 1 до 10 напоминаний');
        }

        $normalized = [];
        foreach ($offsetSeconds as $offset) {
            if (!is_int($offset) || $offset < 0 || $offset > self::MAX_OFFSET_SECONDS) {
                throw new InvalidArgumentException('Смещение напоминания должно быть целым числом от 0 до 2592000 секунд');
            }
            $normalized[$offset] = $offset;
        }
        ksort($normalized, SORT_NUMERIC);
        return array_values($normalized);
    }
}
