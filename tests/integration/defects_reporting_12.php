<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/modules/defects/services/DefectPriorityPolicy.php';
require_once $root . '/modules/defects/services/DefectReportService.php';

function assertDefectReport(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, 'Отчёты Дефектов: ' . $message . PHP_EOL);
    exit(1);
}

final class DefectReportCountingConnection extends PDO
{
    public int $selectStatements = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (preg_match('/^\s*SELECT\b/i', $query) === 1) {
            $this->selectStatements++;
        }
        return parent::prepare($query, $options);
    }
}

$pdo = new DefectReportCountingConnection('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE defect_areas (id INTEGER PRIMARY KEY,name TEXT)');
$pdo->exec('CREATE TABLE defect_object_categories (id INTEGER PRIMARY KEY,area_id INTEGER,name TEXT)');
$pdo->exec('CREATE TABLE defect_objects (id INTEGER PRIMARY KEY,category_id INTEGER,name TEXT)');
$pdo->exec('CREATE TABLE defects (id INTEGER PRIMARY KEY,object_id INTEGER,description TEXT,
    priority TEXT,discovered_at TEXT,due_at TEXT,registered_by_name TEXT,status TEXT,version INTEGER,closed_at TEXT,created_at TEXT)');
$pdo->exec('CREATE TABLE defect_responsible_services (defect_id INTEGER,service_id INTEGER)');
$pdo->exec('CREATE TABLE defect_services (id INTEGER PRIMARY KEY,name TEXT)');
$pdo->exec("INSERT INTO defect_areas VALUES (1,'Северный РЭС'),(2,'Южный РЭС')");
$pdo->exec("INSERT INTO defect_object_categories VALUES (1,1,'Линии'),(2,2,'Подстанции')");
$pdo->exec("INSERT INTO defect_objects VALUES (1,1,'ЛЭП-1'),(2,2,'ТП-2')");
$pdo->exec("INSERT INTO defects VALUES
    (1,1,'Повреждение','urgent','2020-01-01 12:00:00','2020-01-02 12:00:00','Иванов','open',1,NULL,'2020-01-03 10:00:00'),
    (2,1,'Замена','normal','2020-02-01 12:00:00','2020-03-02 12:00:00','Петров','closed',2,'2020-02-04 12:00:00','2020-02-03 10:00:00'),
    (3,2,'Проверка','first','2020-02-02 12:00:00','2020-02-09 12:00:00','Сидоров','open',1,NULL,'2020-02-05 10:00:00')");
$pdo->exec('INSERT INTO defect_responsible_services VALUES (1,10),(2,10),(3,20)');
$pdo->exec("INSERT INTO defect_services VALUES (10,'Электросеть'),(20,'Ремонтная служба')");
$pdo->exec('ALTER TABLE defects ADD COLUMN is_archived INTEGER NOT NULL DEFAULT 0');
$service = new \Modules\Defects\Services\DefectReportService(
    \Core\DatabaseManager::forConnection($pdo)
);

$result = $service->search(['status' => 'all'], 2);
assertDefectReport(count($result['items']) === 2 && $result['next_cursor'] === 2, 'страница реестра неверна');
assertDefectReport(count($service->search(['status' => 'all'], 2, 2)['items']) === 1, 'курсор пропустил запись');
assertDefectReport(count($service->search(['status' => 'open'])['items']) === 2, 'фильтр открытых не работает');
assertDefectReport(count($service->search(['status' => 'all', 'service_id' => 10])['items']) === 2, 'фильтр служб не работает');
$firstSorted = $service->search(['status' => 'all', 'sort' => 'due_desc'], 2);
assertDefectReport(array_column($firstSorted['items'], 'id') === [2, 3], 'сортировка по сроку неверна');
assertDefectReport($firstSorted['next_cursor'] === 2, 'неверный курсор сортировки');
$secondSorted = $service->search(['status' => 'all', 'sort' => 'due_desc'], 2, 2);
assertDefectReport(array_column($secondSorted['items'], 'id') === [1], 'вторая страница сортировки неверна');
assertDefectReport(($firstSorted['items'][0]['service_names'] ?? '') === 'Электросеть', 'ответственные службы отсутствуют в таблице');
assertDefectReport(array_column($service->search(['status' => 'all', 'sort' => 'id_desc'])['items'], 'id') === [3, 2, 1], 'обратная сортировка по номеру неверна');
assertDefectReport(count($service->search(['status' => 'all', 'priority' => 'urgent'])['items']) === 1, 'фильтр приоритета не работает');
assertDefectReport(count($service->search(['status' => 'all', 'area_id' => 2])['items']) === 1, 'фильтр РЭС не работает');
assertDefectReport(count($service->search(['status' => 'all', 'object_id' => 1])['items']) === 2, 'фильтр объекта не работает');
assertDefectReport(count($service->search(['status' => 'all', 'from' => '2020-02-01', 'to' => '2020-02-01'])['items']) === 1,
    'границы периода неверны');
assertDefectReport(count($service->search(['status'=>'all',
    'registered_from'=>'2020-02-03','registered_to'=>'2020-02-03'])['items']) === 1,
    'даты регистрации должны фильтроваться отдельно от обнаружения');
assertDefectReport(count($service->search(['status'=>'all',
    'due_from'=>'2020-02-09','due_to'=>'2020-02-09'])['items']) === 1,
    'фильтр планового срока не должен использовать дату обнаружения');
assertDefectReport(count($service->search(['status'=>'all',
    'from'=>'2020-02-01','to'=>'2020-02-01',
    'registered_from'=>'2020-02-03','registered_to'=>'2020-02-03'])['items']) === 1,
    'одновременные независимые фильтры должны работать вместе');

$readsBefore = $pdo->selectStatements;
$summary = $service->summary(['status' => 'all'], 0, 1);
assertDefectReport($pdo->selectStatements - $readsBefore === 2,
    'сводка должна выполнять только две выборки БД через ядро');
assertDefectReport($summary['total'] === 3 && $summary['overdue'] === 2, 'общая сводка неверна');
assertDefectReport(count($summary['by_priority']) === 3, 'сводка приоритетов неполна');
assertDefectReport(count($summary['by_area']) === 1 && $summary['next_area_cursor'] === 1, 'пагинация РЭС неверна');
$next = $service->summary(['status' => 'all'], $summary['next_area_cursor'], 1);
assertDefectReport(count($next['by_area']) === 1 && $next['next_area_cursor'] === null, 'вторая страница РЭС неверна');

$empty = $service->summary(['status' => 'open', 'object_id' => 999]);
assertDefectReport($empty['total'] === 0 && $empty['overdue'] === 0
    && $empty['by_priority'] === [] && $empty['by_area'] === [],
    'пустая сводка должна возвращать нулевые итоги');

foreach ([
    ['sort' => 'd.id; DROP TABLE defects'],
    ['status' => 'unknown'],
    ['priority' => 'invalid'],
    ['area_id' => '-1'],
    ['from' => '2020-02-30'],
    ['from' => '2020-02-02', 'to' => '2020-02-01'],
    ['registered_from' => '2020-02-30'],
    ['due_from' => '2020-02-10', 'due_to' => '2020-02-01'],
] as $bad) {
    $rejected = false;
    try {
        $service->search($bad);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    assertDefectReport($rejected, 'неверный фильтр принят');
}

echo 'Поиск, периоды, приоритеты, службы, сводки и пагинация Дефектов проверены' . PHP_EOL;
