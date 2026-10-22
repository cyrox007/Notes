<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Превращает агрегаты фонового цикла в понятное администратору состояние.
 * Ни одна отметка не подтверждает наличие задания в системном планировщике:
 * оцениваются только реально записанные проходы.
 */
final class TaskReminderScheduleHealth
{
    private const EXPECTED_INTERVAL_MINUTES = 15;
    private const STALE_AFTER_MINUTES = 45;

    /** @param array<string,mixed> $report @return array<string,mixed> */
    public static function evaluate(array $report, ?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $last = is_array($report['last'] ?? null) ? $report['last'] : null;
        $daily = is_array($report['last_24h'] ?? null) ? $report['last_24h'] : [];

        $result = [
            'state' => 'never_started',
            'message' => 'Фоновые проходы ещё не зарегистрированы. Проверьте установку задания планировщика.',
            'expected_interval_minutes' => self::EXPECTED_INTERVAL_MINUTES,
            'last_run_utc' => null,
            'last_finish_utc' => null,
            'last_status' => null,
            'minutes_since_start' => null,
            'failed_stage' => null,
            'morning_published' => 0,
            'personal_published' => 0,
            'board_published' => 0,
            'delivery_claimed' => 0,
            'delivery_sent' => 0,
            'delivery_retry' => 0,
            'delivery_failed' => 0,
            'runs_24h' => max(0, (int) ($daily['runs'] ?? 0)),
            'sent_24h' => max(0, (int) ($daily['sent'] ?? 0)),
            'retry_24h' => max(0, (int) ($daily['retry'] ?? 0)),
            'failed_24h' => max(0, (int) ($daily['failed'] ?? 0)),
            'external_receipt_verified' => false,
        ];
        if ($last === null) {
            return $result;
        }

        $startedAt = (string) ($last['started_at'] ?? '');
        try {
            $started = new DateTimeImmutable($startedAt, new DateTimeZone('UTC'));
            if ($startedAt === '' || $started->format('Y-m-d H:i:s') !== $startedAt) {
                throw new \UnexpectedValueException('Некорректная отметка запуска');
            }
        } catch (Throwable) {
            $result['state'] = 'unknown';
            $result['message'] = 'Не удалось прочитать время последнего прохода.';
            return $result;
        }

        $minutes = (int) floor(($now->getTimestamp() - $started->getTimestamp()) / 60);
        $status = (string) ($last['status'] ?? '');
        $result['minutes_since_start'] = max(0, $minutes);
        $result['last_run_utc'] = $startedAt;
        $result['last_finish_utc'] = $last['finished_at'] ?? null;
        $result['last_status'] = $status;
        $result['failed_stage'] = $last['error_stage'] ?? null;
        foreach ([
            'morning_published', 'personal_published', 'board_published',
            'delivery_claimed', 'delivery_sent', 'delivery_retry', 'delivery_failed',
        ] as $field) {
            $result[$field] = max(0, (int) ($last[$field] ?? 0));
        }

        if ($status === 'running') {
            $result['state'] = $minutes > self::STALE_AFTER_MINUTES ? 'interrupted' : 'running';
            $result['message'] = $result['state'] === 'running'
                ? 'Фоновый проход выполняется.'
                : 'Проход давно не завершался. Возможно, процесс прерван.';
            return $result;
        }

        if ($status === 'failed') {
            $result['state'] = 'failed';
            $result['message'] = 'Последний проход завершился с ошибкой. Проверьте этап и очередь доставки.';
            return $result;
        }

        if ($status !== 'ok') {
            $result['state'] = 'unknown';
            $result['message'] = 'Неизвестный результат последнего прохода.';
            return $result;
        }

        if ($minutes > self::STALE_AFTER_MINUTES) {
            $result['state'] = 'late';
            $result['message'] = 'Новый проход не зарегистрирован более 45 минут. Проверьте планировщик.';
            return $result;
        }

        $result['state'] = 'healthy';
        $result['message'] = 'Последний фоновый проход выполнен успешно и недавно.';
        return $result;
    }
}
