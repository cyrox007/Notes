<?php

declare(strict_types=1);

// Все ранее реализованные переходы, PDF и старые UTC-документы проверяются первыми.
require __DIR__ . '/work_permits_condition_templates_mysql_12.php';

use Core\DatabaseManager;
use Modules\WorkPermits\Services\WorkPermitDailyService;
use Modules\WorkPermits\Services\WorkPermitDraftEditService;
use Modules\WorkPermits\Services\WorkPermitDraftService;
use Modules\WorkPermits\Services\WorkPermitIssuanceService;
use Modules\WorkPermits\Services\WorkPermitLocalDay;
use Modules\WorkPermits\Services\WorkPermitReferenceService;

wpAssert(WorkPermitLocalDay::date('2026-11-01 23:10:00', 'Europe/Amsterdam')
    === '2026-11-02', 'Местная дата определяется независимо от UTC');
wpAssert(WorkPermitLocalDay::sameDay(
    '2026-10-25 00:30:00', '2026-10-25 01:30:00', 'Europe/Amsterdam'
), 'Повторный час при зимнем переводе времени принадлежит одним суткам');
wpAssert(!WorkPermitLocalDay::sameDay(
    '2026-03-28 22:30:00', '2026-03-28 23:30:00', 'Europe/Amsterdam'
), 'Ночь перевода часов на летнее время может пересекать местную полночь');
try {
    WorkPermitLocalDay::validate('Mars/Phobos');
    throw new RuntimeException('Выдуманная временная зона разрешена');
} catch (InvalidArgumentException) {
    // Часовые пояса проверяются по системной базе IANA.
}
try {
    WorkPermitLocalDay::date('2026-02-30 14:00:00', 'Europe/Amsterdam');
    throw new RuntimeException('Несуществующая дата разрешена');
} catch (InvalidArgumentException) {
    // Некорректные календарные даты нельзя нормализовать молча.
}

DatabaseManager::resetInstance();
$db = DatabaseManager::getInstance();
$references = new WorkPermitReferenceService($db);
$draftService = new WorkPermitDraftService($db);
$editService = new WorkPermitDraftEditService($db);
$issueService = new WorkPermitIssuanceService($db);
$dailyService = new WorkPermitDailyService($db);
$orgId = (int) $org1['id'];
$org = $db->fetchOne(
    'SELECT id,time_zone,version FROM work_permit_organizations WHERE id=:id',
    [':id' => $orgId]
);
wpAssert($org !== null, 'Проверяемая организация должна существовать');
wpAssert($org['time_zone'] === 'UTC', 'У старых организаций остаётся UTC');
$changed = $references->changeTimeZone($orgId, (int) $org['version'], $userB, 'Europe/Amsterdam');
wpAssert($changed['version'] === (int) $org['version'] + 1,
    'Изменение часового пояса поднимает версию организации');
try {
    $references->changeTimeZone($orgId, (int) $org['version'], $userB, 'Europe/London');
    throw new RuntimeException('Устаревшая версия справочника изменена');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Смена часового пояса защищена версией');
}

$draft = $draftService->create($userA, [
    'kind' => 'permit', 'organization_id' => $orgId, 'department_id' => $dept['id'],
    'facility_id' => $facility['id'],
    'work_description' => 'Вечерние допуски на ПС Болдыревка',
    'command_id' => sprintf('%032x', 25001),
]);
$editService->save($draft['id'], $userA, [
    'version' => 1, 'command_id' => sprintf('%032x', 25002),
    'organization_id' => $orgId, 'department_id' => $dept['id'],
    'facility_id' => $facility['id'],
    'work_description' => 'Вечерние допуски на ПС Болдыревка',
    'roles' => ['issuer' => $person['id'], 'producer' => $person['id']],
    'member_ids' => [$person['id']],
]);
wpAssert($draftService->detail($draft['id'], $userA)['organization_time_zone']
    === 'Europe/Amsterdam', 'Черновик получает актуальный пояс организации');
$issued = [
    'version' => 2,
    'time_zone' => 'Europe/Amsterdam',
    'command_id' => sprintf('%032x', 25003),
    'planned_start' => '2026-11-01 20:00:00',
    'planned_finish' => '2026-11-03 12:00:00',
    'special_instructions' => 'Местный рабочий день определяется по Амстердаму',
    'measures' => [[
        'equipment' => 'ПС Болдыревка',
        'isolation' => 'Проверить отсутствие напряжения',
        'remaining_live' => 'Соседние ячейки под напряжением',
    ]],
];
try {
    $issueService->issue($draft['id'], $userB, [
        ...$issued, 'command_id' => sprintf('%032x', 25013), 'time_zone' => 'UTC',
    ]);
    throw new RuntimeException('Выдача со старым часовым поясом разрешена');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409,
        'Пояс, изменившийся с момента открытия формы, вызывает конфликт');
}
wpAssert($draftService->detail($draft['id'], $userA)['state'] === 'draft',
    'Конфликт пояса не создаёт выдачу');
wpAssert($issueService->issue($draft['id'], $userB, $issued)['version'] === 3,
    'Документ выдан с часовым поясом организации');
$issueDetails = $draftService->detail($draft['id'], $userA)['lifecycle']['issuance'];
wpAssert($issueDetails['time_zone'] === 'Europe/Amsterdam',
    'Часовой пояс сохранён в самой выдаче');

$staff = [
    'admitted_employee_id' => $person['id'],
    'briefed_by_employee_id' => $person['id'],
    'briefing_recipient_id' => $person['id'],
    'workplace_prepared' => true,
    'briefing_confirmed' => true,
];
$first = [
    ...$staff, 'version' => 3, 'command_id' => sprintf('%032x', 25004),
    'started_at' => '2026-11-01 22:10:00',
];
wpAssert($issueService->firstAdmission($draft['id'], $userB, $first)['version'] === 4,
    'Первичный допуск открыт до местной полуночи');
try {
    $dailyService->finish($draft['id'], $userB, 'day_end', [
        'version' => 4, 'command_id' => sprintf('%032x', 25005),
        'ended_at' => '2026-11-01 23:05:00',
        'work_stopped' => true, 'note' => 'После местной полуночи',
    ]);
    throw new RuntimeException('Остановка пересекла местную полночь незаметно');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409,
        'Одна UTC-дата не означает один рабочий день в местном поясе');
}
$endDay = [
    'version' => 4, 'command_id' => sprintf('%032x', 25006),
    'ended_at' => '2026-11-01 22:40:00',
    'work_stopped' => true, 'note' => 'Смена окончена до местной полуночи',
];
wpAssert($dailyService->finish($draft['id'], $userB, 'day_end', $endDay)['version'] === 5,
    'Местный день закрыт до полуночи');
$nextDay = [
    ...$staff, 'version' => 5, 'command_id' => sprintf('%032x', 25007),
    'started_at' => '2026-11-01 23:10:00',
];
wpAssert($dailyService->start($draft['id'], $userB, 'daily_admission', $nextDay)['version'] === 6,
    'Новый местный день допускается при ещё прежней UTC-дате');
wpAssert($dailyService->start($draft['id'], $userB, 'daily_admission', $nextDay)['replayed'],
    'Повтор дневного допуска безопасен');

$changed = $references->changeTimeZone($orgId, $changed['version'], $userB, 'America/New_York');
wpAssert($changed['time_zone'] === 'America/New_York', 'Настройка организации изменена');
wpAssert($draftService->detail($draft['id'], $userA)['lifecycle']['issuance']['time_zone']
    === 'Europe/Amsterdam', 'Настройка не изменяет уже выданный наряд');
wpAssert($draftService->detail($draft['id'], $userA)['organization_time_zone']
    === 'America/New_York', 'Текущая настройка и снимок выдачи различаются');
wpAssert($dailyService->finish($draft['id'], $userB, 'day_end', [
    'version' => 6, 'command_id' => sprintf('%032x', 25008),
    'ended_at' => '2026-11-02 10:00:00', 'work_stopped' => true,
    'note' => 'Следующий местный день окончен',
])['version'] === 7, 'Второй рабочий день закрыт по снимку');
wpAssert($dailyService->start($draft['id'], $userB, 'daily_admission', [
    ...$staff, 'version' => 7, 'command_id' => sprintf('%032x', 25009),
    'started_at' => '2026-11-02 23:10:00',
])['version'] === 8, 'Третьи местные сутки открываются по исходному поясу');
wpAssert(count($dailyService->periods($draft['id'])) === 3,
    'Три допуска сохранены без пересечений');
wpAssert(str_contains($renderer->render($draftService->detail($draft['id'], $userA)), 'ToUnicode'),
    'Печатная форма допускает сохранённый часовой пояс');

$order = $draftService->create($userA, [
    'kind' => 'order', 'organization_id' => $orgId, 'department_id' => $dept['id'],
    'facility_id' => $facility['id'], 'work_description' => 'Распоряжение по местным суткам',
    'command_id' => sprintf('%032x', 25010),
]);
$editService->save($order['id'], $userA, [
    'version' => 1, 'command_id' => sprintf('%032x', 25011),
    'organization_id' => $orgId, 'department_id' => $dept['id'],
    'facility_id' => $facility['id'], 'work_description' => 'Распоряжение по местным суткам',
    'roles' => ['issuer' => $person['id'], 'producer' => $person['id']],
    'member_ids' => [],
]);
$orderIssue = [
    ...$issued, 'version' => 2, 'command_id' => sprintf('%032x', 25012),
    'planned_start' => '2026-11-02 23:00:00',
    'planned_finish' => '2026-11-03 00:00:00',
    'time_zone' => 'America/New_York',
];
wpAssert($issueService->issue($order['id'], $userB, $orderIssue)['version'] === 3,
    'Распоряжение через UTC-полночь разрешено, когда местные сутки не изменились');
wpAssert($draftService->detail($order['id'], $userA)['lifecycle']['issuance']['time_zone']
    === 'America/New_York', 'Распоряжение получает новый часовой пояс организации');
wpAssert((int) $db->fetchValue(
    "SELECT COUNT(*) FROM work_permit_reference_history WHERE reference_kind='organizations'
     AND reference_id=:id AND action='time_zone_changed'", [':id' => $orgId]
) === 2, 'Смена пояса зафиксирована дважды в истории без потерь');

DatabaseManager::resetInstance();
echo "Местные рабочие сутки, переходы часов, снимок пояса и распоряжения проверены\n";
