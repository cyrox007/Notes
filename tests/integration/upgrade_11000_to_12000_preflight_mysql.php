<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('TEST_DB_PORT') ?: 3306);
$name = getenv('TEST_DB_NAME') ?: 'workspace_test';
$user = getenv('TEST_DB_USER') ?: 'workspace';
$pass = getenv('TEST_DB_PASS') ?: 'workspace';
if (!str_contains(strtolower($name), 'test')) {
    throw new RuntimeException('Испытание разрешено только на тестовой базе');
}
require_once $root . '/core/PrefixMysqli.php';
require_once $root . '/core/UpdateMigrationPreflight.php';
require_once $root . '/core/UpdateDatabaseMigrator.php';

use Core\MigrationBaseline;
use Core\MigrationManifest;
use Core\PrefixMysqli;
use Core\UpdateMigrationPreflight;
use Core\UpdateDatabaseMigrator;

function upgradeCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Обновление 1.1 → 1.2: ' . $message);
}
$manifest = new MigrationManifest($root);
$canonical = $manifest->names();
$baseline = MigrationBaseline::appliedNames($canonical, 11000);
upgradeCheck(count($baseline) === 36, 'изменился опубликованный baseline 1.1.0');
$expected = count($canonical) - count($baseline);
upgradeCheck($expected > 0, 'отсутствуют миграции 1.2');

$previous = getenv('DBPREFIX');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
foreach (['', 'nt12_'] as $prefix) {
    putenv('DBPREFIX=' . $prefix);
    $db = new PrefixMysqli($host, $user, $pass, $name, $port);
    $db->set_charset('utf8mb4');
    $physical = $db->namespace->name('schema_migrations');
    $quoted = chr(96) . $physical . chr(96);
    try {
        $db->query('DROP TABLE IF EXISTS ' . $quoted);
        $db->query(
            'CREATE TABLE ' . $quoted . ' (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(190) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_upgrade_baseline (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $insert = $db->prepare(
            'INSERT INTO ' . $quoted . ' (migration,checksum) VALUES (?,?)'
        );
        foreach ($baseline as $migration) {
            $checksum = hash('sha256', $manifest->readMigration($migration));
            $insert->bind_param('ss', $migration, $checksum);
            $insert->execute();
        }
        $insert->close();

        $check = (new UpdateMigrationPreflight($root))->check($db, 11000);
        upgradeCheck($check['ledger_present'] && !$check['legacy_untracked'],
            'журнал установленной версии не распознан');
        upgradeCheck($check['applied'] === count($baseline) && $check['pending'] === $expected,
            'неправильный состав ожидающих миграций');

        foreach (['DBDRIVER' => 'mysql', 'DBHOST' => $host, 'DBPORT' => (string) $port,
            'DBNAME' => $name, 'DBUSER' => $user, 'DBPASS' => $pass] as $key => $value) {
            putenv($key . '=' . $value);
        }
        $status = (new UpdateDatabaseMigrator($root))->run(true, 11000);
        upgradeCheck($status['pending'] === $expected,
            'штатный мигратор потерял журнал применённых миграций');

        $db->query("UPDATE " . $quoted . " SET checksum=REPEAT('0',64) WHERE id=1");
        $rejected = false;
        try {
            (new UpdateMigrationPreflight($root))->check($db, 11000);
        } catch (RuntimeException $exception) {
            $rejected = str_contains($exception->getMessage(), 'checksum');
        }
        upgradeCheck($rejected, 'искажённый checksum принят');

        $db->query('DROP TABLE ' . $quoted);
        $legacy = (new UpdateMigrationPreflight($root))->check($db, 11000);
        upgradeCheck(!$legacy['ledger_present'] && $legacy['legacy_untracked']
            && $legacy['applied'] === count($baseline) && $legacy['pending'] === $expected,
            'наследуемая база без журнала не распознана');
        $status = (new UpdateDatabaseMigrator($root))->run(true, 11000);
        upgradeCheck($status['pending'] === $expected,
            'штатный мигратор не учёл baseline базы без журнала');
    } finally {
        $db->query('DROP TABLE IF EXISTS ' . $quoted);
        $db->close();
    }
}
putenv($previous === false ? 'DBPREFIX' : 'DBPREFIX=' . $previous);
echo "Обновление 1.1 → 1.2: журнал, DBPREFIX и контрольные суммы проверены\n";
