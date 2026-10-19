<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/DatabaseManager.php';
require_once dirname(__DIR__, 2) . '/modules/defects/services/DefectHistoryService.php';
require_once dirname(__DIR__, 2) . '/modules/defects/services/DefectReferenceLifecycleService.php';

use Core\DatabaseManager;
use Modules\Defects\Services\DefectHistoryService;
use Modules\Defects\Services\DefectReferenceLifecycleService;

function checkDefectReference(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'Управление справочниками Дефектов: ' . $message . PHP_EOL);
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE defect_areas (
    id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, is_archived INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1
)');
$pdo->exec('CREATE TABLE defect_object_categories (
    id INTEGER PRIMARY KEY, area_id INTEGER NOT NULL REFERENCES defect_areas(id),
    name TEXT NOT NULL, is_archived INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1, UNIQUE(area_id,name)
)');
$pdo->exec('CREATE TABLE defect_objects (
    id INTEGER PRIMARY KEY, category_id INTEGER NOT NULL REFERENCES defect_object_categories(id),
    name TEXT NOT NULL, is_archived INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1, UNIQUE(category_id,name)
)');
$pdo->exec('CREATE TABLE defect_services (
    id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, is_archived INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1
)');
$pdo->exec('CREATE TABLE defects (id INTEGER PRIMARY KEY, object_id INTEGER NOT NULL,
    status TEXT NOT NULL)');
$pdo->exec('CREATE TABLE defect_responsible_services (
    defect_id INTEGER NOT NULL, service_id INTEGER NOT NULL, PRIMARY KEY(defect_id,service_id)
)');
$pdo->exec('CREATE TABLE defect_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,entity_type TEXT NOT NULL,entity_id INTEGER NOT NULL,
    event_type TEXT NOT NULL,actor_user_id INTEGER,command_id TEXT,payload_json TEXT NOT NULL,
    created_at TEXT NOT NULL
)');
$pdo->exec("INSERT INTO defect_areas(id,name) VALUES (1,'Северный РЭС'),(2,'Резервный РЭС')");
$pdo->exec("INSERT INTO defect_object_categories(id,area_id,name) VALUES (1,1,'Подстанция')");
$pdo->exec("INSERT INTO defect_objects(id,category_id,name) VALUES (1,1,'ТП-1')");
$pdo->exec("INSERT INTO defect_services(id,name) VALUES (1,'Ремонтная служба')");
$pdo->exec("INSERT INTO defects(id,object_id,status) VALUES (1,1,'open')");
$pdo->exec('INSERT INTO defect_responsible_services(defect_id,service_id) VALUES (1,1)');

$db = DatabaseManager::forConnection($pdo);
$service = new DefectReferenceLifecycleService($db);
$history = new DefectHistoryService($db);

$renamed = $service->rename('areas', 1, 1, 7, 'Центральный РЭС');
checkDefectReference($renamed === ['id' => 1, 'version' => 2, 'changed' => true], 'переименование или версия неверны');
checkDefectReference($service->rename('areas', 1, 2, 7, 'Центральный РЭС')['changed'] === false, 'неизменённое имя должно быть безопасным');
$events = $history->list('area', 1);
checkDefectReference(count($events['events']) === 1 && $events['events'][0]['event_type'] === 'updated', 'история переименования отсутствует');
checkDefectReference($events['events'][0]['actor_user_id'] === 7
    && $events['events'][0]['details']['name_before'] === 'Северный РЭС', 'история потеряла автора или прежнее значение');

$stale = false;
try {
    $service->rename('areas', 1, 1, 8, 'Несогласованное название');
} catch (DomainException $exception) {
    $stale = $exception->getCode() === 409;
}
checkDefectReference($stale, 'устаревшая версия не отклонена');
checkDefectReference($service->list('areas')['items'][0]['version'] === 2, 'список не отражает текущую версию');

$dependent = static function (callable $run): bool {
    try { $run(); }
    catch (DomainException $exception) { return $exception->getCode() === 409; }
    return false;
};
checkDefectReference($dependent(fn () => $service->archive('areas', 1, 2, 7)), 'участок с активной категорией ушёл в архив');
checkDefectReference($dependent(fn () => $service->archive('categories', 1, 1, 7)), 'категория с активным объектом ушла в архив');
checkDefectReference($dependent(fn () => $service->archive('objects', 1, 1, 7)), 'объект с открытым дефектом ушёл в архив');
checkDefectReference($dependent(fn () => $service->archive('services', 1, 1, 7)), 'служба с открытым дефектом ушла в архив');
checkDefectReference((int) $db->fetchValue('SELECT COUNT(*) FROM defect_history') === 1, 'неудачные операции создали события');

$pdo->exec("UPDATE defects SET status='closed' WHERE id=1");
$archivedService = $service->archive('services', 1, 1, 7);
checkDefectReference($archivedService['version'] === 2, 'служба не архивирована');
checkDefectReference(count($service->list('services')['items']) === 0, 'архивная служба видна в активном списке');
checkDefectReference($service->list('services', 20, 0, true)['items'][0]['is_archived'] === true, 'архивная служба не найдена');
$service->restore('services', 1, 2, 7);
checkDefectReference((int) $db->fetchValue('SELECT is_archived FROM defect_services WHERE id=1') === 0, 'служба не восстановлена');

$service->archive('objects', 1, 1, 7);
$service->archive('categories', 1, 1, 7);
$service->archive('areas', 1, 2, 7);
checkDefectReference($dependent(fn () => $service->restore('categories', 1, 2, 7)), 'категория восстановлена внутри архивного участка');
$service->restore('areas', 1, 3, 7);
$service->restore('categories', 1, 2, 7);
$service->restore('objects', 1, 2, 7);
checkDefectReference((int) $db->fetchValue('SELECT is_archived FROM defect_objects WHERE id=1') === 0, 'объект не восстановлен');

$pdo->exec("CREATE TRIGGER reject_history BEFORE INSERT ON defect_history
    WHEN NEW.event_type='updated' BEGIN SELECT RAISE(ABORT,'Искусственная ошибка истории'); END");
$rollback = false;
try {
    $service->rename('areas', 1, 4, 7, 'Ошибочное название');
} catch (PDOException) {
    $rollback = true;
}
checkDefectReference($rollback, 'сбой истории не передан вызывающему');
checkDefectReference((string) $db->fetchValue('SELECT name FROM defect_areas WHERE id=1') === 'Центральный РЭС',
    'имя поменялось вопреки транзакционному откату');
checkDefectReference((int) $db->fetchValue('SELECT version FROM defect_areas WHERE id=1') === 4,
    'сбой истории изменил версию');
checkDefectReference(!$db->inTransaction(), 'транзакция осталась открытой после ошибки');

$bad = false;
try { $service->rename('areas', 1, 4, 7, '   '); }
catch (InvalidArgumentException) { $bad = true; }
checkDefectReference($bad, 'пустое имя принято');
$unknown = false;
try { $service->list('another_table'); }
catch (InvalidArgumentException) { $unknown = true; }
checkDefectReference($unknown, 'произвольная таблица принята');

echo 'История, версии, архив, восстановление и транзакционные откаты справочников проверены' . PHP_EOL;
