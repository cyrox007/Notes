<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/modules/defects/services/DefectHistoryService.php';
require_once $root . '/modules/defects/services/DefectReferenceService.php';
require_once $root . '/modules/defects/services/DefectReferenceImportService.php';

function assertDefectImport(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, 'Импорт справочников Дефектов: ' . $message . PHP_EOL);
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
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE defect_areas (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL UNIQUE,is_archived INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE defect_object_categories (id INTEGER PRIMARY KEY AUTOINCREMENT,area_id INTEGER NOT NULL REFERENCES defect_areas(id),name TEXT NOT NULL,is_archived INTEGER DEFAULT 0,UNIQUE(area_id,name))');
$pdo->exec('CREATE TABLE defect_objects (id INTEGER PRIMARY KEY AUTOINCREMENT,category_id INTEGER NOT NULL REFERENCES defect_object_categories(id),name TEXT NOT NULL,is_archived INTEGER DEFAULT 0,UNIQUE(category_id,name))');
$pdo->exec('CREATE TABLE defect_services (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL UNIQUE,is_archived INTEGER DEFAULT 0)');

$db = \Core\DatabaseManager::forConnection($pdo);
$import = new \Modules\Defects\Services\DefectReferenceImportService($db);
$file = "object;Северный РЭС;Подстанции;ТП-17\n"
    . "category;Северный РЭС;Подстанции\n"
    . "service;Ремонтная служба\n"
    . "area;Северный РЭС\n";

$preview = $import->preview($file);
assertDefectImport($preview['total'] === 4 && $preview['errors'] === [], 'предпросмотр не принял корректный файл');
assertDefectImport(strlen($preview['hash']) === 64, 'отсутствует отпечаток');
$created = $import->apply($file, $preview['hash']);
assertDefectImport($created === ['created' => 4, 'existing' => 0, 'total' => 4], 'вложенные справочники не созданы');
assertDefectImport((int) $db->fetchValue('SELECT COUNT(*) FROM defect_objects') === 1, 'объект не создан');
assertDefectImport((int) $db->fetchValue('SELECT COUNT(*) FROM defect_history') === 4, 'созданные справочники не отражены в истории');
$replay = $import->apply($file, $preview['hash']);
assertDefectImport($replay === ['created' => 0, 'existing' => 4, 'total' => 4], 'повтор импорта создал дубликаты');
assertDefectImport((int) $db->fetchValue('SELECT COUNT(*) FROM defect_history') === 4, 'повтор импорта создал повтор истории');

$changed = false;
try {
    $import->apply($file . '#изменение', $preview['hash']);
} catch (InvalidArgumentException) {
    $changed = true;
}
assertDefectImport($changed, 'изменённый после просмотра файл должен отклоняться');

$invalid = $import->preview("object;Несуществующий РЭС;Категория;ТП-99\n");
assertDefectImport($invalid['errors'] !== [], 'предпросмотр не обнаружил отсутствующего родителя');
$duplicate = $import->preview("area;РЭС\narea;РЭС\n");
assertDefectImport($duplicate['errors'] !== [], 'дубликат внутри файла принят');

$pdo->exec("CREATE TRIGGER fail_new_object BEFORE INSERT ON defect_objects
    WHEN NEW.name='Аварийный объект' BEGIN SELECT RAISE(ABORT,'Искусственный сбой'); END");
$failFile = "area;Западный РЭС\ncategory;Западный РЭС;Линии\n"
    . "object;Западный РЭС;Линии;Аварийный объект\n";
$previewFail = $import->preview($failFile);
assertDefectImport($previewFail['errors'] === [], 'предпросмотр отклонил допустимые справочники');
$failed = false;
try {
    $import->apply($failFile, $previewFail['hash']);
} catch (PDOException) {
    $failed = true;
}
assertDefectImport($failed, 'ошибка БД при импорте потеряна');
assertDefectImport($db->fetchValue('SELECT id FROM defect_areas WHERE name=:name', ['name' => 'Западный РЭС']) === null,
    'транзакционный откат должен убрать частично импортированные записи');
assertDefectImport(!$db->inTransaction(), 'после сбоя осталась транзакция');
assertDefectImport((int) $db->fetchValue('SELECT COUNT(*) FROM defect_history') === 4, 'неудачный импорт оставил историю');

echo 'Предпросмотр, связи, повтор, целостность и откат импорта проверены' . PHP_EOL;
