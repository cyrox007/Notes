<?php
declare(strict_types=1);

/**
 * Испытание всех ожидающих миграций на схеме опубликованной версии.
 * Исходные таблицы предварительно создаёт стенд из тега v1.1.0.
 */
$root = dirname(__DIR__, 2);
$name = (string) (getenv('DBNAME') ?: '');
if ($name === '' || !str_contains(strtolower($name), 'test')) {
    throw new RuntimeException('Выполнение разрешено только на отдельной тестовой БД');
}

require_once $root . '/core/UpdateDatabaseMigrator.php';
require_once $root . '/core/MigrationManifest.php';
require_once $root . '/core/MigrationBaseline.php';
require_once $root . '/core/DatabaseOwnership.php';

$manifest = new \Core\MigrationManifest($root);
$names = $manifest->names();
$published = \Core\MigrationBaseline::appliedNames($names, 11000);
$owned = \Core\DatabaseOwnership::fromPackageRoot($root)->migrationNamesInCanonicalOrder($names);
$expected = count(array_diff($owned, $published));
if ($expected < 1) {
    throw new RuntimeException('Нет миграций новой версии');
}

$runner = new \Core\UpdateDatabaseMigrator($root);
$before = $runner->run(true, 11000);
if ($before['pending'] !== $expected) {
    throw new RuntimeException('Некорректное количество ожидающих миграций до обновления: '
        . $before['pending'] . ' вместо ' . $expected);
}

$applied = $runner->run(false, 11000);
if ($applied['applied'] !== $expected || $applied['pending'] !== 0) {
    throw new RuntimeException('Не все миграции 1.2 применились');
}

$second = $runner->run(false, 11000);
$status = $runner->run(true, 11000);
if ($second['applied'] !== 0 || $second['pending'] !== 0 || $status['pending'] !== 0) {
    throw new RuntimeException('Повторный запуск миграций не идемпотентен');
}

$db = new \Core\PrefixMysqli(
    (string) (getenv('DBHOST') ?: '127.0.0.1'),
    (string) (getenv('DBUSER') ?: 'workspace'),
    (string) (getenv('DBPASS') ?: 'workspace'),
    $name,
    (int) (getenv('DBPORT') ?: 3306)
);
$db->set_charset('utf8mb4');
try {
    $users = $db->query("SELECT username,lastname FROM users WHERE username='upgrade_11000_fixture'");
    $row = $users->fetch_assoc();
    $users->free();
    if (!is_array($row) || $row['lastname'] !== 'Сохранено') {
        throw new RuntimeException('Пользователь 1.1 повреждён при переходе на 1.2');
    }

    $count = $db->query('SELECT COUNT(*) AS total FROM schema_migrations')->fetch_assoc();
    if ((int) ($count['total'] ?? 0) !== count(array_unique(array_merge($published, $owned)))) {
        throw new RuntimeException('Журнал миграций не соответствует релизу');
    }
    // В режиме общей БД чужие таблицы без нашего префикса не меняются.
    if ($db->namespace->prefix !== '') {
        $physicalUsers = $db->namespace->name('users');
        $physicalLedger = $db->namespace->name('schema_migrations');
        if ($physicalUsers === 'users' || $physicalLedger === 'schema_migrations') {
            throw new RuntimeException('DBPREFIX не изолирует физические таблицы');
        }
        $raw = new mysqli(
            (string) (getenv('DBHOST') ?: '127.0.0.1'),
            (string) (getenv('DBUSER') ?: 'workspace'),
            (string) (getenv('DBPASS') ?: 'workspace'),
            $name,
            (int) (getenv('DBPORT') ?: 3306)
        );
        try {
            $foreign = $raw->query('SELECT marker FROM users WHERE id=1')->fetch_assoc();
            if (($foreign['marker'] ?? null) !== 'Чужая таблица') {
                throw new RuntimeException('Миграции изменили чужую таблицу в общей БД');
            }
            $unexpectedLedger = $raw->query(
                "SELECT COUNT(*) AS total FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='schema_migrations'"
            )->fetch_assoc();
            if ((int) ($unexpectedLedger['total'] ?? 1) !== 0) {
                throw new RuntimeException('Журнал миграций создан без DBPREFIX');
            }
        } finally {
            $raw->close();
        }
    }
} finally {
    $db->close();
}

echo 'Все ' . $expected
    . ' миграций 1.2 применены, повтор безопасен, данные пользователя сохранены'
    . PHP_EOL;
