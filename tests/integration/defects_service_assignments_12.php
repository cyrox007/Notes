<?php

declare(strict_types=1);

use Core\DatabaseManager;
use Modules\Defects\Services\DefectHistoryService;
use Modules\Defects\Services\DefectServiceAssignmentService;

$root = dirname(__DIR__, 2);
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/modules/defects/services/DefectHistoryService.php';
require_once $root . '/modules/defects/services/DefectServiceAssignmentService.php';

function checkAssignments(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, 'Назначения исполнителей: ' . $message . PHP_EOL);
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec("CREATE TABLE defects (
    id INTEGER PRIMARY KEY,version INTEGER NOT NULL,status TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE defect_services (
    id INTEGER PRIMARY KEY,name TEXT NOT NULL,is_archived INTEGER NOT NULL DEFAULT 0
)");
$pdo->exec("CREATE TABLE defect_responsible_services (
    defect_id INTEGER NOT NULL REFERENCES defects(id),
    service_id INTEGER NOT NULL REFERENCES defect_services(id),
    executor_name TEXT DEFAULT NULL,
    PRIMARY KEY(defect_id,service_id)
)");
$pdo->exec("CREATE TABLE defect_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type TEXT NOT NULL,entity_id INTEGER NOT NULL,event_type TEXT NOT NULL,
    actor_user_id INTEGER,command_id TEXT,payload_json TEXT NOT NULL,created_at TEXT NOT NULL,
    UNIQUE(entity_type,entity_id,event_type,command_id)
)");
$pdo->exec("INSERT INTO defects VALUES (7,1,'open'),(8,1,'closed')");
$pdo->exec("INSERT INTO defect_services VALUES
    (11,'Дежурная служба',0),(12,'Ремонтная служба',0),(13,'Архивная служба',1)");
$pdo->exec("INSERT INTO defect_responsible_services VALUES
    (7,11,NULL),(7,12,NULL)");
$pdo->exec('ALTER TABLE defects ADD COLUMN is_archived INTEGER NOT NULL DEFAULT 0');
$db = DatabaseManager::forConnection($pdo);
$service = new DefectServiceAssignmentService($db);
$initial = [
    ['service_id' => 12, 'executor_name' => '  Петров П. П.  '],
    ['service_id' => 11, 'executor_name' => ''],
];
$command = 'assign_1234567890AB';
$result = $service->replace(7, 1, 21, $initial, $command);
checkAssignments($result['version'] === 2 && !$result['replayed'], 'версия карточки не увеличилась');
$rows = $db->fetchAll('SELECT service_id,executor_name FROM defect_responsible_services WHERE defect_id=7 ORDER BY service_id');
checkAssignments(count($rows) === 2 && $rows[0]['executor_name'] === null
    && $rows[1]['executor_name'] === 'Петров П. П.', 'состав служб и ФИО не сохранены');
$replayed = $service->replace(7, 1, 21, array_reverse($initial), $command);
checkAssignments($replayed['replayed'] && $replayed['version'] === 2, 'безопасный повтор команды не сработал');
checkAssignments((int) $db->fetchValue('SELECT COUNT(*) FROM defect_history') === 1, 'повтор создал лишнюю историю');

$badReplay = false;
try {
    $service->replace(7, 1, 21, [['service_id' => 11, 'executor_name' => 'Другой']], $command);
} catch (DomainException $error) {
    $badReplay = $error->getCode() === 409;
}
checkAssignments($badReplay, 'повтор команды с другими данными не отклонён');

$stale = false;
try {
    $service->replace(7, 1, 22, [['service_id' => 11, 'executor_name' => 'Сидоров']], 'assign_1234567890CD');
} catch (DomainException $error) {
    $stale = $error->getCode() === 409;
}
checkAssignments($stale, 'потеряно обнаружение конфликта версий');

$closed = false;
try {
    $service->replace(8, 1, 22, [['service_id' => 11, 'executor_name' => 'Сидоров']], 'assign_1234567890EF');
} catch (DomainException $error) {
    $closed = $error->getCode() === 409;
}
checkAssignments($closed, 'разрешено изменение закрытой карточки');

$archived = false;
try {
    $service->replace(7, 2, 22, [['service_id' => 13, 'executor_name' => null]], 'assign_1234567890GH');
} catch (InvalidArgumentException) {
    $archived = true;
}
checkAssignments($archived, 'архивированная служба была назначена');

$duplicate = false;
try {
    $service->replace(7, 2, 22, [
        ['service_id' => 11, 'executor_name' => null],
        ['service_id' => 11, 'executor_name' => 'Дубль'],
    ], 'assign_1234567890IJ');
} catch (InvalidArgumentException) {
    $duplicate = true;
}
checkAssignments($duplicate, 'повтор службы не отклонён');

$pdo->exec("CREATE TRIGGER reject_service_history BEFORE INSERT ON defect_history
    WHEN NEW.command_id='assign_HISTORY_FAIL_001'
    BEGIN SELECT RAISE(ABORT, 'Тестовый отказ истории'); END");
$rollback = false;
try {
    $service->replace(7, 2, 22, [
        ['service_id' => 11, 'executor_name' => 'Новый исполнитель'],
    ], 'assign_HISTORY_FAIL_001');
} catch (PDOException) {
    $rollback = true;
}
checkAssignments($rollback, 'не удалось воспроизвести отказ истории');
checkAssignments((int) $db->fetchValue('SELECT version FROM defects WHERE id=7') === 2, 'версия не откатилась после ошибки');
$rows = $db->fetchAll('SELECT service_id,executor_name FROM defect_responsible_services WHERE defect_id=7 ORDER BY service_id');
checkAssignments(count($rows) === 2 && $rows[1]['executor_name'] === 'Петров П. П.',
    'при ошибке истории назначения изменились');
checkAssignments((int) $db->fetchValue('SELECT COUNT(*) FROM defect_history') === 1,
    'доказательная история нарушена после отката');

$final = $service->replace(7, 2, 22, [
    ['service_id' => 12, 'executor_name' => 'Сидоров С. С.'],
], 'assign_1234567890KL');
checkAssignments($final['version'] === 3, 'переназначение после конфликта не применено');
$history = (new DefectHistoryService($db))->list('defect', 7);
checkAssignments(count($history['events']) === 2, 'история двух назначений неполна');
checkAssignments($history['events'][1]['actor_user_id'] === 22
    && $history['events'][1]['details']['version_before'] === 2,
    'отсутствует автор или версия переназначения');
checkAssignments((int) $db->fetchValue('SELECT COUNT(*) FROM defect_responsible_services WHERE defect_id=7') === 1,
    'удалённая из карточки служба осталась');

echo 'Назначения служб, версии, повтор, архив, журнал и откат проверены' . PHP_EOL;
