<?php

declare(strict_types=1);

// Все прежние этапы и старые наряды проходят проверку без новых обязательных полей.
require __DIR__ . '/work_permits_local_day_mysql_12.php';
require_once $root . '/modules/work-permits/services/WorkPermitSafetyAuthorizationService.php';

use Core\DatabaseManager;
use Modules\WorkPermits\Services\WorkPermitDraftService;
use Modules\WorkPermits\Services\WorkPermitDraftEditService;
use Modules\WorkPermits\Services\WorkPermitIssuanceService;
use Modules\WorkPermits\Services\WorkPermitSafetyAuthorizationService;

DatabaseManager::resetInstance();
$db = DatabaseManager::getInstance();
$safety = new WorkPermitSafetyAuthorizationService($db);
$drafts = new WorkPermitDraftService($db);
$editing = new WorkPermitDraftEditService($db);
$issuing = new WorkPermitIssuanceService($db);

$safetyProducer = (new \Modules\WorkPermits\Services\WorkPermitReferenceService($db))->create(
    'employees',
    ['name' => 'Смирнов Пётр Алексеевич', 'organization_id' => $org1['id'],
     'department_id' => $dept['id'], 'electrical_group' => 'IV'],
    $userB
);

$prepared = $drafts->create($userA, [
    'kind' => 'permit', 'organization_id' => $org1['id'],
    'department_id' => $dept['id'], 'facility_id' => $facility['id'],
    'work_description' => 'Проверка РЗА после получения разрешений',
    'command_id' => sprintf('%032x', 31001),
]);
$editing->save($prepared['id'], $userA, [
    'version' => 1, 'command_id' => sprintf('%032x', 31002),
    'organization_id' => $org1['id'], 'department_id' => $dept['id'],
    'facility_id' => $facility['id'],
    'work_description' => 'Проверка РЗА после получения разрешений',
    'roles' => [
        'issuer' => $person['id'],
        'responsible_manager' => $person['id'],
        'admitter' => $person['id'],
        'producer' => $safetyProducer['id'],
    ],
    'member_ids' => [$person['id'], $safetyProducer['id']],
]);
$issuing->issue($prepared['id'], $userB, [
    'version' => 2, 'command_id' => sprintf('%032x', 31003),
    'planned_start' => '2026-12-07 08:00:00',
    'planned_finish' => '2026-12-08 18:00:00',
    'special_instructions' => '',
    'measures' => [[
        'equipment' => 'КРУН-10, ячейка 4',
        'isolation' => 'Отключить, проверить отсутствие напряжения, установить заземление',
        'remaining_live' => 'Смежная ячейка под напряжением',
    ]],
]);
$doc = $prepared['id'];
$permission = [
    'version' => 3, 'command_id' => sprintf('%032x', 31004),
    'granted_at' => '2026-12-07 07:30:00',
    'grantor_employee_id' => $safetyProducer['id'],
    'receiver_employee_id' => $person['id'],
    'workplace' => 'КРУН-10, ячейка 4',
    'communication_method' => 'radio',
    'note' => 'Разрешение получено от оперативного персонала',
    'permission_confirmed' => true,
];
wpAssert($safety->preparationPermission($doc, $userB, $permission)['version'] === 4,
    'Разрешение на подготовку места фиксируется отдельной записью');
wpAssert($safety->preparationPermission($doc, $userB, $permission)['replayed'],
    'Повтор разрешения не создаёт дубль');
try {
    $safety->preparationPermission($doc, $userB, [...$permission, 'workplace' => 'Иная ячейка']);
    throw new RuntimeException('Идентификатор разрешения повторно использован с другим содержимым');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Подмена ранее применённой команды запрещена');
}
foreach ([
    [...$permission, 'command_id' => sprintf('%032x', 31005),
        'version' => 4, 'receiver_employee_id' => $otherEmployee['id']],
    [...$permission, 'command_id' => sprintf('%032x', 31006),
        'version' => 4, 'grantor_employee_id' => $person['id']],
    [...$permission, 'command_id' => sprintf('%032x', 31007),
        'version' => 4, 'permission_confirmed' => false],
] as $rejected) {
    try {
        $safety->preparationPermission($doc, $userB, $rejected);
        throw new RuntimeException('Недействительное разрешение сохранено');
    } catch (DomainException $e) {
        wpAssert($e->getCode() === 409, 'Разрешение отклонено до записи');
    }
}

$issuerBriefing = [
    'version' => 4, 'command_id' => sprintf('%032x', 31008),
    'stage' => 'issuer',
    'briefed_at' => '2026-12-07 07:40:00',
    'instructor_employee_id' => $person['id'],
    'recipient_employee_id' => $safetyProducer['id'],
    'topic' => 'Границы рабочего места и опасные части оборудования',
    'briefing_confirmed' => true,
];
wpAssert($safety->targetBriefing($doc, $userB, $issuerBriefing)['version'] === 5,
    'Выдавший наряд фиксирует персональный инструктаж');
wpAssert($safety->targetBriefing($doc, $userB, $issuerBriefing)['replayed'],
    'Повтор инструктажа безопасен');
$managerBriefing = [
    ...$issuerBriefing, 'version' => 5,
    'command_id' => sprintf('%032x', 31009),
    'stage' => 'manager', 'briefed_at' => '2026-12-07 07:45:00',
];
wpAssert($safety->targetBriefing($doc, $userB, $managerBriefing)['version'] === 6,
    'Инструктаж руководителем записан отдельно');
$producerBriefing = [
    ...$issuerBriefing, 'version' => 6,
    'command_id' => sprintf('%032x', 31010), 'stage' => 'producer',
    'instructor_employee_id' => $safetyProducer['id'],
    'recipient_employee_id' => $person['id'],
    'briefed_at' => '2026-12-07 07:50:00',
];
wpAssert($safety->targetBriefing($doc, $userB, $producerBriefing)['version'] === 7,
    'Инструктаж производителем члену бригады записан');
$admitterBriefing = [
    ...$issuerBriefing, 'version' => 7,
    'command_id' => sprintf('%032x', 31011), 'stage' => 'admitter',
    'briefed_at' => '2026-12-07 08:05:00',
];
wpAssert($safety->targetBriefing($doc, $userB, $admitterBriefing)['version'] === 8,
    'Инструктаж допускающим выделен в собственную строку');
foreach ([
    [...$issuerBriefing, 'version' => 8, 'command_id' => sprintf('%032x', 31012),
        'instructor_employee_id' => $safetyProducer['id']],
    [...$issuerBriefing, 'version' => 8, 'command_id' => sprintf('%032x', 31013),
        'recipient_employee_id' => $otherEmployee['id']],
    [...$issuerBriefing, 'version' => 8, 'command_id' => sprintf('%032x', 31014),
        'recipient_employee_id' => $person['id']],
    [...$issuerBriefing, 'version' => 8, 'command_id' => sprintf('%032x', 31015),
        'briefing_confirmed' => false],
] as $rejected) {
    try {
        $safety->targetBriefing($doc, $userB, $rejected);
        throw new RuntimeException('Недействительный инструктаж сохранён');
    } catch (DomainException $e) {
        wpAssert($e->getCode() === 409, 'Сервер проверяет состав, роли и факт инструктажа');
    }
}
try {
    $safety->targetBriefing($doc, $userB, [
        ...$issuerBriefing, 'version' => 8, 'command_id' => sprintf('%032x', 31016),
        'topic' => str_repeat('я', 1001),
    ]);
    throw new RuntimeException('Превышение ограничения текста разрешено');
} catch (InvalidArgumentException) {
    // Тема ограничена 1000 символами.
}
$read = $drafts->detail($doc, $userA);
wpAssert(count($read['lifecycle']['preparation_permissions']) === 1
    && count($read['lifecycle']['target_briefings']) === 4,
    'Автор видит отдельные записи разрешения и каждого вида инструктажа');
wpAssert($read['lifecycle']['target_briefings'][0]['recipient_name'] !== '',
    'ФИО получателя хранится историческим снимком');
$printed = $renderer->render($read);
checkPdfWithPoppler($printed, [
    'Разрешения на подготовку рабочих мест и допуск',
    'Целевые инструктажи',
    'Границы рабочего места',
    'КРУН-10, ячейка 4',
], 2);
wpAssert((int) $db->fetchValue(
    "SELECT COUNT(*) FROM work_permit_history WHERE document_id=:doc
     AND event_type IN ('preparation_permission','target_briefing')",
    [':doc' => $doc]
) === 5, 'Пять событий отражены в неизменяемой истории документа');

$admitted = $issuing->firstAdmission($doc, $userB, [
    'version' => 8, 'command_id' => sprintf('%032x', 31017),
    'started_at' => '2026-12-07 08:10:00',
    'admitted_employee_id' => $person['id'],
    'briefed_by_employee_id' => $person['id'],
    'briefing_recipient_id' => $safetyProducer['id'],
    'workplace_prepared' => true, 'briefing_confirmed' => true,
]);
wpAssert($admitted['version'] === 9, 'Старый допуск совместим с отдельными новыми сведениями');
try {
    $safety->preparationPermission($doc, $userB, [
        ...$permission, 'version' => 9, 'command_id' => sprintf('%032x', 31018),
    ]);
    throw new RuntimeException('Разрешение на подготовку после первого допуска допустили');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Разрешение на подготовку после первого допуска запрещено');
}
try {
    $safety->targetBriefing($doc, $userB, [
        ...$issuerBriefing, 'version' => 9, 'command_id' => sprintf('%032x', 31019),
    ]);
    throw new RuntimeException('Инструктаж выдающего наряд после первого допуска допустили');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Инструктаж выдающим после первого допуска запрещён');
}
wpAssert($safety->details($filled['id'])['preparation_permissions'] === []
    && $safety->details($filled['id'])['target_briefings'] === [],
    'Ранее закрытые документы не получили выдуманных разрешений или подписей');
$routes = file_get_contents($root . '/modules/work-permits/WorkPermitsRuntimeProvider.php');
$controller = file_get_contents($root . '/modules/work-permits/controllers/WorkPermitController.php');
wpAssert(str_contains($routes, "'work_permits_preparation_permission'")
    && str_contains($routes, "'work_permits_target_briefing'")
    && substr_count($routes, 'CSRFMiddleware::class') >= 16,
    'Маршруты новых операций защищены CSRF');
wpAssert(str_contains($controller, 'WorkPermitSafetyAuthorizationService')
    && substr_count($controller, "'work_permits.manage'") >= 10,
    'Новые записи разрешены только управляющему');

DatabaseManager::resetInstance();
echo "Разрешения, целевые инструктажи, защита версий, исторические ФИО и PDF проверены\n";
