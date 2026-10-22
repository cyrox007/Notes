<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/notifications/TaskReminderScheduleHealth.php';

use App\Services\Notifications\TaskReminderScheduleHealth;

function assertSchedule(bool $valid, string $description): void
{
    if (!$valid) {
        throw new RuntimeException('Состояние планировщика: ' . $description);
    }
}

$now = new DateTimeImmutable('2026-10-22 13:00:00', new DateTimeZone('UTC'));
$base = [
    'status' => 'ok',
    'last' => [
        'id' => 7, 'started_at' => '2026-10-22 12:50:00',
        'finished_at' => '2026-10-22 12:50:12', 'status' => 'ok',
        'morning_published' => 2, 'personal_published' => 3,
        'board_published' => 1, 'delivery_claimed' => 8,
        'delivery_sent' => 6, 'delivery_retry' => 2,
        'delivery_failed' => 0, 'error_stage' => null,
    ],
    'last_24h' => ['runs' => 12, 'sent' => 40, 'retry' => 3, 'failed' => 1],
];
$healthy = TaskReminderScheduleHealth::evaluate($base, $now);
assertSchedule($healthy['state'] === 'healthy'
    && $healthy['minutes_since_start'] === 10
    && $healthy['runs_24h'] === 12
    && $healthy['delivery_sent'] === 6,
    'неверно отображается исправный запуск');
assertSchedule($healthy['external_receipt_verified'] === false,
    'принятие каналом ошибочно объявлено получением сообщения');

$never = TaskReminderScheduleHealth::evaluate(['last' => null, 'last_24h' => []], $now);
assertSchedule($never['state'] === 'never_started', 'нельзя определить отсутствие запусков');

$late = $base;
$late['last']['started_at'] = '2026-10-22 12:14:00';
assertSchedule(TaskReminderScheduleHealth::evaluate($late, $now)['state'] === 'late',
    'просроченный запуск считается свежим');

$running = $base;
$running['last']['status'] = 'running';
$running['last']['finished_at'] = null;
assertSchedule(TaskReminderScheduleHealth::evaluate($running, $now)['state'] === 'running',
    'активный проход не распознан');
$running['last']['started_at'] = '2026-10-22 12:10:00';
assertSchedule(TaskReminderScheduleHealth::evaluate($running, $now)['state'] === 'interrupted',
    'зависший проход не распознан');

$failed = $base;
$failed['last']['status'] = 'failed';
$failed['last']['error_stage'] = 'delivery';
assertSchedule(TaskReminderScheduleHealth::evaluate($failed, $now)['state'] === 'failed',
    'ошибка скрыта успешными предыдущими запусками');

$bad = $base;
$bad['last']['started_at'] = 'не дата';
assertSchedule(TaskReminderScheduleHealth::evaluate($bad, $now)['state'] === 'unknown',
    'неверная дата маскируется как работающий планировщик');

echo "Планировщик: простои, ошибки, прерывания и счётчики проверены\n";
