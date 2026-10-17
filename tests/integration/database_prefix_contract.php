<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Core\\')) {
        $path = $root . '/core/' . substr($class, 5) . '.php';
        if (is_file($path)) require_once $path;
    }
});
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$database = getenv('PREFIX_TEST_DBNAME');
check(is_string($database) && preg_match('/^prefix_test_[a-z0-9_]+$/D', $database) === 1, 'Explicit isolated PREFIX_TEST_DBNAME=prefix_test_* required');
putenv('DBNAME=' . $database);
$host = getenv('DBHOST') ?: '127.0.0.1';
$port = (int) (getenv('DBPORT') ?: 3306);
$user = getenv('DBUSER') ?: 'root';
$password = getenv('DBPASS') ?: '';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$raw = new mysqli($host, $user, $password, $database, $port);
check($raw->query('SHOW TABLES')->num_rows === 0, 'Use an empty isolated database');
$raw->query('CREATE TABLE unrelated (id INT PRIMARY KEY, payload TEXT) ENGINE=MyISAM');
$raw->query("INSERT INTO unrelated VALUES (1,'foreign data users notes')");
$schemas = array_map(fn ($file) => $root . '/' . $file, Core\DatabaseOwnership::fromPackageRoot($root)->schemaFiles());
$installer = new Core\InstallerDatabaseService();
$connections = [];
foreach (['', 'prod_', 'prod_test_'] as $prefix) {
    putenv('DBPREFIX=' . $prefix);
    $namespace = new Core\DatabasePrefix();
    $installer->importSchemas($host, $port, $database, $user, $password, $schemas);
    $pdo = $installer->connect($host, $port, $database, $user, $password);
    $installer->createAdminUser($pdo, 'admin', 'admin@example.test', 'prefix-test-password', 'Admin', 'Test');
    check((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'PDO table isolation');
    $statement = $pdo->prepare('SELECT :value AS sample FROM users WHERE username=:username');
    $statement->execute(['value' => "users ' FROM notes UPDATE tasks", 'username' => 'admin']);
    check($statement->fetchColumn() === "users ' FROM notes UPDATE tasks", 'Parameters must remain literal');
    $metadata = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');
    $metadata->execute(['table' => 'users']);
    check((int) $metadata->fetchColumn() === 1, 'Metadata parameter translation');
    $db = new Core\PrefixMysqli($host, $user, $password, $database, $port);
    $db->set_charset('utf8mb4');
    $connections[$prefix] = $db;
    check(Core\SchemaReadiness::inspect($root)['ready'], 'Schema readiness for ' . $prefix);
    check($db->query('SHOW TRIGGERS')->num_rows === 3, 'All fresh-schema triggers must belong to their own prefix');
    (new Core\UpdateDatabaseMigrator($root))->run(false);
    check((new Core\UpdateDatabaseMigrator($root))->run(true)['pending'] === 0, 'Independent migration ledger');
    $db->query("INSERT INTO notes(uid,user_id,notename,content) VALUES ('prefix-note',1,'Prefix test','users FROM notes')");
    check((int) $db->query('SELECT COUNT(*) AS n FROM note_history')->fetch_assoc()['n'] === 1, 'Note trigger wrote into another namespace');
    check((int) $db->query('SELECT COUNT(*) AS n FROM user_roles')->fetch_assoc()['n'] === 1, 'User role trigger wrote into another namespace');
    $db->query("INSERT INTO system_settings(setting_key,setting_value,setting_type,category,is_editable) VALUES ('prefix_marker','" . $prefix . "','string','testing',1)");
    check((int) $db->query('SELECT COUNT(*) AS n FROM schema_migrations')->fetch_assoc()['n'] > 30, 'Namespaced ledger');
}
// Connections capture the prefix; changing process configuration must not retarget them.
foreach ($connections as $prefix => $db) {
    check($db->query("SELECT setting_value FROM system_settings WHERE setting_key='prefix_marker'")->fetch_assoc()['setting_value'] === $prefix, 'Connection namespace changed');
}
$auditRoot = sys_get_temp_dir() . '/prefix-contract-' . bin2hex(random_bytes(6));
mkdir($auditRoot); mkdir($auditRoot . '/app'); file_put_contents($auditRoot . '/app/index.php', '<?php echo "fixture";');
foreach ($connections as $prefix => $db) {
    putenv('DBPREFIX=' . $prefix);
    $backup = (new Core\UpdateBackupManager($auditRoot . '/backups', $auditRoot . '/app'))->create('prefix-' . ($prefix ?: 'empty'), $db);
    check($backup['database']['table_prefix'] === $prefix, 'Backup namespace identity');
    foreach ($backup['database']['table_metadata'] as $table) check($db->namespace->ownsTable($table['name']), 'Foreign table in dump');
    $db->query("UPDATE system_settings SET setting_value='changed' WHERE setting_key='prefix_marker'");
    $db->namespace->includeTargetIdentifiers(['future_feature_rows'], ['future_feature_procedure']);
    $db->query('CREATE TABLE future_feature_rows(id INT PRIMARY KEY) ENGINE=InnoDB');
    $db->query('CREATE PROCEDURE future_feature_procedure() SELECT 1');
    $db->close();
    $db = new Core\PrefixMysqli($host, $user, $password, $database, $port);
    $connections[$prefix] = $db;
    $db->namespace->includeTargetIdentifiers(['future_feature_rows'], ['future_feature_procedure']);
    (new Core\UpdateDatabaseRestorer())->restore($db, $backup['backup_dir'], $backup['database']);
    check($raw->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='" . $db->namespace->name('future_feature_rows') . "'")->num_rows === 0, 'Rollback left a table introduced by the target package');
    check($db->query("SELECT setting_value FROM system_settings WHERE setting_key='prefix_marker'")->fetch_assoc()['setting_value'] === $prefix, 'Rollback failed');
    // Exercise the checkpointed HTTP rollback path as well.
    $db->query("UPDATE system_settings SET setting_value='changed-again' WHERE setting_key='prefix_marker'");
    $db->query('CREATE TABLE future_feature_rows(id INT PRIMARY KEY) ENGINE=InnoDB');
    for ($attempt = 0; $attempt < 200; ++$attempt) {
        try {
            (new Core\UpdateDatabaseRestorer())->restore($db, $backup['backup_dir'], $backup['database'], new Core\UpdateStepBudget(10, 10));
            break;
        } catch (Core\UpdateStepPending) {}
    }
    check($attempt < 200, 'Checkpointed restore did not finish');
    check($raw->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='" . $db->namespace->name('future_feature_rows') . "'")->num_rows === 0, 'Checkpointed rollback left a new table');
    foreach ($connections as $otherPrefix => $other) {
        check($other->query("SELECT setting_value FROM system_settings WHERE setting_key='prefix_marker'")->fetch_assoc()['setting_value'] === $otherPrefix, 'Rollback touched a neighboring installation');
    }
    $other = $prefix === 'prod_' ? $connections['prod_test_'] : $connections['prod_'];
    try {
        (new Core\UpdateDatabaseRestorer())->restore($other, $backup['backup_dir'], $backup['database']);
        throw new RuntimeException('Cross-prefix restore was accepted');
    } catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'Префикс'), 'Wrong restore rejection: ' . $error->getMessage()); }
}
check($raw->query('SELECT payload FROM unrelated WHERE id=1')->fetch_assoc()['payload'] === 'foreign data users notes', 'Foreign table modified');
foreach (['bad-prefix_', '../', 'test', 'A_', str_repeat('a', 17) . '_'] as $invalid) {
    try { new Core\DatabasePrefix($invalid); throw new RuntimeException('Invalid prefix accepted'); }
    catch (InvalidArgumentException) {}
}
echo "DBPREFIX: three installations (including empty/nested prefix), full schemas/triggers, PDO/mysqli, metadata, migration ledgers, backups and rollback isolation passed\n";
