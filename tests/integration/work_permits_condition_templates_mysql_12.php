<?php

declare(strict_types=1);

// Цепочка всех реализованных этапов, включая проверку PDF на двух объектах.
require __DIR__ . '/work_permits_pdf_mysql_12.php';

use Core\DatabaseManager;
use Modules\WorkPermits\Services\WorkPermitDraftService;
use Modules\WorkPermits\Services\WorkPermitDraftEditService;
use Modules\WorkPermits\Services\WorkPermitIssuanceService;
use Modules\WorkPermits\Services\WorkPermitTemplateService;

DatabaseManager::resetInstance();
$db = DatabaseManager::getInstance();
$conditionsTemplates = new WorkPermitTemplateService($db);
$conditionsDrafts = new WorkPermitDraftService($db);
$conditionsEditor = new WorkPermitDraftEditService($db);
$conditionsIssuer = new WorkPermitIssuanceService($db);

$conditionsText = 'Работы выполнять с ограждением действующих токоведущих частей. '
    . 'Перед началом проверять соответствие рабочего места схеме.';
$instructionsText = 'Не допускать посторонних в помещение РЗА; '
    . 'перед переключениями уведомить оперативный персонал.';
$condition = $conditionsTemplates->create($userB, [
    'organization_id' => $org1['id'],
    'kind' => 'conditions',
    'name' => 'КРУН — стандартные условия',
    'work_description' => $conditionsText,
]);
$instruction = $conditionsTemplates->create($userB, [
    'organization_id' => $org1['id'],
    'kind' => 'instructions',
    'name' => 'Указания для панели РЗА',
    'work_description' => $instructionsText,
]);
wpAssert($condition['version'] === 1 && $instruction['version'] === 1,
    'Созданы два новых вида шаблонов');
wpAssert(count($conditionsTemplates->listing($org1['id'], 'conditions')['items']) === 1
    && count($conditionsTemplates->listing($org1['id'], 'instructions')['items']) === 1,
    'Условия и указания в разных списках');
wpAssert(count($conditionsTemplates->listing($org1['id'], 'job')['items']) === 1
    && count($conditionsTemplates->listing($org1['id'], 'crew')['items']) === 1,
    'Шаблоны заданий и бригад не затронуты миграцией');
wpAssert($conditionsTemplates->listing($org2['id'], 'conditions')['items'] === [],
    'Шаблон не доступен другим организациям');
try {
    $conditionsTemplates->detail($condition['id'], $org2['id']);
    throw new RuntimeException('Шаблон условий чужой организации доступен');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 404, 'Сервер запрещает чужие шаблоны');
}
try {
    $conditionsTemplates->create($userB, [
        'organization_id' => $org1['id'], 'kind' => 'instructions',
        'name' => 'Недопустимый размер',
        'work_description' => str_repeat('я', 4001),
    ]);
    throw new RuntimeException('Превышение предела текста указаний разрешено');
} catch (InvalidArgumentException) {
    // Указания ограничены 4000 символами.
}
try {
    $conditionsTemplates->create($userB, [
        'organization_id' => $org1['id'], 'kind' => 'conditions',
        'name' => 'Недопустимые условия',
        'work_description' => str_repeat('а', 6001),
    ]);
    throw new RuntimeException('Превышение предела условий разрешено');
} catch (InvalidArgumentException) {
    // Условия ограничены 6000 символами.
}

$draft = $conditionsDrafts->create($userA, [
    'kind' => 'permit',
    'organization_id' => $org1['id'],
    'department_id' => $dept['id'],
    'facility_id' => $facility['id'],
    'work_description' => 'Проверить токовые и напряженческие цепи РЗА',
    'command_id' => sprintf('%032x', 19001),
]);
$conditionsEditor->save($draft['id'], $userA, [
    'version' => 1, 'command_id' => sprintf('%032x', 19002),
    'organization_id' => $org1['id'], 'department_id' => $dept['id'],
    'facility_id' => $facility['id'],
    'work_description' => 'Проверить токовые и напряженческие цепи РЗА',
    'roles' => ['issuer' => $person['id'], 'producer' => $person['id']],
    'member_ids' => [$person['id']],
]);
$customConditions = $conditionsTemplates->detail($condition['id'], $org1['id'])['work_description']
    . ' Проверить границы ограждений конкретной ячейки.';
$customInstructions = $conditionsTemplates->detail($instruction['id'], $org1['id'])['work_description']
    . ' Уточнить порядок оповещения диспетчера.';
$input = [
    'version' => 2,
    'command_id' => sprintf('%032x', 19003),
    'planned_start' => '2026-11-04 08:00:00',
    'planned_finish' => '2026-11-04 18:00:00',
    'work_conditions' => $customConditions,
    'special_instructions' => $customInstructions,
    'measures' => [[
        'equipment' => 'КРУН-10, ячейка 4',
        'isolation' => 'Проверить отключённое состояние, установить ограждения',
        'remaining_live' => 'Соседние ячейки под напряжением',
    ]],
];
wpAssert($conditionsIssuer->issue($draft['id'], $userB, $input)['version'] === 3,
    'Выдача с двумя заполненными текстовыми разделами');
wpAssert($conditionsIssuer->issue($draft['id'], $userB, $input)['replayed'],
    'Повтор команды с условиями безопасен');

$detail = $conditionsDrafts->detail($draft['id'], $userA);
wpAssert($detail['lifecycle']['issuance']['work_conditions'] === $customConditions
    && $detail['lifecycle']['issuance']['special_instructions'] === $customInstructions,
    'Оба текста сохранены как самостоятельные снимки выданного наряда');
wpAssert(count($detail['lifecycle']['measures']) === 1,
    'Меры безопасности не подменяются общими условиями');

$conditionsTemplates->change($userB, $condition['id'], [
    'action' => 'replace', 'version' => 1,
    'work_description' => 'Новые условия, не предназначенные для выданного наряда.',
]);
$conditionsTemplates->change($userB, $condition['id'], [
    'action' => 'archive', 'version' => 2,
]);
$conditionsTemplates->change($userB, $instruction['id'], [
    'action' => 'rename', 'version' => 1,
    'name' => 'Переименованные указания для РЗА',
]);
$conditionsTemplates->change($userB, $instruction['id'], [
    'action' => 'archive', 'version' => 2,
]);
try {
    $conditionsTemplates->detail($condition['id'], $org1['id']);
    throw new RuntimeException('Архивный шаблон должен скрываться');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 404, 'Архивный текст исключён из выбора');
}
wpAssert($conditionsTemplates->detail($condition['id'], $org1['id'], true)['work_description']
    === 'Новые условия, не предназначенные для выданного наряда.',
    'Управляющий читает изменённый шаблон в архиве');
wpAssert($conditionsIssuer->issue($draft['id'], $userB, $input)['replayed'],
    'Изменение и архив шаблона не ломают повтор команды выдачи');
$after = $conditionsDrafts->detail($draft['id'], $userA);
wpAssert($after['lifecycle']['issuance']['work_conditions'] === $customConditions
    && $after['lifecycle']['issuance']['special_instructions'] === $customInstructions,
    'Исторические тексты не меняются при изменении справочника');

$printed = $renderer->render($after);
checkPdfWithPoppler($printed, [
    'Условия производства работ',
    'границы ограждений конкретной ячейки',
    'Отдельные указания',
    'порядок оповещения диспетчера',
], 2);

try {
    $conditionsIssuer->issue($draft['id'], $userB, [
        ...$input, 'work_conditions' => 'Подмена ранее выданных условий',
    ]);
    throw new RuntimeException('Изменённая команда не должна применяться');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Команда выдачи с другими условиями возвращает 409');
}

wpAssert((int) $db->fetchValue(
    'SELECT COUNT(*) FROM work_permit_template_history WHERE template_id=:id',
    [':id' => $condition['id']]
) === 3, 'Создание, замена и архивирование шаблона фиксируются в истории');

$routes = file_get_contents($root . '/modules/work-permits/WorkPermitsRuntimeProvider.php');
$controller = file_get_contents($root . '/modules/work-permits/controllers/WorkPermitController.php');
wpAssert(str_contains($routes, 'work_permits_template_create')
    && str_contains($routes, 'work_permits_template_change')
    && substr_count($routes, 'CSRFMiddleware::class') >= 14,
    'Изменение шаблонов защищено межсайтовой проверкой');
wpAssert(substr_count($controller, "'work_permits.manage'") >= 9,
    'Управление шаблонами требует отдельного разрешения');

DatabaseManager::resetInstance();
echo "Шаблоны условий и указаний, архив, снимки выдачи и PDF проверены\n";
