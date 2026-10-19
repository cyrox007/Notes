<?php

declare(strict_types=1);

// Фоновая очистка использует владельцев данных без runtime,
// в том числе когда модуль отключён или лицензия не действует.
$root = dirname(__DIR__, 2);
foreach ([
    '/core/Version.php',
    '/core/DatabaseManager.php',
    '/core/ModuleManifest.php',
    '/core/ModuleRegistry.php',
    '/core/ModuleAccountDataCleanup.php',
    '/core/ModuleRetentionCleanup.php',
    '/core/RetentionFileCleanup.php',
    '/core/RetentionCleanupContext.php',
    '/core/BackgroundRetentionCleanup.php',
] as $file) {
    require_once $root . $file;
}

use Core\BackgroundRetentionCleanup;
use Core\DatabaseManager;
use Core\ModuleRegistry;
use Core\Version;

function verifyRetention(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('Для теста требуется pdo_sqlite');
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE module_lifecycle (module_id TEXT, configured_state TEXT, effective_state TEXT)');
$pdo->exec("INSERT INTO module_lifecycle VALUES
    ('notes','disabled','disabled'),
    ('tasks','enabled','unlicensed'),
    ('files','enabled','enabled'),
    ('messenger','enabled','enabled')");
$db = DatabaseManager::forConnection($pdo);
$registry = ModuleRegistry::discover($root . '/modules', Version::VERSION);
foreach (['notes', 'files', 'messenger', 'tasks'] as $module) {
    verifyRetention(
        $registry->get($module)->backgroundRetentionCleanup() === 'background/retention.php',
        'Нет отдельного фонового поставщика у модуля ' . $module
    );
}

$loader = new BackgroundRetentionCleanup($db, $root . '/modules');
$loader->verify();
verifyRetention(!class_exists(\Modules\Notes\NotesRuntimeProvider::class, false),
    'Фоновая очистка недопустимо загрузила Notes runtime');
verifyRetention(!class_exists(\Modules\Messenger\MessengerRuntimeProvider::class, false),
    'Фоновая очистка недопустимо загрузила Messenger runtime');

$pdo->exec("INSERT INTO module_lifecycle VALUES ('missing-installed','disabled','disabled')");
try {
    (new BackgroundRetentionCleanup($db, $root . '/modules'))->verify();
    throw new RuntimeException('Фоновая очистка разрешена при отсутствующем установленном модуле');
} catch (RuntimeException $error) {
    verifyRetention(str_contains($error->getMessage(), 'Отсутствует ранее установленный модуль'),
        'Неожиданная ошибка при проверке отсутствующего модуля');
}
$pdo->exec("DELETE FROM module_lifecycle WHERE module_id='missing-installed'");

$tmp = sys_get_temp_dir() . '/notes-modular-retention-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);
mkdir($tmp . '/tasks', 0700, true);
try {
    $manifest = (string) file_get_contents($root . '/modules/tasks/module.json');
    file_put_contents($tmp . '/tasks/module.json', $manifest);
    $pdo->exec('DELETE FROM module_lifecycle');
    try {
        (new BackgroundRetentionCleanup($db, $tmp))->verify();
        throw new RuntimeException('Принят недоступный фоновой обработчик');
    } catch (RuntimeException $error) {
        verifyRetention(str_contains($error->getMessage(), 'Недоступен фоновый обработчик'),
            'Недоступный обработчик не был закрыт с отказом');
    }

    $data = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
    unset($data['background']['retention_cleanup']);
    file_put_contents($tmp . '/tasks/module.json', json_encode($data, JSON_THROW_ON_ERROR));
    try {
        (new BackgroundRetentionCleanup($db, $tmp))->verify();
        throw new RuntimeException('Принята отсутствующая фоновая политика');
    } catch (RuntimeException $error) {
        verifyRetention(str_contains($error->getMessage(), 'Не объявлена фоновая очистка'),
            'Модуль с таблицами должен явно описывать политику фоновой очистки');
    }

    $data['background']['retention_cleanup'] = '../external.php';
    file_put_contents($tmp . '/tasks/module.json', json_encode($data, JSON_THROW_ON_ERROR));
    try {
        (new BackgroundRetentionCleanup($db, $tmp))->verify();
        throw new RuntimeException('Принят небезопасный путь к поставщику');
    } catch (RuntimeException $error) {
        verifyRetention(str_contains($error->getMessage(), 'небезопасный путь'),
            'Запрещён выход за каталог фонового обработчика');
    }
} finally {
    unlink($tmp . '/tasks/module.json');
    rmdir($tmp . '/tasks');
    rmdir($tmp);
}

echo "Контракт фоновой очистки владельцами модулей пройден\n";
