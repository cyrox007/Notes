<?php

declare(strict_types=1);

// Проверка с нуля: справочники → шаблоны → выдача → первый допуск.
require __DIR__ . '/work_permits_issue_admission_mysql_12.php';

use Core\DatabaseManager;
use Modules\WorkPermits\Services\WorkPermitDailyService;

DatabaseManager::resetInstance();
$db = DatabaseManager::getInstance();
$daily = new WorkPermitDailyService($db);
$doc = $filled['id'];
$first = $daily->periods($doc);
wpAssert(count($first) === 1 && $first[0]['start_kind'] === 'first'
    && $first[0]['ended_at'] === null,
    'Первичный допуск открывает первый рабочий период');

$break = [
    'version' => 4, 'command_id' => sprintf('%032x', 10001),
    'ended_at' => '2026-10-30 12:00:00',
    'note' => 'Перерыв на безопасном рабочем месте',
    'work_stopped' => true,
];
$paused = $daily->finish($doc, $userB, 'break', $break);
wpAssert($paused['version'] === 5 && !$paused['replayed'],
    'Перерыв завершил открытый период');
wpAssert($daily->finish($doc, $userB, 'break', $break)['replayed'],
    'Повтор команды перерыва не добавляет запись');
try {
    $daily->finish($doc, $userB, 'day_end', [
        ...$break, 'version' => 5, 'command_id' => sprintf('%032x', 10002),
    ]);
    throw new RuntimeException('Нельзя остановить уже остановленный рабочий период');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Вторая остановка без возобновления запрещена');
}
$workers = [
    'admitted_employee_id' => $person['id'],
    'briefed_by_employee_id' => $person['id'],
    'briefing_recipient_id' => $person['id'],
    'workplace_prepared' => true,
    'briefing_confirmed' => true,
];
$resume = [
    ...$workers, 'version' => 5,
    'command_id' => sprintf('%032x', 10003),
    'started_at' => '2026-10-30 13:00:00',
];
try {
    $daily->start($doc, $userB, 'daily_admission', $resume);
    throw new RuntimeException('Нельзя оформить новый день после обычного перерыва');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'После перерыва требуется возобновление того же дня');
}
try {
    $daily->start($doc, $userB, 'resume', [
        ...$resume, 'briefing_confirmed' => false,
    ]);
    throw new RuntimeException('Нельзя возобновлять работу без проведённого инструктажа');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Повторный инструктаж должен быть подтверждён');
}
try {
    $daily->start($doc, $userB, 'resume', [
        ...$resume, 'admitted_employee_id' => $otherEmployee['id'],
    ]);
    throw new RuntimeException('Чужой допускающий не допускается');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Работник другой организации отклонён');
}
$continued = $daily->start($doc, $userB, 'resume', $resume);
wpAssert($continued['version'] === 6, 'Возобновление открывает следующий период');
wpAssert($daily->start($doc, $userB, 'resume', $resume)['replayed'],
    'Повтор возобновления безопасен');
try {
    $daily->start($doc, $userB, 'resume', [
        ...$resume, 'command_id' => sprintf('%032x', 10004), 'version' => 6,
        'started_at' => '2026-10-30 14:00:00',
    ]);
    throw new RuntimeException('Перекрывающийся период разрешён');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Невозможно иметь две активные смены');
}
try {
    $daily->finish($doc, $userB, 'day_end', [
        ...$break, 'version' => 6, 'command_id' => sprintf('%032x', 10005),
        'ended_at' => '2026-10-29 16:00:00',
    ]);
    throw new RuntimeException('Дата остановки раньше начала');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Время остановки должно следовать за началом');
}
$dayEnd = [
    ...$break, 'version' => 6, 'command_id' => sprintf('%032x', 10006),
    'ended_at' => '2026-10-30 16:00:00',
    'note' => 'Первый рабочий день завершён',
];
wpAssert($daily->finish($doc, $userB, 'day_end', $dayEnd)['version'] === 7,
    'Рабочий день закрывается отдельным событием');
try {
    $daily->start($doc, $userB, 'resume', [
        ...$resume, 'version' => 7, 'command_id' => sprintf('%032x', 10007),
        'started_at' => '2026-10-30 16:30:00',
    ]);
    throw new RuntimeException('Возобновление после окончания дня разрешено');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'После окончания дня возможен только новый дневной допуск');
}
try {
    $daily->start($doc, $userB, 'daily_admission', [
        ...$resume, 'version' => 7, 'command_id' => sprintf('%032x', 10008),
        'started_at' => '2026-10-30 16:30:00',
    ]);
    throw new RuntimeException('Два дневных допуска в одни сутки разрешены');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Новый дневной допуск требует следующей даты UTC');
}
try {
    $daily->start($doc, $userB, 'daily_admission', [
        ...$resume, 'version' => 7, 'command_id' => sprintf('%032x', 10009),
        'started_at' => '2026-11-01 09:00:00',
    ]);
    throw new RuntimeException('Допуск за сроком наряда разрешён');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Нельзя допустить за пределами выданных сроков');
}
$nextDay = [
    ...$resume, 'version' => 7, 'command_id' => sprintf('%032x', 10010),
    'started_at' => '2026-10-31 09:00:00',
];
wpAssert($daily->start($doc, $userB, 'daily_admission', $nextDay)['version'] === 8,
    'Допуск на следующий день изменил версию');
wpAssert($daily->start($doc, $userB, 'daily_admission', $nextDay)['replayed'],
    'Повтор дневного допуска безопасен');
try {
    $daily->finish($doc, $userB, 'break', [
        ...$break, 'version' => 7, 'command_id' => sprintf('%032x', 10011),
        'ended_at' => '2026-10-31 10:00:00',
    ]);
    throw new RuntimeException('Устаревшая версия записала перерыв');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Конфликт версии блокирует устаревшее изменение');
}
$final = [
    ...$break, 'version' => 8, 'command_id' => sprintf('%032x', 10012),
    'ended_at' => '2026-10-31 16:00:00',
    'note' => 'Второй рабочий день завершён',
];
wpAssert($daily->finish($doc, $userB, 'day_end', $final)['version'] === 9,
    'Второй рабочий день завершился');
wpAssert(count($daily->periods($doc)) === 3, 'Хранится первый, возобновлённый и следующий дневной период');
wpAssert($daily->periods($doc)[2]['end_kind'] === 'day_end',
    'Конец каждого периода сохраняется отдельно');
wpAssert((int) $db->fetchValue(
    "SELECT COUNT(*) FROM work_permit_history
     WHERE document_id=:id AND event_type IN ('break','resume','day_end','daily_admission')",
    [':id' => $doc]
) === 5, 'Каждая успешная команда ровно один раз зафиксирована в истории');
wpAssert(count($drafts->detail($doc, $userA)['lifecycle']['periods']) === 3,
    'Автор видит всю историю без отдельного опасного маршрута');

try {
    $daily->start($order['id'], $userB, 'daily_admission', [
        ...$nextDay, 'version' => 3, 'command_id' => sprintf('%032x', 10013),
    ]);
    throw new RuntimeException('Ежедневный допуск по распоряжению не должен существовать');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Наряды и распоряжения сохраняют разные жизненные циклы');
}
$routes = (string) file_get_contents($root . '/modules/work-permits/WorkPermitsRuntimeProvider.php');
$controller = (string) file_get_contents($root . '/modules/work-permits/controllers/WorkPermitController.php');
wpAssert(str_contains($routes, 'work_permits_work_period')
    && substr_count($routes, 'CSRFMiddleware::class') >= 9,
    'События рабочих смен доступны только через защищённый маршрут');
wpAssert(substr_count($controller, "'work_permits.manage'") >= 7,
    'Право управления проверяется сервером');

DatabaseManager::resetInstance();
echo "Проверены дневные допуски, перерывы, возобновление и журнал без повторов\n";
