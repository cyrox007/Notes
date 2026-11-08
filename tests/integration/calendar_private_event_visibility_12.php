<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/DatabaseManager.php';
require_once dirname(__DIR__, 2) . '/core/Uuid.php';
require_once dirname(__DIR__, 2) . '/modules/calendar/services/CalendarRecurrence.php';
require_once dirname(__DIR__, 2) . '/modules/calendar/services/CalendarReminderScheduler.php';
require_once dirname(__DIR__, 2) . '/modules/calendar/services/CalendarReminderPlanner.php';
require_once dirname(__DIR__, 2) . '/modules/calendar/services/CalendarEventService.php';

use Core\DatabaseManager;
use Modules\Calendar\Services\CalendarEventService;

final class MissingCalendarEventDatabase extends DatabaseManager
{
    /** @var list<array<string, mixed>> */
    public array $queries = [];

    public function __construct()
    {
    }

    public function fetchOne(string $query, #[SensitiveParameter] array $params = []): ?array
    {
        $this->queries[] = ['sql' => $query, 'params' => $params];
        return null;
    }

    public function beginTransaction(): bool
    {
        return true;
    }

    public function endTransaction(bool $commit = true): void
    {
    }
}

function checkPrivateCalendarEvent(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
    exit(1);
}

$db = new MissingCalendarEventDatabase();
$calendar = new CalendarEventService($db);
$uid = '11111111-1111-4111-8111-111111111111';
$ownerId = 42;

foreach ([
    'чтение' => static fn () => $calendar->detailForUser($ownerId, $uid),
    'отмена' => static fn () => $calendar->cancelForUser($ownerId, $uid),
] as $operation => $action) {
    try {
        $action();
        checkPrivateCalendarEvent(false, $operation . ': несуществующая запись стала доступна');
    } catch (DomainException $error) {
        checkPrivateCalendarEvent(
            $error->getCode() === 404,
            $operation . ': отсутствующая запись не возвращает 404'
        );
    }
}

foreach ($db->queries as $query) {
    checkPrivateCalendarEvent(
        str_contains((string) $query['sql'], 'owner_user_id = :user_id'),
        'Запрос Calendar потерял ограничение по владельцу'
    );
    checkPrivateCalendarEvent(
        (int) ($query['params'][':user_id'] ?? 0) === $ownerId,
        'Запрос Calendar использовал неправильного владельца'
    );
}

$controller = file_get_contents(dirname(__DIR__, 2) . '/modules/calendar/controllers/CalendarApiController.php');
checkPrivateCalendarEvent(
    is_string($controller)
        && str_contains($controller, "'code' => 'calendar_not_found'")
        && str_contains($controller, "if (\$e->getCode() === 404)"),
    'API Calendar не сопоставляет отсутствие записи ответу 404'
);

fwrite(STDOUT, '[OK] Calendar не раскрывает чужую запись, отличает отсутствие от ошибки формы' . PHP_EOL);
