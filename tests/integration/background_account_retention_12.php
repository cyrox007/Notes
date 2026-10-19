<?php

declare(strict_types=1);

// Фоновая проверка не запускает пользовательский runtime и работает даже
// при disabled/unlicensed. Все данные временные, внешняя БД не используется.
$root = dirname(__DIR__, 2);
foreach ([
    '/core/Version.php',
    '/core/DatabaseManager.php',
    '/core/ModuleManifest.php',
    '/core/ModuleRegistry.php',
    '/core/BackgroundAccountRetentionGuard.php',
    '/core/BackgroundAccountRetention.php',
] as $file) {
    require_once $root . $file;
}

use Core\BackgroundAccountRetention;
use Core\DatabaseManager;
use Core\ModuleManifest;
use Core\ModuleRegistry;
use Core\Version;

function retentionGuardAssert(bool $valid, string $message): void
{
    if (!$valid) {
        throw new RuntimeException($message);
    }
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('Требуется расширение pdo_sqlite для проверки фоновых операций');
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE module_lifecycle (module_id TEXT,configured_state TEXT,effective_state TEXT)');
$pdo->exec("INSERT INTO module_lifecycle VALUES
    ('messenger','disabled','disabled'),
    ('tasks','enabled','unlicensed')");
$pdo->exec('CREATE TABLE dialogs (id INTEGER PRIMARY KEY,type TEXT,name TEXT)');
$pdo->exec('CREATE TABLE user_to_dialogs (dialog_id INT,user_id INT,role TEXT,is_deleted INT)');
$pdo->exec('CREATE TABLE task_boards (id INTEGER PRIMARY KEY,owner_user_id INT,name TEXT,audience TEXT)');
$pdo->exec('CREATE TABLE task_board_members (board_id INT,user_id INT)');
$db = DatabaseManager::forConnection($pdo);
$checker = new BackgroundAccountRetention($db, $root . '/modules');
$retentionModules = ModuleRegistry::discover($root . '/modules', Version::VERSION);
retentionGuardAssert(
    $retentionModules->get('messenger')->backgroundAccountGuard() === 'background/account-purge.php'
    && $retentionModules->get('tasks')->backgroundAccountGuard() === 'background/account-purge.php',
    'Объявления фоновых проверок не совпадают с модулем-владельцем'
);
retentionGuardAssert(
    $retentionModules->get('notes')->backgroundAccountGuard() === 'none',
    'Модули без ограничений должны явно подтверждать это в manifest'
);

retentionGuardAssert($checker->blockingReason(17) === null,
    'Отключённый Messenger и нелицензированный Tasks без чужих объектов не должны препятствовать очистке');

$pdo->exec("INSERT INTO dialogs VALUES (8,'group','Общая группа')");
$pdo->exec("INSERT INTO user_to_dialogs VALUES (8,17,'owner',0)");
retentionGuardAssert($checker->blockingReason(17) === 'owned_shared_messenger_group',
    'Владельца общей группы должен блокировать даже выключенный Messenger');
$pdo->exec('UPDATE user_to_dialogs SET is_deleted=1 WHERE dialog_id=8');

$pdo->exec("INSERT INTO task_boards VALUES (5,17,'Общая доска','all_active')");
retentionGuardAssert($checker->blockingReason(17) === 'owned_shared_task_board',
    'Владельца общей доски должен блокировать даже нелицензированный Tasks');
$pdo->exec('DELETE FROM task_boards');
retentionGuardAssert($checker->blockingReason(17) === null,
    'После передачи или удаления общих объектов очистка снова разрешена');

$pdo->exec('DROP TABLE task_boards');
retentionGuardAssert($checker->blockingReason(17) === 'account_guard_unavailable:tasks',
    'Ошибка недоступной схемы Tasks обязана закрывать операцию с отказом');
$pdo->exec('CREATE TABLE task_boards (id INTEGER PRIMARY KEY,owner_user_id INT,name TEXT,audience TEXT)');

$pdo->exec("INSERT INTO module_lifecycle VALUES ('missing-package','disabled','disabled')");
$missing = new BackgroundAccountRetention($db, $root . '/modules');
retentionGuardAssert($missing->blockingReason(17) === 'account_guard_registry_unavailable',
    'Отсутствующий пакет с сохранившимся состоянием не даёт согласия на очистку');
$pdo->exec("DELETE FROM module_lifecycle WHERE module_id='missing-package'");

$tmp = sys_get_temp_dir() . '/notes-retention-contract-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);
mkdir($tmp . '/tasks', 0700, true);
$manifest = json_decode((string) file_get_contents($root . '/modules/tasks/module.json'), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($tmp . '/tasks/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));
try {
    $notInstalled = new BackgroundAccountRetention($db, $tmp);
    retentionGuardAssert($notInstalled->blockingReason(17) === 'account_guard_registry_unavailable',
        'Отсутствующий файл фонового контракта блокирует удаление');
    unset($manifest['background']);
    file_put_contents($tmp . '/tasks/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $noPolicy = new BackgroundAccountRetention($db, $tmp);
    retentionGuardAssert($noPolicy->blockingReason(17) === 'account_guard_registry_unavailable',
        'Модуль без объявленной фоновой политики не может подтвердить безопасность');
} finally {
    unlink($tmp . '/tasks/module.json');
    rmdir($tmp . '/tasks');
    rmdir($tmp);
}

retentionGuardAssert(!class_exists(\Modules\Messenger\MessengerRuntimeProvider::class, false),
    'Фоновая проверка загрузила пользовательский Messenger runtime');
retentionGuardAssert(!class_exists(\Modules\Tasks\TasksRuntimeProvider::class, false),
    'Фоновая проверка загрузила пользовательский Tasks runtime');

echo "Контракт безопасной фоновой проверки владения модулями выполнен\n";
