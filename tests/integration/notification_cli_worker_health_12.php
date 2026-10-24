<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationWorkerScheduleHealth.php';

use App\Services\Notifications\NotificationWorkerScheduleHealth;
use DateTimeImmutable;
use DateTimeZone;

function checkWorkerHealth(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'Диагностика обработчика: ' . $message . PHP_EOL);
        exit(1);
    }
}

$now = new DateTimeImmutable('2026-10-24 12:00:00', new DateTimeZone('UTC'));
$never = NotificationWorkerScheduleHealth::evaluate(null, $now);
checkWorkerHealth($never['state'] === 'never_started'
    && $never['last_success_utc'] === null, 'пустой журнал не должен означать успешный запуск');

$recent = NotificationWorkerScheduleHealth::evaluate('2026-10-24 11:58:00', $now);
checkWorkerHealth($recent['state'] === 'healthy'
    && $recent['minutes_since_success'] === 2, 'свежий проход не распознан');

$border = NotificationWorkerScheduleHealth::evaluate('2026-10-24 11:15:00', $now);
checkWorkerHealth($border['state'] === 'healthy', '45 минут не должны считаться просрочкой');

$late = NotificationWorkerScheduleHealth::evaluate('2026-10-24 11:14:00', $now);
checkWorkerHealth($late['state'] === 'late'
    && $late['minutes_since_success'] === 46, 'задержка отдельного обработчика не обнаружена');

$wrong = NotificationWorkerScheduleHealth::evaluate('2026-02-30 11:00:00', $now);
checkWorkerHealth($wrong['state'] === 'unknown', 'некорректная дата должна отклоняться');

$future = NotificationWorkerScheduleHealth::evaluate('2026-10-24 12:02:00', $now);
checkWorkerHealth($future['state'] === 'unknown', 'будущее время должно сигнализировать о рассинхронизации');

$source = file_get_contents(dirname(__DIR__, 2) . '/bin/notifications_worker.php');
$service = file_get_contents(dirname(__DIR__, 2) . '/app/services/notifications/NotificationWorkerScheduleHealth.php');
$view = file_get_contents(dirname(__DIR__, 2) . '/modules/admin/views/notifications.php');
$diagnostics = file_get_contents(dirname(__DIR__, 2) . '/bin/notifications_diagnose.php');

checkWorkerHealth(is_string($source) && is_string($service)
    && is_string($view) && is_string($diagnostics), 'исходники диагностики не найдены');
checkWorkerHealth(str_contains($source, 'NotificationWorkerScheduleHealth::recordSuccess($db)')
    && str_contains($source, '$stats = $runtime->run($workerId, $limit);'),
    'отметка успеха должна записываться после прохода очереди');
checkWorkerHealth(str_contains($service, 'UTC_TIMESTAMP()')
    && str_contains($service, "'delivery_cli_worker'")
    && !str_contains($service, 'CREATE TABLE'),
    'используйте существующую схему и серверное время UTC');
checkWorkerHealth(str_contains($view, 'Состояние отдельного обработчика уведомлений')
    && str_contains($diagnostics, 'cli_worker_last_success_utc'),
    'состояние должно быть видно администратору и в диагностике');

echo "Проверены отметка успешного прохода, пропуск запуска и безопасность диагностики\n";
