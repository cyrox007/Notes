<?php

declare(strict_types=1);

use Core\DatabaseManager;
use Modules\Defects\Services\DefectPriorityPolicy;
use Modules\Defects\Services\DefectRegistrationService;
use Modules\Defects\Services\DefectReferenceService;
use Modules\Defects\Services\DefectRegistryService;

$root = dirname(__DIR__, 2);
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/modules/defects/services/DefectHistoryService.php';
require_once $root . '/modules/defects/services/DefectPriorityPolicy.php';
require_once $root . '/modules/defects/services/DefectRegistrationService.php';
require_once $root . '/modules/defects/services/DefectReferenceService.php';
require_once $root . '/modules/defects/services/DefectRegistryService.php';

function defectsAssert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, 'Проверка регистрации дефектов: ' . $message . PHP_EOL);
    exit(1);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE defect_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type TEXT NOT NULL,
    entity_id INTEGER NOT NULL,
    event_type TEXT NOT NULL,
    actor_user_id INTEGER DEFAULT NULL,
    command_id TEXT DEFAULT NULL,
    payload_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE(entity_type,entity_id,event_type,command_id)
)');
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE defect_areas (id INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT \'\', is_archived INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE defect_object_categories (id INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT \'\', area_id INTEGER NOT NULL, is_archived INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE defect_objects (id INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT \'\', category_id INTEGER NOT NULL, is_archived INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE defect_services (id INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT \'\', is_archived INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE defects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    object_id INTEGER NOT NULL,
    description TEXT NOT NULL,
    priority TEXT NOT NULL,
    discovered_at TEXT NOT NULL,
    due_at TEXT NOT NULL,
    registered_by_name TEXT NOT NULL,
    registered_by_user_id INTEGER NOT NULL,
    creation_command_id TEXT NOT NULL UNIQUE,
    creation_request_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT "open",
    completed_work TEXT DEFAULT NULL,
    closed_at TEXT DEFAULT NULL,
    closed_by_user_id INTEGER DEFAULT NULL,
    close_command_id TEXT DEFAULT NULL UNIQUE,
    close_request_hash TEXT DEFAULT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec('CREATE TABLE defect_responsible_services (
    defect_id INTEGER NOT NULL REFERENCES defects(id),
    service_id INTEGER NOT NULL REFERENCES defect_services(id),
    executor_name TEXT DEFAULT NULL,
    PRIMARY KEY (defect_id, service_id)
)');
$pdo->exec('INSERT INTO defect_areas (id) VALUES (1)');
$pdo->exec('INSERT INTO defect_object_categories (id,area_id) VALUES (2,1)');
$pdo->exec('INSERT INTO defect_objects (id,category_id) VALUES (3,2)');
$pdo->exec('INSERT INTO defect_services (id) VALUES (4),(5)');

$database = DatabaseManager::forConnection($pdo);
$references = new DefectReferenceService($database);
$areaId = $references->createArea('  Северный РЭС  ');
defectsAssert($areaId === 2, 'новый участок не создан');
$categoryId = $references->createCategory($areaId, 'Подстанция');
$objectId = $references->createObject($categoryId, 'ТП-17');
$serviceId = $references->createService('Ремонтная служба');
defectsAssert($categoryId === 3 && $objectId === 4 && $serviceId === 6, 'справочники не сохранили идентификаторы');
defectsAssert(count($references->areas(1, 1)) === 1, 'пагинация участков нарушена');
defectsAssert(count($references->categories($areaId)) === 1, 'категория участка не найдена');
defectsAssert(count($references->objects($categoryId)) === 1, 'объект категории не найден');
defectsAssert(count($references->services(2, 4)) === 2, 'список служб не ограничен курсором');
$pdo->exec('UPDATE defect_areas SET is_archived = 1 WHERE id = 2');
defectsAssert($references->categories($areaId) === [], 'категория архивного участка стала доступной');
defectsAssert($references->objects($categoryId) === [], 'объект архивного участка стал доступным');
$pdo->exec('UPDATE defect_areas SET is_archived = 0 WHERE id = 2');
defectsAssert((string) $database->fetchValue(
    'SELECT name FROM defect_areas WHERE id = :id', ['id' => $areaId]
) === 'Северный РЭС', 'название участка не нормализовано');

$invalidParent = false;
try {
    $references->createCategory(999, 'Несуществующая категория');
} catch (InvalidArgumentException) {
    $invalidParent = true;
}
defectsAssert($invalidParent, 'нельзя создать категорию для отсутствующего участка');

$registrar = new DefectRegistrationService($database);
$detected = new DateTimeImmutable('2026-10-19 12:30:00', new DateTimeZone('Europe/Moscow'));
$register = static fn (string $command, array $services = [4, 5], string $description = 'Повреждение изоляции'):
    array => $registrar->register(3, $description, DefectPriorityPolicy::FIRST, $detected, 7, 'Иванов И. И.', $services, $command);

$registeredFrom = time();
$first = $register('command_1234567890AB');
defectsAssert($first['id'] === 1 && $first['replayed'] === false, 'карточка должна создаваться один раз');
$deadlineTime = strtotime($first['due_at'] . ' UTC');
defectsAssert($deadlineTime !== false
    && $deadlineTime >= $registeredFrom + 7 * 86400
    && $deadlineTime <= time() + 7 * 86400,
    'плановый срок должен отсчитываться от регистрации, а не от обнаружения');
defectsAssert((int) $database->fetchValue('SELECT COUNT(*) FROM defect_responsible_services') === 2, 'сохранены не все службы');
defectsAssert((int) $database->fetchValue("SELECT COUNT(*) FROM defect_history WHERE entity_type='defect' AND event_type='created'") === 1, 'создание не отражено в истории');

$replay = $register('command_1234567890AB', [5, 4]);
defectsAssert($replay['replayed'] === true && $replay['id'] === $first['id'], 'повтор команды должен вернуть прежнюю карточку');
defectsAssert((int) $database->fetchValue('SELECT COUNT(*) FROM defects') === 1, 'повтор создал второй дефект');

$conflict = false;
try {
    $register('command_1234567890AB', [4, 5], 'Другое повреждение');
} catch (DomainException) {
    $conflict = true;
}
defectsAssert($conflict, 'повтор команды с иным содержимым должен отклоняться');

$missingDescription = false;
try {
    $register('command_1234567890ZZ', [4], '   ');
} catch (InvalidArgumentException) {
    $missingDescription = true;
}
defectsAssert($missingDescription, 'нельзя создать дефект без описания');

$pdo->exec('UPDATE defect_services SET is_archived = 1 WHERE id = 5');
$archived = false;
try {
    $register('command_1234567890CD');
} catch (InvalidArgumentException) {
    $archived = true;
}
defectsAssert($archived, 'архивную службу нельзя назначить');
defectsAssert((int) $database->fetchValue('SELECT COUNT(*) FROM defects') === 1, 'неуспешная регистрация не должна сохранять карточку');
$pdo->exec('UPDATE defect_services SET is_archived = 0 WHERE id = 5');

$pdo->exec('CREATE TRIGGER reject_second_service BEFORE INSERT ON defect_responsible_services
    WHEN NEW.service_id = 5 BEGIN SELECT RAISE(ABORT, "Искусственный сбой связи"); END');
$rolledBack = false;
try {
    $register('command_1234567890EF');
} catch (PDOException) {
    $rolledBack = true;
}
defectsAssert($rolledBack, 'аварийная запись связи должна завершаться ошибкой');
defectsAssert((int) $database->fetchValue('SELECT COUNT(*) FROM defects') === 1, 'карточка должна откатываться вместе со связями');
defectsAssert(!$database->inTransaction(), 'ошибка не должна оставлять открытой транзакцию');
defectsAssert((int) $database->fetchValue("SELECT COUNT(*) FROM defect_history WHERE entity_type='defect'") === 1, 'после отката появилась лишняя история');


$pdo->exec('DROP TRIGGER reject_second_service');
$registry = new DefectRegistryService($database);
$records = $registry->list(40, 0, 'open');
defectsAssert(count($records) === 1 && (int) $records[0]['id'] === $first['id'], 'реестр должен отображать дефект');
defectsAssert(count($registry->detail($first['id'])['services'] ?? []) === 2, 'карточка должна содержать службы');
defectsAssert($registry->list(40, $first['id'], 'open') === [], 'курсор должен исключать предыдущую запись');

$pdo->exec("UPDATE defects SET due_at='2000-01-01 00:00:00' WHERE id=1");
$blockedOverdue = false;
try {
    $registry->close($first['id'], 1, 7, 'Заменён повреждённый изолятор', 'close_expired_1234567890AB', 'Иванов Иван', gmdate('c'));
} catch (DomainException $e) {
    $blockedOverdue = $e->getCode() === 409 && str_contains($e->getMessage(), 'срок');
}
defectsAssert($blockedOverdue, 'просроченную запись нельзя закрыть штатной командой');
defectsAssert((int) $database->fetchValue(
    'SELECT version FROM defects WHERE id=1'
) === 1, 'неуспешное закрытие изменило версию');
$pdo->prepare('UPDATE defects SET due_at=? WHERE id=1')->execute([$first['due_at']]);

$actualClosedAt = gmdate('c');
$closed = $registry->close($first['id'], 1, 7, 'Заменён повреждённый изолятор', 'close_1234567890ABCDE', 'Иванов Иван', $actualClosedAt, 'Проверено после ремонта');
defectsAssert($closed['version'] === 2 && !$closed['replayed'], 'дефект должен закрыться с новой версией');
$repeatClose = $registry->close($first['id'], 1, 7, 'Заменён повреждённый изолятор', 'close_1234567890ABCDE', 'Иванов Иван', $actualClosedAt, 'Проверено после ремонта');
defectsAssert($repeatClose['replayed'] && $repeatClose['version'] === 2, 'повтор закрытия должен быть безопасным');
$history = new \Modules\Defects\Services\DefectHistoryService($database);
$events = $history->list('defect', $first['id'], 1);
defectsAssert(count($events['events']) === 1 && $events['next_cursor'] !== null, 'история должна поддерживать страницы');
$continued = $history->list('defect', $first['id'], 1, $events['next_cursor']);
defectsAssert(count($continued['events']) === 1 && $continued['events'][0]['event_type'] === 'closed', 'закрытие не отражено в истории');
defectsAssert($continued['events'][0]['actor_user_id'] === 7, 'потерян автор закрытия');
$closedDetails = $continued['events'][0]['details'];
defectsAssert(($closedDetails['completed_by_name'] ?? null) === 'Иванов Иван', 'ФИО исполнителя не записано в историю');
defectsAssert(($closedDetails['close_comment'] ?? null) === 'Проверено после ремонта', 'Комментарий закрытия не сохранился');
defectsAssert(($closedDetails['closed_at'] ?? null) === gmdate('Y-m-d H:i:s', strtotime($actualClosedAt)), 'Фактическая дата закрытия не сохранилась');
defectsAssert((string) $database->fetchValue('SELECT closed_at FROM defects WHERE id=1') === $closedDetails['closed_at'], 'Дата в карточке не совпадает с историей');
defectsAssert($continued['next_cursor'] === null, 'последняя страница должна завершать курсор');
defectsAssert((int) $database->fetchValue("SELECT COUNT(*) FROM defect_history WHERE entity_type='defect'") === 2, 'повтор закрытия создал лишнюю историю');

$pdo->exec("CREATE TRIGGER reject_history BEFORE INSERT ON defect_history
    WHEN NEW.command_id='command_HISTORY_FAIL_01'
    BEGIN SELECT RAISE(ABORT, 'Искусственный сбой истории'); END");
$historyRollback = false;
try {
    $register('command_HISTORY_FAIL_01', [4]);
} catch (PDOException) {
    $historyRollback = true;
}
defectsAssert($historyRollback, 'сбой истории должен завершать регистрацию ошибкой');
defectsAssert((int) $database->fetchValue('SELECT COUNT(*) FROM defects') === 1, 'сбой записи истории оставил карточку');
defectsAssert((int) $database->fetchValue('SELECT COUNT(*) FROM defect_history') >= 2, 'сохранённые события пропали после отката');
$pdo->exec('DROP TRIGGER reject_history');

defectsAssert(count($registry->list(40, 0, 'closed')) === 1, 'реестр закрытых дефектов должен содержать запись');

$staleRejected = false;
try {
    $registry->close($first['id'], 1, 7, 'Ещё одно закрытие', 'close_1234567890XYZAB', 'Иванов Иван', gmdate('c'));
} catch (DomainException) {
    $staleRejected = true;
}
defectsAssert($staleRejected, 'просроченная версия должна вызывать конфликт');
defectsAssert(!$database->inTransaction(), 'конфликт закрытия не должен оставлять транзакцию');

echo 'Регистрация дефектов: запись, идемпотентность, проверка ссылок и откат проверены' . PHP_EOL;
