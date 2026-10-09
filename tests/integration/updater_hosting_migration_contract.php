<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/UpdateDatabaseMigrator.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli((string) getenv('DBHOST'), (string) getenv('DBUSER'),
    (string) getenv('DBPASS'), (string) getenv('DBNAME'), (int) (getenv('DBPORT') ?: 3306));
$db->set_charset('utf8mb4');
$migration = '20261002_user_lifecycle.sql';
$sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $migration);
$execute = new ReflectionMethod(Core\UpdateDatabaseMigrator::class, 'executeMigration');
$migrator = new Core\UpdateDatabaseMigrator();
try {
    foreach (['missing', 'partial', 'complete', 'incompatible'] as $variant) {
        $db->query('DROP TABLE IF EXISTS users');
        $db->query('CREATE TABLE users (id INT PRIMARY KEY, account_status VARCHAR(20) NOT NULL) ENGINE=InnoDB');
        $db->query("INSERT INTO users VALUES (1, 'active')");
        if ($variant === 'partial' || $variant === 'complete') {
            $db->query('ALTER TABLE users ADD deletion_requested_at DATETIME NULL');
        }
        if ($variant === 'complete') {
            $db->query('ALTER TABLE users ADD purge_after DATETIME NULL, ADD anonymized_at DATETIME NULL,
                ADD KEY idx_users_purge (purge_after, anonymized_at)');
        }
        if ($variant === 'incompatible') {
            $db->query('ALTER TABLE users ADD purge_after VARCHAR(20) NULL');
        }
        $failed = false;
        try { $execute->invoke($migrator, $db, $sql, $migration); }
        catch (RuntimeException $error) {
            if ($variant !== 'incompatible') throw $error;
            $failed = str_contains($error->getMessage(), $migration)
                && str_contains($error->getMessage(), 'SQLSTATE=42S22');
        }
        if ($variant === 'incompatible') {
            if (!$failed) throw new RuntimeException('Несовместимая схема не отклонена');
            $added = $db->query("SELECT COUNT(*) AS c FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='users' AND column_name='deletion_requested_at'")
                ->fetch_assoc()['c'];
            if ((int) $added !== 0) throw new RuntimeException('Несовместимость обнаружена после первого ALTER');
        } else {
            $columns = $db->query("SELECT COUNT(*) AS c FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='users'
                AND column_name IN ('deletion_requested_at','purge_after','anonymized_at')")
                ->fetch_assoc()['c'];
            if ((int) $columns !== 3) throw new RuntimeException('Поля миграции не созданы');
            // Повторная синхронизация допустима без повторного создания полей.
            $execute->invoke($migrator, $db, $sql, $migration);
        }
        if ((string) $db->query('SELECT account_status FROM users WHERE id=1')->fetch_assoc()['account_status'] !== 'active') {
            throw new RuntimeException('Контрольная строка пользователя изменена');
        }
    }
    echo "[OK] Миграция без прав на процедуры: новая, частичная, полная и несовместимая схема\n";
} finally {
    $db->query('DROP TABLE IF EXISTS users');
    $db->close();
}
