<?php

declare(strict_types=1);

// Полная цепочка предыдущих этапов сохраняется: справочники, черновики,
// роли, версии и шаблоны, затем отдельно проверяются выдача и первый допуск.
require __DIR__ . '/work_permits_templates_mysql_12.php';
require_once $root . '/modules/work-permits/services/WorkPermitIssuanceService.php';

use Core\DatabaseManager;
use Modules\WorkPermits\Services\WorkPermitIssuanceService;

DatabaseManager::resetInstance();
$db = DatabaseManager::getInstance();
$lifecycle = new WorkPermitIssuanceService($db);

$issue = [
    'version' => 2,
    'command_id' => str_repeat('6', 32),
    'planned_start' => '2026-10-30 08:00:00',
    'planned_finish' => '2026-10-31 17:00:00',
    'special_instructions' => 'Работу выполнять с контролем места и ограждений.',
    'measures' => [
        [
            'equipment' => 'ПС Болдыревка, КРУН-10',
            'isolation' => 'Отключить аппарат, проверить отсутствие напряжения, установить заземление',
            'remaining_live' => 'Соседние токоведущие части остаются под напряжением',
        ],
        [
            'equipment' => 'Панель РЗА',
            'isolation' => 'Первичное оборудование не выводится; оградить опасные части',
            'remaining_live' => 'Шины 10 кВ под напряжением',
        ],
    ],
];
$management = $drafts->managementList(20, 0, 'draft');
wpAssert(count($management['items']) > 0 && $drafts->detail($filled['id'], $userB) === null,
    'Управленческий список не изменяет права обычного чтения');
wpAssert($drafts->detail($filled['id'], $userB, true) !== null,
    'Управляющий может открыть черновик другого автора');
wpAssert((int) $drafts->detail($filled['id'], $userA)['version'] === 2,
    'До выдачи сохраняется отредактированный черновик');
try {
    $lifecycle->issue($filled['id'], $userB, [
        ...$issue, 'measures' => [],
    ]);
    throw new RuntimeException('Нельзя выдавать наряд без мер безопасности');
} catch (InvalidArgumentException) {
    // Требуются отдельные строки подготовки рабочего места.
}
$issued = $lifecycle->issue($filled['id'], $userB, $issue);
wpAssert($issued['id'] === $filled['id'] && $issued['version'] === 3
    && $issued['state'] === 'issued' && !$issued['replayed'],
    'Выдача переводит черновик в выданное состояние');
$detail = $drafts->detail($filled['id'], $userA);
wpAssert($detail['state'] === 'issued' && count($detail['lifecycle']['measures']) === 2,
    'Мероприятия и состояние сохранены отдельно от карточки');
wpAssert($detail['lifecycle']['first_admission'] === null,
    'Выдача сама по себе не считается состоявшимся допуском');
wpAssert((int) $detail['lifecycle']['issuance']['issuer_employee_id'] === $person['id'],
    'Выдающий сотрудник берётся из проверенной бригады');
wpAssert($lifecycle->issue($filled['id'], $userB, $issue)['replayed'],
    'Повтор команды выдачи не создаёт новое событие');

try {
    $lifecycle->issue($filled['id'], $userB, [
        ...$issue, 'special_instructions' => 'Изменённое содержимое',
    ]);
    throw new RuntimeException('Изменённая команда не должна применяться');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Содержимое повторной команды не может меняться');
}
try {
    $lifecycle->issue($filled['id'], $userB, [
        ...$issue, 'command_id' => str_repeat('7', 32),
    ]);
    throw new RuntimeException('Нельзя выдавать наряд дважды');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Вторичная выдача без смены версии запрещена');
}
try {
    $editor->save($filled['id'], $userA, [
        'version' => 3,
        'command_id' => str_repeat('8', 32),
        'organization_id' => $org1['id'],
        'department_id' => $dept['id'],
        'facility_id' => $facility['id'],
        'work_description' => 'Изменение уже выданного',
        'roles' => [],
        'member_ids' => [],
    ]);
    throw new RuntimeException('Выданный наряд нельзя исправить через черновик');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Редактор черновика закрыт после выдачи');
}
$admission = [
    'version' => 3,
    'command_id' => str_repeat('9', 32),
    'started_at' => '2026-10-30 09:10:00',
    'admitted_employee_id' => $person['id'],
    'briefed_by_employee_id' => $person['id'],
    'briefing_recipient_id' => $person['id'],
    'workplace_prepared' => true,
    'briefing_confirmed' => true,
];
try {
    $lifecycle->firstAdmission($filled['id'], $userB, [
        ...$admission, 'briefing_confirmed' => false,
    ]);
    throw new RuntimeException('Допуск без фактического инструктажа невозможен');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Инструктаж должен быть явно подтверждён');
}
try {
    $lifecycle->firstAdmission($filled['id'], $userB, [
        ...$admission, 'started_at' => '2026-11-01 09:10:00',
    ]);
    throw new RuntimeException('Допуск не может выходить за срок наряда');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Ограничен срок первоначального допуска');
}
try {
    $lifecycle->firstAdmission($filled['id'], $userB, [
        ...$admission, 'admitted_employee_id' => $otherEmployee['id'],
    ]);
    throw new RuntimeException('Допуск чужим работником запрещён');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Допускающий должен относиться к организации');
}
$recorded = $lifecycle->firstAdmission($filled['id'], $userB, $admission);
wpAssert($recorded['version'] === 4 && !$recorded['replayed'],
    'Первичный допуск увеличивает версию документа');
$detail = $drafts->detail($filled['id'], $userA);
wpAssert($detail['state'] === 'issued' && $detail['lifecycle']['first_admission'] !== null,
    'Первый допуск отдельный от состояния выданного наряда');
wpAssert($detail['lifecycle']['first_admission']['briefed_by_name'] === 'Иванов И. И.',
    'ФИО инструктора записывается снимком в момент допуска');
wpAssert($lifecycle->firstAdmission($filled['id'], $userB, $admission)['replayed'],
    'Повтор допуска не дублирует событие');
wpAssert((int) $db->fetchValue(
    'SELECT COUNT(*) FROM work_permit_first_admissions WHERE document_id=:id',
    [':id' => $filled['id']]
) === 1, 'Первый допуск записывается только один раз');
wpAssert((int) $db->fetchValue(
    'SELECT COUNT(*) FROM work_permit_lifecycle_commands WHERE document_id=:id',
    [':id' => $filled['id']]
) === 2, 'Обе команды имеют отдельный уникальный след');
wpAssert((int) $db->fetchValue(
    "SELECT COUNT(*) FROM work_permit_history WHERE document_id=:id AND event_type IN ('issued','first_admission')",
    [':id' => $filled['id']]
) === 2, 'Выдача и допуск добавили по одному событию истории');

$order = $drafts->create($userA, [
    'kind' => 'order',
    'organization_id' => $org1['id'],
    'department_id' => $dept['id'],
    'facility_id' => $facility['id'],
    'work_description' => 'Проверка панели по распоряжению',
    'command_id' => str_repeat('0', 32),
]);
$editor->save($order['id'], $userA, [
    'version' => 1, 'command_id' => str_repeat('b', 32),
    'organization_id' => $org1['id'], 'department_id' => $dept['id'],
    'facility_id' => $facility['id'],
    'work_description' => 'Проверка панели по распоряжению',
    'roles' => ['issuer' => $person['id'], 'producer' => $person['id']],
    'member_ids' => [],
]);
try {
    $lifecycle->issue($order['id'], $userB, [
        ...$issue, 'version' => 2, 'command_id' => str_repeat('c', 32),
    ]);
    throw new RuntimeException('Многодневное распоряжение не может выдаваться');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Распоряжение ограничено одной сменой');
}
$lifecycle->issue($order['id'], $userB, [
    ...$issue, 'version' => 2, 'command_id' => str_repeat('d', 32),
    'planned_finish' => '2026-10-30 17:00:00',
]);
try {
    $lifecycle->firstAdmission($order['id'], $userB, [
        ...$admission, 'command_id' => str_repeat('e', 32),
    ]);
    throw new RuntimeException('Для распоряжений отдельный первый допуск не предусмотрен');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Допуск к распоряжению не должен применяться');
}

$routes = (string) file_get_contents($root . '/modules/work-permits/WorkPermitsRuntimeProvider.php');
$controller = (string) file_get_contents($root . '/modules/work-permits/controllers/WorkPermitController.php');
wpAssert(substr_count($routes, 'CSRFMiddleware::class') >= 8,
    'Выдача и допуск должны быть защищены CSRF');
wpAssert(substr_count($controller, "'work_permits.manage'") >= 6,
    'Выдача и допуск требуют права управления');
DatabaseManager::resetInstance();
echo "Проверены выдача и первичный допуск, сроки, мероприятия, версии и повторы\n";
