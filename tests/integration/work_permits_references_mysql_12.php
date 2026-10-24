<?php

declare(strict_types=1);

// Сначала выполняется существующий сценарий двух пользователей и черновиков.
// Он подготавливает временную MySQL-базу и проверяет обратную совместимость.
require __DIR__ . '/work_permits_draft_mysql_12.php';

use App\Services\PermissionService;
use Core\DatabaseManager;
use Modules\WorkPermits\Services\WorkPermitDraftService;
use Modules\WorkPermits\Services\WorkPermitReferenceService;

require_once $root . '/modules/work-permits/services/WorkPermitReferenceService.php';
DatabaseManager::resetInstance();
$db = DatabaseManager::getInstance();
$refs = new WorkPermitReferenceService($db);
$drafts = new WorkPermitDraftService($db);

$mysqli->query("INSERT IGNORE INTO role_permissions (role_id,permission_id)
    SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='work_permits.manage'
    WHERE r.code='admin'");
$mysqli->query("INSERT IGNORE INTO user_roles (user_id,role_id)
    SELECT " . (int) $userB . ",r.id FROM roles r WHERE r.code='admin'");
$permissions = new PermissionService($db);
wpAssert($permissions->hasPermission($userB, 'work_permits.manage'), 'Администратор может управлять справочниками');
wpAssert(!$permissions->hasPermission($userA, 'work_permits.manage'), 'Обычный пользователь не управляет справочниками');

$org1 = $refs->create('organizations', ['name' => 'АО Сети'], $userB);
$org2 = $refs->create('organizations', ['name' => 'АО Сети-2'], $userB);
$dept = $refs->create('departments', [
    'name' => 'Релейная служба', 'organization_id' => $org1['id'],
], $userB);
$facility = $refs->create('facilities', [
    'name' => 'ПС Болдыревка', 'organization_id' => $org1['id'],
], $userB);
$person = $refs->create('employees', [
    'name' => 'Иванов Иван Иванович', 'organization_id' => $org1['id'],
    'department_id' => $dept['id'], 'electrical_group' => 'IV',
], $userB);
wpAssert($person['id'] > 0, 'Справочник сотрудников регистрирует ФИО');

$orgList = $refs->listing('organizations');
wpAssert(count($orgList['items']) === 2, 'Получены активные организации');
$deptList = $refs->listing('departments', $org1['id']);
wpAssert(count($deptList['items']) === 1, 'Подразделение относится к организации');
wpAssert($refs->listing('departments', $org2['id'])['items'] === [],
    'Подразделение чужой организации не появляется в списке');
wpAssert(count($refs->listing('employees', $org1['id'])['items']) === 1,
    'Сотрудник отображается в справочнике выбранной организации');

$input = [
    'kind' => 'permit',
    'organization_id' => $org1['id'],
    'department_id' => $dept['id'],
    'facility_id' => $facility['id'],
    'work_description' => 'Проверка цепей и опробование РЗА',
    'command_id' => str_repeat('d', 32),
];
$created = $drafts->create($userA, $input);
$detail = $drafts->detail($created['id'], $userA);
wpAssert($detail !== null, 'Документ должен сохраняться');
wpAssert($detail['organization'] === 'АО Сети'
    && $detail['subdivision'] === 'Релейная служба'
    && $detail['facility'] === 'ПС Болдыревка',
    'Название берётся с сервера и сохраняется в черновик');
wpAssert((int) $detail['organization_id'] === $org1['id']
    && (int) $detail['department_id'] === $dept['id']
    && (int) $detail['facility_id'] === $facility['id'],
    'Черновик сохраняет самостоятельные идентификаторы справочников');
$renamed = $refs->change('organizations', $org1['id'], 1, $userB, 'АО Новые сети');
wpAssert($renamed['version'] === 2, 'Изменение повышает версию');
wpAssert($drafts->detail($created['id'], $userA)['organization'] === 'АО Сети',
    'Историческое название в ранее созданном черновике неизменно');
$replay = $drafts->create($userA, $input);
wpAssert($replay['replayed'] && $replay['id'] === $created['id'],
    'Переименование не ломает идемпотентность исходного запроса');

try {
    $refs->change('organizations', $org1['id'], 1, $userB, 'Старая версия');
    throw new RuntimeException('Конкурентное изменение должно конфликтовать');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Устаревшая версия вызывает конфликт 409');
}

try {
    $refs->create('employees', [
        'name' => 'Петров', 'organization_id' => $org2['id'],
        'department_id' => $dept['id'],
    ], $userB);
    throw new RuntimeException('Нельзя назначить чужое подразделение');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Сотрудник не может использовать подразделение другой организации');
}

try {
    $drafts->create($userA, [
        ...$input, 'organization_id' => $org2['id'], 'command_id' => str_repeat('e', 32),
    ]);
    throw new RuntimeException('Нельзя использовать чужой объект');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Чужой объект отклонён');
}
$archived = $refs->change('facilities', $facility['id'], 1, $userB, null, true);
wpAssert($archived['version'] === 2 && $archived['archived'], 'Объект должен архивироваться');
wpAssert($refs->listing('facilities', $org1['id'])['items'] === [],
    'Архивный объект скрывается при выборе в форме');
wpAssert(count($refs->listing('facilities', $org1['id'], true)['items']) === 1,
    'Управляющий может просмотреть архив');

try {
    $drafts->create($userA, [
        ...$input, 'command_id' => str_repeat('f', 32),
    ]);
    throw new RuntimeException('Архивный объект нельзя выбрать');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Архивный объект должен отклоняться сервером');
}
wpAssert($drafts->create($userA, $input)['replayed'],
    'Повтор старого запроса разрешён и после архивирования справочника');

$restored = $refs->change('facilities', $facility['id'], 2, $userB, null, false);
wpAssert($restored['version'] === 3 && !$restored['archived'], 'Восстановление объекта повышает версию');
wpAssert(count($refs->listing('facilities', $org1['id'])['items']) === 1,
    'Восстановленный объект снова выбирается');

for ($i=0; $i<104; $i++) {
    $refs->create('organizations', ['name'=>'Тестовая организация ' . $i], $userB);
}
$page = $refs->listing('organizations', 0, false, 100);
wpAssert(count($page['items']) === 100 && $page['next_cursor'] !== null,
    'Справочник возвращает ограниченную страницу');
$second = $refs->listing('organizations', 0, false, 100, (int) $page['next_cursor']);
wpAssert(count($second['items']) === 6 && $second['next_cursor'] === null,
    'Последняя страница справочника завершается');
wpAssert((int) $second['items'][0]['id'] < (int) $page['items'][0]['id'],
    'Курсор сохраняет порядок справочника');

foreach ([
    fn () => $refs->create('unknown', ['name'=>'X'], $userB),
    fn () => $refs->create('organizations', ['name'=>''], $userB),
    fn () => $refs->create('employees', ['name'=>'Неверная группа', 'organization_id'=>$org1['id'], 'electrical_group'=>'VII'], $userB),
    fn () => $refs->listing('organizations', 0, false, 101),
] as $invalid) {
    try {
        $invalid();
        throw new RuntimeException('Некорректный запрос не был отклонён');
    } catch (InvalidArgumentException) {
        // Все данные были проверены до обращения к SQL.
    }
}

wpAssert((int) $db->fetchValue('SELECT COUNT(*) FROM work_permit_reference_history') >= 112,
    'История справочников фиксирует создание и изменения');

$provider = (string) file_get_contents($root . '/modules/work-permits/WorkPermitsRuntimeProvider.php');
$controller = (string) file_get_contents($root . '/modules/work-permits/controllers/WorkPermitController.php');
wpAssert(substr_count($provider, 'CSRFMiddleware::class') >= 3,
    'Изменяющие маршруты защищены CSRF');
wpAssert(substr_count($controller, "'work_permits.manage'") >= 2,
    'Управление требует отдельного права, а не только права просмотра');

DatabaseManager::resetInstance();
echo "Проверены четыре справочника, версии, архив, история, ссылки черновика и пагинация\n";
