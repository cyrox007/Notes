<?php

declare(strict_types=1);

use Core\DatabaseManager;
use Modules\Defects\Services\DefectPriorityPolicy;
use Modules\Defects\Services\DefectRegistrationService;

$root = dirname(__DIR__, 2);
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/development/defects/DefectPriorityPolicy.php';
require_once $root . '/development/defects/DefectRegistrationService.php';

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
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE defect_areas (id INTEGER PRIMARY KEY, is_archived INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE defect_object_categories (id INTEGER PRIMARY KEY, area_id INTEGER NOT NULL, is_archived INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE defect_objects (id INTEGER PRIMARY KEY, category_id INTEGER NOT NULL, is_archived INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE defect_services (id INTEGER PRIMARY KEY, is_archived INTEGER NOT NULL DEFAULT 0)');
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
    version INTEGER NOT NULL DEFAULT 1
)');
$pdo->exec('CREATE TABLE defect_responsible_services (
    defect_id INTEGER NOT NULL REFERENCES defects(id),
    service_id INTEGER NOT NULL REFERENCES defect_services(id),
    PRIMARY KEY (defect_id, service_id)
)');
$pdo->exec('INSERT INTO defect_areas (id) VALUES (1)');
$pdo->exec('INSERT INTO defect_object_categories (id,area_id) VALUES (2,1)');
$pdo->exec('INSERT INTO defect_objects (id,category_id) VALUES (3,2)');
$pdo->exec('INSERT INTO defect_services (id) VALUES (4),(5)');

$database = DatabaseManager::forConnection($pdo);
$registrar = new DefectRegistrationService($database);
$detected = new DateTimeImmutable('2026-10-19 12:30:00', new DateTimeZone('Europe/Moscow'));
$register = static fn (string $command, array $services = [4, 5], string $description = 'Повреждение изоляции'):
    array => $registrar->register(3, $description, DefectPriorityPolicy::FIRST, $detected, 7, 'Иванов И. И.', $services, $command);

$first = $register('command_1234567890AB');
defectsAssert($first['id'] === 1 && $first['replayed'] === false, 'карточка должна создаваться один раз');
defectsAssert($first['due_at'] === '2026-10-26 09:30:00', 'срок должен сохраняться в UTC');
defectsAssert((int) $database->fetchValue('SELECT COUNT(*) FROM defect_responsible_services') === 2, 'сохранены не все службы');

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

echo 'Регистрация дефектов: запись, идемпотентность, проверка ссылок и откат проверены' . PHP_EOL;
