<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$dbHost = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$dbPort = (int) (getenv('TEST_DB_PORT') ?: 3306);
$dbName = getenv('TEST_DB_NAME') ?: 'workspace_test';
$dbUser = getenv('TEST_DB_USER') ?: 'workspace';
$dbPass = getenv('TEST_DB_PASS') ?: 'workspace';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$mysqli->set_charset('utf8mb4');

function wpAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wpApply(mysqli $db, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) {
        throw new RuntimeException('Не удалось прочитать схему ' . $path);
    }
    foreach (preg_split('/;\s*(?:\R|$)/', $sql) ?: [] as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $db->query($statement);
        }
    }
}

wpApply($mysqli, $root . '/database/core_identity_schema.sql');
wpApply($mysqli, $root . '/database/access_control_schema.sql');
wpApply($mysqli, $root . '/database/work_permits_schema.sql');

$mysqli->query("INSERT INTO users
    (uid,username,email,password_hash,firstname,lastname,role,is_active,account_status)
    VALUES ('79990000-0000-4000-8000-000000000001','work-permit-user-a','wp-a@example.test','hash',
            'Проверка','Первая',888,1,'active')");
$userA = (int) $mysqli->insert_id;
$mysqli->query("INSERT INTO users
    (uid,username,email,password_hash,firstname,lastname,role,is_active,account_status)
    VALUES ('79990000-0000-4000-8000-000000000002','work-permit-user-b','wp-b@example.test','hash',
            'Проверка','Вторая',888,1,'active')");
$userB = (int) $mysqli->insert_id;

putenv('DBDRIVER=mysql');
putenv('DBHOST=' . $dbHost);
putenv('DBPORT=' . $dbPort);
putenv('DBNAME=' . $dbName);
putenv('DBUSER=' . $dbUser);
putenv('DBPASS=' . $dbPass);

require_once $root . '/core/Config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/app/services/PermissionService.php';
require_once $root . '/modules/work-permits/services/WorkPermitCrewService.php';
require_once $root . '/modules/work-permits/services/WorkPermitDraftService.php';

\Core\DatabaseManager::resetInstance();
$db = \Core\DatabaseManager::getInstance();
$service = new \Modules\WorkPermits\Services\WorkPermitDraftService($db);
$permission = new \App\Services\PermissionService($db);

wpAssert($permission->hasPermission($userA, 'work_permits.use'), 'Пользователь должен иметь право на собственные черновики');
wpAssert(!$permission->hasPermission($userA, 'work_permits.manage'), 'Обычный пользователь не должен получать права администратора');

$input = [
    'kind' => 'permit',
    'organization' => 'Электросети',
    'subdivision' => 'РЭС',
    'facility' => 'ПС Болдыревка',
    'work_description' => 'Проверка цепей управления',
    'command_id' => str_repeat('a', 32),
];
$created = $service->create($userA, $input);
wpAssert($created['id'] > 0 && $created['id'] === $created['number'], 'Сервер обязан назначить сквозной номер');
wpAssert($created['state'] === 'draft' && !$created['replayed'], 'Создаётся только черновик');

$repeated = $service->create($userA, $input);
wpAssert($repeated['id'] === $created['id'] && $repeated['replayed'], 'Повтор той же команды не создаёт дубль');
wpAssert((int) $db->fetchValue('SELECT COUNT(*) FROM work_permit_documents') === 1,
    'Нельзя создавать второй наряд при повторном запросе');
wpAssert((int) $db->fetchValue('SELECT COUNT(*) FROM work_permit_history') === 1,
    'Повтор запроса не должен создавать второй журнал событий');

try {
    $service->create($userA, [...$input, 'work_description' => 'Другое задание']);
    throw new RuntimeException('Повтор команды с иными данными должен запрещаться');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Повтор с изменёнными данными обязан вернуть конфликт 409');
}

wpAssert($service->detail($created['id'], $userA) !== null, 'Автор видит свою запись');
wpAssert($service->detail($created['id'], $userB) === null, 'Чужая карточка недоступна');
wpAssert($service->listMine($userB)['items'] === [], 'Чужой журнал нельзя прочитать');

$order = $service->create($userB, [
    ...$input, 'kind' => 'order', 'command_id' => str_repeat('b', 32),
]);
wpAssert($order['id'] > $created['id'] && $order['state'] === 'draft',
    'Распоряжение должно использовать общий сквозной счётчик');
wpAssert(count($service->listMine($userA)['items']) === 1,
    'Журнал автора не должен включать чужие распоряжения');
wpAssert(count($service->listMine($userB)['items']) === 1,
    'Журнал второго пользователя показывает только его документы');

for ($i = 0; $i < 22; ++$i) {
    $service->create($userA, [
        ...$input,
        'command_id' => str_pad(dechex(256 + $i), 32, '0', STR_PAD_LEFT),
    ]);
}
$first = $service->listMine($userA, 20);
wpAssert(count($first['items']) === 20 && $first['next_cursor'] !== null,
    'Журнал должен возвращать ограниченную страницу и курсор');
$next = $service->listMine($userA, 20, (int) $first['next_cursor']);
wpAssert(count($next['items']) === 3 && $next['next_cursor'] === null,
    'Последняя страница не должна иметь продолжения');
wpAssert((int) $first['items'][0]['id'] > (int) $next['items'][0]['id'],
    'Должен соблюдаться порядок от новых документов к старым');

foreach ([['kind' => 'invalid'], ['organization' => ''], ['command_id' => 'short']] as $patch) {
    try {
        $service->create($userA, array_replace($input, $patch));
        throw new RuntimeException('Неверные сведения не должны сохраняться');
    } catch (InvalidArgumentException) {
        // Некорректные параметры отклонены до вставки.
    }
}

$manifest = json_decode((string) file_get_contents($root . '/modules/work-permits/module.json'), true, 512, JSON_THROW_ON_ERROR);
wpAssert(($manifest['package']['default_enabled'] ?? null) === false,
    'Новый модуль нельзя автоматически включать до готовности полного цикла');
wpAssert(in_array('work_permit_documents', $manifest['database']['tables'], true),
    'Манифест должен объявлять владельца документов');
$runtime = (string) file_get_contents($root . '/modules/work-permits/WorkPermitsRuntimeProvider.php');
wpAssert(str_contains($runtime, 'CSRFMiddleware::class')
    && str_contains($runtime, 'ApiLoginRequired::class'),
    'Изменяющий API должен требовать авторизацию и CSRF');

\Core\DatabaseManager::resetInstance();
echo "Проверены права, регистрация, повтор команды, журнал и изоляция документов\n";
