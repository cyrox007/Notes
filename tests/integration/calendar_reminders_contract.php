<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function calendarReminderAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function calendarReminderRead(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException('Не удалось прочитать файл: ' . $path);
    }
    return $content;
}

$manifestPath = $root . '/modules/calendar/module.json';
$manifest = json_decode(calendarReminderRead($manifestPath), true, 32, JSON_THROW_ON_ERROR);
calendarReminderAssert(($manifest['id'] ?? null) === 'calendar', 'Manifest должен объявлять модуль calendar');
calendarReminderAssert(($manifest['runtime']['mode'] ?? null) === 'isolated', 'Calendar должен быть изолированным модулем');
calendarReminderAssert(($manifest['runtime']['entrypoint'] ?? null) === 'runtime.php', 'Calendar должен иметь собственный runtime');
calendarReminderAssert(($manifest['capabilities'] ?? []) === ['workspace.calendar'], 'Calendar должен экспортировать только workspace.calendar');
calendarReminderAssert(($manifest['license']['feature'] ?? null) === 'workspace.calendar', 'Calendar должен иметь отдельное лицензионное разрешение');
calendarReminderAssert(
    ($manifest['database']['tables'] ?? []) === ['calendar_events', 'calendar_reminders', 'calendar_runtime_state'],
    'Calendar должен владеть только своими таблицами'
);
calendarReminderAssert(
    in_array('database/migrations/20261011_calendar_reminders.sql', $manifest['database']['migrations'] ?? [], true),
    'Manifest Calendar должен владеть своей миграцией'
);

$globalMigrationManifest = json_decode(
    calendarReminderRead($root . '/database/migrations/manifest.json'),
    true,
    32,
    JSON_THROW_ON_ERROR
);
calendarReminderAssert(
    in_array('20261011_calendar_reminders.sql', $globalMigrationManifest['migrations'] ?? [], true),
    'Миграция Calendar должна входить в канонический порядок'
);

$sql = calendarReminderRead($root . '/database/migrations/20261011_calendar_reminders.sql');
foreach (['calendar_events', 'calendar_reminders', 'calendar_runtime_state'] as $table) {
    calendarReminderAssert(str_contains($sql, 'CREATE TABLE IF NOT EXISTS `' . $table . '`'), 'Миграция должна создавать ' . $table);
}
calendarReminderAssert(str_contains($sql, 'uq_calendar_reminder_occurrence'), 'Схема должна дедуплицировать экземпляр события и смещение');
calendarReminderAssert(str_contains($sql, 'occurrence_start_utc'), 'Напоминание должно хранить конкретный экземпляр повторяющейся серии');
calendarReminderAssert(str_contains($sql, 'remind_at_utc'), 'Время обработки напоминания должно храниться в UTC');

$scheduler = calendarReminderRead($root . '/modules/calendar/services/CalendarReminderScheduler.php');
$publisher = calendarReminderRead($root . '/modules/calendar/services/CalendarReminderPublisher.php');
$capability = calendarReminderRead($root . '/modules/calendar/CalendarCapability.php');
$provider = calendarReminderRead($root . '/modules/calendar/CalendarRuntimeProvider.php');
$cli = calendarReminderRead($root . '/bin/calendar_reminders.php');
$calendarSources = implode("\n", [$scheduler, $publisher, $capability, $provider, $cli]);

calendarReminderAssert(str_contains($publisher, 'NotificationService'), 'Calendar должен публиковать через общий NotificationService');
calendarReminderAssert(str_contains($publisher, "'calendar.reminder_due'"), 'Publisher должен использовать событие calendar.reminder_due');
calendarReminderAssert(str_contains($publisher, "'calendar.reminders'"), 'Publisher должен использовать категорию UI calendar.reminders');
calendarReminderAssert(str_contains($publisher, "'calendar.reminder:%s:%s:%d'"), 'Ключ идемпотентности должен различать экземпляр серии и смещение');
calendarReminderAssert(str_contains($publisher, "'event_uid'"), 'Безопасный payload должен содержать только идентификатор события и метаданные времени');
calendarReminderAssert(!str_contains($publisher, "'description'"), 'Publisher не должен отправлять описание события во внешний слой');
calendarReminderAssert(!str_contains($publisher, 'secret_ref'), 'Calendar не должен читать ссылки на секреты каналов');
calendarReminderAssert(
    preg_match('/SmtpNotificationChannel|SocketSmtpTransport|DatabaseNotificationEmailRecipientResolver/', $calendarSources) !== 1,
    'Calendar не должен зависеть от конкретного внешнего канала доставки'
);

calendarReminderAssert(str_contains($publisher, 'GET_LOCK'), 'Publisher должен защищать проход глобальной MySQL-блокировкой');
calendarReminderAssert(str_contains($publisher, 'MAX_LIMIT = 100'), 'Размер прохода должен иметь жёсткий верхний предел');
calendarReminderAssert(str_contains($publisher, 'HTTP_LIMIT = 3'), 'HTTP fallback должен быть строго ограничен');
calendarReminderAssert(str_contains($publisher, 'HTTP_INTERVAL_SECONDS = 60'), 'HTTP fallback должен иметь глобальный интервал');
calendarReminderAssert(!preg_match('/\bsleep\s*\(|while\s*\(\s*true\s*\)/', $calendarSources), 'Calendar reminders не должны требовать бесконечный daemon-loop');
calendarReminderAssert(str_contains($provider, 'runHttpFallback'), 'Runtime Calendar должен запускать резервный bounded catch-up');
calendarReminderAssert(str_contains($provider, "PHP_SAPI === 'cli'"), 'HTTP fallback не должен дублировать CLI-проход');

calendarReminderAssert(str_contains($scheduler, 'scheduleOccurrences'), 'Scheduler должен принимать несколько конкретных экземпляров серии');
calendarReminderAssert(str_contains($scheduler, 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'), 'Повторное планирование должно быть идемпотентным');
calendarReminderAssert(str_contains($publisher, "state = 'expired'"), 'Слишком старые напоминания должны безопасно истекать');
calendarReminderAssert(str_contains($publisher, 'notification_publish_failed'), 'Ошибка публикации должна сохраняться только безопасным кодом');
calendarReminderAssert(!str_contains($publisher, 'getMessage()'), 'Publisher не должен сохранять или выводить сырой текст исключения');

calendarReminderAssert(str_contains($cli, '--catch-up-seconds'), 'CLI должен позволять задавать окно восстановления');
calendarReminderAssert(str_contains($cli, '--limit'), 'CLI должен иметь bounded batch');
calendarReminderAssert(str_contains($cli, 'Напоминания Calendar:'), 'CLI-вывод должен быть русскоязычным');
calendarReminderAssert(!preg_match('/while\s*\(\s*true\s*\)/', $cli), 'CLI должен выполнять один проход');

echo "[OK] архитектурный контракт напоминаний Calendar 1.1.0\n";
