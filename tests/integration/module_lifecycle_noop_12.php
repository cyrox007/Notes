<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/core/Version.php';
require_once $root . '/core/ModuleManifest.php';
require_once $root . '/core/ModuleLifecycleStore.php';

use Core\DatabaseManager;
use Core\ModuleLifecycleStore;
use Core\ModuleManifest;
use Core\Version;

function checkLifecycleNoop(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'Согласование модулей: ' . $message . PHP_EOL);
        exit(1);
    }
}

final class LifecycleCountingConnection extends PDO
{
    public int $readCount = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (preg_match('/^\\s*SELECT\\s+module_id,installed_version/i', $query) === 1) {
            $this->readCount++;
        }
        return parent::prepare($query, $options);
    }
}

$pdo = new LifecycleCountingConnection('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE module_lifecycle (
    module_id TEXT PRIMARY KEY, installed_version TEXT, manifest_hash TEXT,
    configured_state TEXT NOT NULL, effective_state TEXT NOT NULL,
    last_error TEXT, discovered_at TEXT DEFAULT CURRENT_TIMESTAMP,
    state_changed_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec('CREATE TABLE lifecycle_writes (changes INTEGER NOT NULL)');
$pdo->exec('INSERT INTO lifecycle_writes VALUES (0)');
$pdo->exec('CREATE TRIGGER count_lifecycle_updates AFTER UPDATE ON module_lifecycle
    BEGIN UPDATE lifecycle_writes SET changes = changes + 1; END');

$manifest = ModuleManifest::fromFile($root . '/modules/defects/module.json', 'defects');
$insert = $pdo->prepare('INSERT INTO module_lifecycle
    (module_id,installed_version,manifest_hash,configured_state,effective_state,last_error)
    VALUES (:id,:version,:hash,:configured,:effective,NULL)');
$insert->execute([
    'id' => 'defects',
    'version' => $manifest->version(),
    'hash' => $manifest->integrityHash(),
    'configured' => 'enabled',
    'effective' => 'enabled',
]);

$database = DatabaseManager::forConnection($pdo);
$store = new ModuleLifecycleStore($database);
$modules = ['defects' => $manifest];
$writes = static fn (): int => (int) $database->fetchValue('SELECT changes FROM lifecycle_writes');

$initialReads = $pdo->readCount;
checkLifecycleNoop($store->reconcile($modules, Version::VERSION)['defects']['effective_state'] === 'enabled',
    'корректная композиция неожиданно отключена');
checkLifecycleNoop($pdo->readCount - $initialReads === 1,
    'неизменный состав должен проверяться одной выборкой БД');

$readsBeforeNext = $pdo->readCount;
$store->reconcile($modules, Version::VERSION);
checkLifecycleNoop($pdo->readCount - $readsBeforeNext === 1,
    'повторное согласование должно заново читать состояние, но только один раз');
checkLifecycleNoop($writes() === 0, 'повторная проверка неизменного модуля выполнила SQL UPDATE');

$pdo->exec("UPDATE module_lifecycle SET manifest_hash='старый_отпечаток'");
$pdo->exec('UPDATE lifecycle_writes SET changes=0');
$beforeChanged = $pdo->readCount;
$store->reconcile($modules, Version::VERSION);
checkLifecycleNoop($pdo->readCount - $beforeChanged === 2,
    'при изменённом манифесте необходимо перечитать подтверждённое состояние');
checkLifecycleNoop($writes() === 1, 'изменение контрольной суммы не привело к одной записи');
checkLifecycleNoop((string) $database->fetchValue('SELECT manifest_hash FROM module_lifecycle') === $manifest->integrityHash(),
    'новая контрольная сумма не сохранена');
$store->reconcile($modules, Version::VERSION);
checkLifecycleNoop($writes() === 1, 'проверка после исправления суммы повторно обновила строку');

$pdo->exec("UPDATE module_lifecycle SET installed_version='0.0.1'");
$pdo->exec('UPDATE lifecycle_writes SET changes=0');
$store->reconcile($modules, Version::VERSION);
checkLifecycleNoop($writes() === 1, 'изменение установленной версии не отражено');
checkLifecycleNoop((string) $database->fetchValue('SELECT installed_version FROM module_lifecycle') === $manifest->version(),
    'версия модуля не восстановлена');

$pdo->exec("UPDATE module_lifecycle SET configured_state='disabled',last_error='Прошлая ошибка'");
$pdo->exec('UPDATE lifecycle_writes SET changes=0');
$store->reconcile($modules, Version::VERSION);
checkLifecycleNoop($writes() === 1, 'изменение доступности и ошибки требует ровно одну запись');
$row = $database->fetchOne('SELECT effective_state,last_error FROM module_lifecycle');
checkLifecycleNoop($row['effective_state'] === 'disabled' && $row['last_error'] === null,
    'согласование не обновило доступность или устаревшую ошибку');
$store->reconcile($modules, Version::VERSION);
checkLifecycleNoop($writes() === 1, 'после отключения модуль повторно записывается без изменений');

echo 'Согласование модулей: отсутствие лишних UPDATE и сохранение изменений проверены' . PHP_EOL;
