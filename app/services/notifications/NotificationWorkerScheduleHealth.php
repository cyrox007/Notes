<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Core\DatabaseManager;
use DateTimeImmutable;
use DateTimeZone;

/**
 * След последнего успешного запуска отдельного обработчика внешней очереди.
 *
 * Используется существующая таблица notification_runtime_state: имя её столбца
 * last_http_run_at историческое, а назначение записи задаётся state_key.
 * Это не проверка наличия задания в cron или планировщике Windows.
 */
final class NotificationWorkerScheduleHealth
{
    private const STATE_KEY = 'delivery_cli_worker';
    private const STALE_AFTER_MINUTES = 45;

    public static function recordSuccess(DatabaseManager $db): void
    {
        // Даже пустой проход фиксируется: отсутствие заданий не должно
        // ошибочно выглядеть как остановка расписания.
        $db->execute(
            'INSERT INTO notification_runtime_state
                (state_key, last_http_run_at, updated_at)
             VALUES (:state_key, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                last_http_run_at = VALUES(last_http_run_at),
                updated_at = VALUES(updated_at)',
            [':state_key' => self::STATE_KEY]
        );
    }

    public static function readLastSuccess(DatabaseManager $db): ?string
    {
        $value = $db->fetchValue(
            'SELECT last_http_run_at FROM notification_runtime_state
             WHERE state_key = :state_key LIMIT 1',
            [':state_key' => self::STATE_KEY]
        );
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return array{state:string,message:string,last_success_utc:?string,minutes_since_success:?int} */
    public static function evaluate(?string $lastSuccess, ?DateTimeImmutable $now = null): array
    {
        $result = [
            'state' => 'never_started',
            'message' => 'Успешных проходов отдельного обработчика пока не зарегистрировано. Проверьте расписание на сервере.',
            'last_success_utc' => $lastSuccess,
            'minutes_since_success' => null,
        ];
        if ($lastSuccess === null) {
            return $result;
        }

        $utc = new DateTimeZone('UTC');
        $now = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        $last = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $lastSuccess, $utc);
        if ($last === false || $last->format('Y-m-d H:i:s') !== $lastSuccess) {
            $result['state'] = 'unknown';
            $result['message'] = 'В базе обнаружено некорректное время последнего прохода обработчика.';
            $result['last_success_utc'] = null;
            return $result;
        }

        $ageSeconds = $now->getTimestamp() - $last->getTimestamp();
        if ($ageSeconds < -60) {
            $result['state'] = 'unknown';
            $result['message'] = 'Время последнего прохода находится в будущем. Проверьте часы сервера.';
            return $result;
        }

        $minutes = max(0, (int) floor($ageSeconds / 60));
        $result['minutes_since_success'] = $minutes;
        if ($minutes > self::STALE_AFTER_MINUTES) {
            $result['state'] = 'late';
            $result['message'] = 'Более 45 минут нет успешных проходов отдельной очереди. Проверьте cron или планировщик Windows.';
            return $result;
        }

        $result['state'] = 'healthy';
        $result['message'] = 'Недавно завершился успешный проход обработчика. Это не подтверждает получение уведомлений адресатами.';
        return $result;
    }
}
