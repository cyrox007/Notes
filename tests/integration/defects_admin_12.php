<?php

declare(strict_types=1);

use Core\DatabaseManager;
use Modules\Defects\Services\DefectAdministrationService;
use Modules\Defects\Services\DefectHistoryService;
use Modules\Defects\Services\DefectReportService;

$root = dirname(__DIR__, 2);
require_once $root . '/core/DatabaseManager.php';
foreach ([
    'DefectPriorityPolicy', 'DefectHistoryService', 'DefectAdministrationService', 'DefectReportService',
] as $class) {
    require_once $root . '/modules/defects/services/' . $class . '.php';
}
function assertAdmin(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, 'Администрирование дефектов: ' . $message . PHP_EOL);
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE defect_areas(id INTEGER PRIMARY KEY,name TEXT,is_archived INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE defect_object_categories(id INTEGER PRIMARY KEY,area_id INTEGER,name TEXT,is_archived INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE defect_objects(id INTEGER PRIMARY KEY,category_id INTEGER,name TEXT,is_archived INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE defect_services(id INTEGER PRIMARY KEY,name TEXT,is_archived INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE defects(
    id INTEGER PRIMARY KEY,version INTEGER NOT NULL DEFAULT 1,is_archived INTEGER NOT NULL DEFAULT 0,
    archived_at TEXT DEFAULT NULL,object_id INTEGER NOT NULL,description TEXT NOT NULL,
    priority TEXT NOT NULL,discovered_at TEXT NOT NULL,due_at TEXT NOT NULL,
    registered_by_name TEXT NOT NULL,registered_by_user_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT "open",completed_work TEXT,closed_at TEXT,closed_by_user_id INTEGER,
    created_at TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE defect_responsible_services(defect_id INTEGER,service_id INTEGER)');
$pdo->exec('CREATE TABLE defect_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,entity_type TEXT NOT NULL,entity_id INTEGER NOT NULL,
    event_type TEXT NOT NULL,actor_user_id INTEGER,command_id TEXT,
    payload_json TEXT NOT NULL,created_at TEXT NOT NULL,
    UNIQUE(entity_type,entity_id,event_type,command_id)
)');
$pdo->exec("INSERT INTO defect_areas VALUES (1,'РЭС',0)");
$pdo->exec("INSERT INTO defect_object_categories VALUES (1,1,'Линия',0)");
$pdo->exec("INSERT INTO defect_objects VALUES (1,1,'Л-1',0),(2,1,'Л-2',0)");
$pdo->exec("INSERT INTO defect_services VALUES (1,'Служба',0)");
$pdo->exec("INSERT INTO defects VALUES
    (1,1,0,NULL,1,'Повреждение','normal','2026-01-01 09:00:00',
     '2026-12-01 12:00:00','Иванов',17,'open',NULL,NULL,NULL,'2026-01-01 10:00:00'),
    (2,1,0,NULL,1,'Устранено','normal','2026-01-01 09:00:00',
     '2026-01-10 12:00:00','Иванов',17,'closed','Ремонт','2026-01-11 12:00:00',22,'2026-01-01 10:00:00')");
$pdo->exec('INSERT INTO defect_responsible_services VALUES (1,1),(2,1)');
$db = DatabaseManager::forConnection($pdo);
$admin = new DefectAdministrationService($db);
$report = new DefectReportService($db);

$command = 'correct_1234567890ABC';
$changed = $admin->correct(1, 1, 31, [
    'description' => 'Повреждение уточнено',
    'object_id' => 2,
    'due_at' => '2026-12-04T15:00:00+03:00',
], 'Уточнение по акту осмотра', $command);
assertAdmin($changed['version'] === 2 && $changed['changed'], 'не исправилась карточка');
$record = $db->fetchOne('SELECT * FROM defects WHERE id=1');
assertAdmin($record['object_id'] == 2 && $record['due_at'] === '2026-12-04 12:00:00',
    'данные исправления не сохранены');
assertAdmin($record['registered_by_user_id'] == 17, 'потерян исходный автор');
assertAdmin($admin->correct(1, 1, 31, [
    'object_id' => 2, 'due_at' => '2026-12-04T12:00:00Z',
    'description' => 'Повреждение уточнено',
], 'Уточнение по акту осмотра', $command)['replayed'], 'повтор операции не распознан');
assertAdmin((int) $db->fetchValue('SELECT COUNT(*) FROM defect_history') === 1,
    'повтор добавил лишнее событие');

$conflict = false;
try {
    $admin->correct(1, 1, 32, ['description' => 'Чужая правка'], 'Чужая версия документа', 'correct_1234567890DEF');
} catch (DomainException $e) {
    $conflict = $e->getCode() === 409;
}
assertAdmin($conflict, 'устаревшая версия перезаписала запись');
$forbidden = false;
try {
    $admin->correct(1, 2, 31, ['status' => 'closed'], 'Обход штатного закрытия', 'correct_1234567890GHI');
} catch (InvalidArgumentException) {
    $forbidden = true;
}
assertAdmin($forbidden, 'разрешено переписывать статус и происхождение без процедуры');

$archived = $admin->archive(1, 2, 31, 'Дубликат после проверки', 'archive_1234567890ABC');
assertAdmin($archived['version'] === 3, 'архив не увеличил версию');
assertAdmin((int) $db->fetchValue('SELECT is_archived FROM defects WHERE id=1') === 1,
    'карточка не попала в архив');
assertAdmin(count($report->search(['status' => 'all'])['items']) === 1,
    'архивная запись осталась в штатном списке');
assertAdmin(count($report->search(['status' => 'all', 'archived' => '1'])['items']) === 1,
    'архивная запись потерялась');
assertAdmin((int) $db->fetchValue('SELECT COUNT(*) FROM defect_responsible_services WHERE defect_id=1') === 1,
    'архивирование физически удалило связи');
assertAdmin($admin->archive(1, 2, 31, 'Дубликат после проверки', 'archive_1234567890ABC')['replayed'],
    'архив нельзя безопасно повторить');

$archiveConflict = false;
try {
    $admin->correct(1, 3, 31, ['description' => 'Ошибка'], 'Попытка изменить архив', 'correct_1234567890JKL');
} catch (DomainException $e) {
    $archiveConflict = $e->getCode() === 409;
}
assertAdmin($archiveConflict, 'архивное состояние не защищено');

$restored = $admin->restore(1, 3, 33, 'Восстановление для работы', 'restore_1234567890ABC');
assertAdmin($restored['version'] === 4
    && (int) $db->fetchValue('SELECT is_archived FROM defects WHERE id=1') === 0,
    'восстановление не сработало');
assertAdmin(count($report->search(['status' => 'all'])['items']) === 2, 'восстановленная запись не найдена');

$fixedClosed = $admin->correct(2, 1, 31, [
    'completed_work' => 'Полный ремонт',
    'closed_at' => '2026-01-11T15:00:00+03:00',
], 'Уточнение по итоговому акту', 'correct_1234567890MNO');
assertAdmin($fixedClosed['version'] === 2
    && $db->fetchValue('SELECT completed_work FROM defects WHERE id=2') === 'Полный ремонт',
    'закрытые работы не исправлены');
assertAdmin((int) $db->fetchValue('SELECT closed_by_user_id FROM defects WHERE id=2') === 22,
    'исходная учётная запись закрывшего была изменена');

$reopened = $admin->reopen(2, 2, 31, 'Закрытие ошибочно, устранение не подтверждено', 'reopen_1234567890ABC');
assertAdmin($reopened['version'] === 3 && $reopened['changed'], 'карточка не возвращена в работу');
$reopenedRow = $db->fetchOne('SELECT * FROM defects WHERE id=2');
assertAdmin($reopenedRow['status'] === 'open' && $reopenedRow['closed_at'] === null
    && $reopenedRow['completed_work'] === null && $reopenedRow['closed_by_user_id'] === null,
    'у вновь открытого дефекта остались признаки закрытия');
assertAdmin($reopenedRow['registered_by_user_id'] == 17, 'исходный автор регистрации изменён');
$replayedReopen = $admin->reopen(2, 2, 31, 'Закрытие ошибочно, устранение не подтверждено', 'reopen_1234567890ABC');
assertAdmin($replayedReopen['replayed'] && $replayedReopen['version'] === 3, 'повтор открытия не распознан');
$staleReopen = false;
try {
    $admin->reopen(2, 2, 31, 'Повторная отмена закрытия', 'reopen_1234567890DEF');
} catch (DomainException $error) {
    $staleReopen = $error->getCode() === 409;
}
assertAdmin($staleReopen, 'устаревшая версия вернула дефект в работу');
$wrongState = false;
try {
    $admin->reopen(2, 3, 31, 'Повторная отмена закрытия', 'reopen_1234567890GHI');
} catch (DomainException $error) {
    $wrongState = $error->getCode() === 409;
}
assertAdmin($wrongState, 'повторно открывать активный дефект нельзя');
$reopenHistory = (new DefectHistoryService($db))->list('defect', 2);
assertAdmin(count($reopenHistory['events']) === 2
    && $reopenHistory['events'][1]['event_type'] === 'reopened'
    && $reopenHistory['events'][1]['actor_user_id'] === 31
    && $reopenHistory['events'][1]['details']['changes']['closed_by_user_id']['before'] == 22,
    'потеряно доказательство отменённого закрытия');


$pdo->exec("CREATE TRIGGER reject_archive_history BEFORE INSERT ON defect_history
    WHEN NEW.command_id='archive_FAIL_1234567890'
    BEGIN SELECT RAISE(ABORT, 'Отказ журнала'); END");
$failed = false;
try {
    $admin->archive(1, 4, 31, 'Проверка атомарного отката', 'archive_FAIL_1234567890');
} catch (PDOException) {
    $failed = true;
}
assertAdmin($failed, 'отказ истории не воспроизведён');
assertAdmin((int) $db->fetchValue('SELECT is_archived FROM defects WHERE id=1') === 0
    && (int) $db->fetchValue('SELECT version FROM defects WHERE id=1') === 4,
    'операция не откатилась');
$history = (new DefectHistoryService($db))->list('defect', 1);
assertAdmin(count($history['events']) === 3
    && $history['events'][0]['actor_user_id'] === 31
    && $history['events'][1]['event_type'] === 'archived'
    && $history['events'][2]['event_type'] === 'restored',
    'история исправления и архива неполна');

echo 'Административные правки, архив, восстановление, версии и атомарность проверены' . PHP_EOL;
