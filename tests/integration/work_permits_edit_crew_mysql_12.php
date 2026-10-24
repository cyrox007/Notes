<?php

declare(strict_types=1);

// Наследуем проверенные документы и справочники из предыдущего этапа.
require __DIR__ . '/work_permits_references_mysql_12.php';
require_once $root . '/modules/work-permits/services/WorkPermitDraftEditService.php';

use Core\DatabaseManager;
use Modules\WorkPermits\Services\WorkPermitDraftService;
use Modules\WorkPermits\Services\WorkPermitDraftEditService;
use Modules\WorkPermits\Services\WorkPermitReferenceService;

DatabaseManager::resetInstance();
$db = DatabaseManager::getInstance();
$refs = new WorkPermitReferenceService($db);
$drafts = new WorkPermitDraftService($db);
$editor = new WorkPermitDraftEditService($db);
$crewService = new \Modules\WorkPermits\Services\WorkPermitCrewService($db);

// Из предыдущей проверки: организация переименована, объект возвращён из архива.
// При этом предыдущий черновик с историческими названиями остаётся неизменным.
$secondEmployee = $refs->create('employees', [
    'name' => 'Петров Пётр Петрович',
    'organization_id' => $org1['id'],
    'department_id' => $dept['id'],
    'electrical_group' => 'III',
], $userB);
$otherEmployee = $refs->create('employees', [
    'name' => 'Сидоров Иван Иванович',
    'organization_id' => $org2['id'],
    'electrical_group' => 'II',
], $userB);

$document = $drafts->detail($created['id'], $userA);
wpAssert($document !== null && (int) $document['version'] === 1, 'Ожидалась исходная версия черновика');
wpAssert($document['crew'] === [], 'Новые черновики не должны получать выдуманный состав бригады');
$update = [
    'version' => 1,
    'command_id' => str_repeat('c', 32),
    'organization_id' => $org1['id'],
    'department_id' => $dept['id'],
    'facility_id' => $facility['id'],
    'work_description' => 'Проверка РЗА и вторичных цепей: уточнённое задание',
    'roles' => [
        'issuer' => $person['id'],
        'responsible_manager' => $person['id'],
        'admitter' => '',
        'producer' => $secondEmployee['id'],
        'observer' => null,
    ],
    'member_ids' => [$person['id'], $secondEmployee['id']],
];
$edited = $editor->save($created['id'], $userA, $update);
wpAssert($edited === ['id' => $created['id'], 'version' => 2, 'replayed' => false],
    'Первое изменение повышает версию до 2');
$detail = $drafts->detail($created['id'], $userA);
wpAssert($detail !== null && $detail['work_description'] === $update['work_description'],
    'Задание после редактирования сохранено');
wpAssert($detail['organization'] === 'АО Новые сети',
    'Изменение записывает текущее серверное название');
wpAssert($drafts->detail($created['id'], $userB) === null,
    'Администратор без специально реализованного доступа не читает чужой черновик');

$crew = $detail['crew'];
wpAssert(count($crew) === 5, 'Один сотрудник может быть одновременно выдающим, руководителем и членом бригады');
$assigned = array_filter($crew, static fn (array $row): bool =>
    (int) $row['employee_id'] === $person['id']
);
wpAssert(count($assigned) === 3, 'Сотрудник может одновременно занимать три допустимые роли');
wpAssert(count(array_filter($crew, static fn (array $row): bool => $row['role'] === 'member')) === 2,
    'Множественный выбор членов бригады сохранён');
wpAssert($crew[0]['employee_name'] === 'Иванов Иван Иванович',
    'Сохранён снимок ФИО сотрудника');

wpAssert($editor->save($created['id'], $userA, $update)['replayed'],
    'Повтор подтверждённой команды не создаёт второе изменение');
wpAssert((int) $db->fetchValue(
    'SELECT COUNT(*) FROM work_permit_history WHERE document_id=:id AND event_type=\'draft_updated\'',
    [':id' => $created['id']]
) === 1, 'Повтор команды не добавляет историю');
wpAssert((int) $db->fetchValue(
    'SELECT COUNT(*) FROM work_permit_edit_commands WHERE document_id=:id',
    [':id' => $created['id']]
) === 1, 'Повтор команды не добавляет запись операции');

try {
    $editor->save($created['id'], $userA, [
        ...$update, 'work_description' => 'Изменение без нового command_id',
    ]);
    throw new RuntimeException('Изменённое содержание команды должно быть отклонено');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Повтор команды с другим телом возвращает 409');
}

try {
    $editor->save($created['id'], $userA, [
        ...$update, 'command_id' => str_repeat('d', 32),
    ]);
    throw new RuntimeException('Устаревшая версия должна быть отклонена');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Редактирование устаревшей версии блокируется');
}

try {
    $editor->save($created['id'], $userB, [
        ...$update, 'version' => 2, 'command_id' => str_repeat('e', 32),
    ]);
    throw new RuntimeException('Чужой документ должен быть недоступен');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 404, 'Запрещено редактировать чужую карточку');
}

try {
    $editor->save($created['id'], $userA, [
        ...$update,
        'version' => 2,
        'command_id' => str_repeat('f', 32),
        'member_ids' => [$otherEmployee['id']],
    ]);
    throw new RuntimeException('Чужого сотрудника нельзя включать в бригаду');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Чужой сотрудник отклонён');
}
wpAssert(count($drafts->detail($created['id'], $userA)['crew']) === 5,
    'Неудачная команда не изменила бригаду или версию');
wpAssert((int) $drafts->detail($created['id'], $userA)['version'] === 2,
    'Неудачная команда не изменяет версию');

$renamed = $refs->change('employees', $person['id'], 1, $userB, 'Иванов И. И.');
wpAssert($renamed['version'] === 2, 'Переименование сотрудника повышает версию справочника');
wpAssert($drafts->detail($created['id'], $userA)['crew'][0]['employee_name'] === 'Иванов Иван Иванович',
    'Снимок ФИО в документе не меняется вместе со справочником');
wpAssert($editor->save($created['id'], $userA, $update)['replayed'],
    'Повтор старой команды после переименования сотрудника остаётся идемпотентным');

$next = [
    ...$update,
    'version' => 2,
    'command_id' => str_repeat('1', 32),
    'roles' => ['producer' => $secondEmployee['id']],
    'member_ids' => [],
];
$secondEdit = $editor->save($created['id'], $userA, $next);
wpAssert($secondEdit['version'] === 3, 'Следующее редактирование повышает версию до 3');
$crew = $drafts->detail($created['id'], $userA)['crew'];
wpAssert(count($crew) === 1 && $crew[0]['role'] === 'producer',
    'Удаление участников из формы действительно очищает прежние назначения');

$refs->change('employees', $secondEmployee['id'], 1, $userB, null, true);
wpAssert($editor->save($created['id'], $userA, $next)['replayed'],
    'Подтверждённая команда повторяется даже после архивирования сотрудника');
try {
    $editor->save($created['id'], $userA, [
        ...$next, 'version' => 3, 'command_id' => str_repeat('2', 32),
    ]);
    throw new RuntimeException('Архивного сотрудника нельзя заново назначить');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'При новом изменении архивированный сотрудник отклоняется');
}

$db->execute(
    'UPDATE work_permit_documents SET state=\'issued\' WHERE id=:id',
    [':id' => $created['id']]
);
try {
    $editor->save($created['id'], $userA, [
        ...$next, 'version' => 3,
        'command_id' => str_repeat('3', 32),
        'roles' => [], 'member_ids' => [],
    ]);
    throw new RuntimeException('Выданный документ не должен изменяться через редактор черновиков');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Состояние issued блокирует редактор черновика');
}
wpAssert($drafts->detail($created['id'], $userA)['state'] === 'issued',
    'Проверка состояния не изменяет документ');

$runtime = (string) file_get_contents($root . '/modules/work-permits/WorkPermitsRuntimeProvider.php');
wpAssert(str_contains($runtime, "WorkPermitController::class, 'edit'")
    && substr_count($runtime, 'CSRFMiddleware::class') >= 4,
    'HTTP-изменение документа требует авторизацию и CSRF');

DatabaseManager::resetInstance();
echo "Проверены версии, повтор команд, состав бригады, архив и границы доступа\n";
