<?php
declare(strict_types=1);

/**
 * Резервное копирование настоящими службами и возврат после двух обрывов PHP.
 * Запускается исключительно на выделенной тестовой БД.
 */
$root = dirname(__DIR__, 2);
require_once $root . '/core/PrefixMysqli.php';
require_once $root . '/core/UpdateBackupManager.php';
require_once $root . '/core/UpdateRollbackCodeRestorer.php';
require_once $root . '/core/UpdateDatabaseRestorer.php';

use Core\PrefixMysqli;
use Core\UpdateBackupManager;
use Core\UpdateRollbackCodeRestorer;
use Core\UpdateDatabaseRestorer;

function recoveryAssert(bool $ok, string $reason): void
{
    if (!$ok) {
        throw new RuntimeException('Восстановление обновлятора: ' . $reason);
    }
}

function recoveryDb(): PrefixMysqli
{
    $database = (string) getenv('TEST_DB_NAME');
    recoveryAssert($database !== '' && str_contains(strtolower($database), 'test'),
        'не разрешено использовать рабочую базу');
    return new PrefixMysqli(
        getenv('TEST_DB_HOST') ?: '127.0.0.1',
        getenv('TEST_DB_USER') ?: 'workspace',
        getenv('TEST_DB_PASS') ?: 'workspace',
        $database,
        (int) (getenv('TEST_DB_PORT') ?: 3306)
    );
}

function recoveryDeleteTree(string $directory): void
{
    if (!is_dir($directory) || is_link($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            recoveryDeleteTree($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

$stage = $argv[1] ?? 'test';
$sandbox = (string) ($argv[2] ?? '');
$transaction = (string) ($argv[3] ?? 'recovery-live-12');
if (in_array($stage, ['damage', 'restore-code', 'restore-db'], true)) {
    recoveryAssert(is_dir($sandbox), 'стенд отсутствует');
    if ($stage === 'damage') {
        file_put_contents($sandbox . '/live/core/Version.php', 'Новая версия');
        file_put_contents($sandbox . '/live/new-file.php', 'Неудачная миграция кода');
        $db = recoveryDb();
        $db->query("UPDATE users SET title='Повреждено' WHERE id=1");
        $db->query('CREATE TABLE mutation_commands (id INT PRIMARY KEY) ENGINE=InnoDB');
        $db->close();
        exit(73);
    }
    $manager = new UpdateBackupManager($sandbox . '/backups', $sandbox . '/live');
    $backup = $manager->verify($sandbox . '/backups/' . $transaction, $transaction);
    if ($stage === 'restore-code') {
        (new UpdateRollbackCodeRestorer($sandbox . '/live'))
            ->restore($transaction, $backup['backup_dir']);
        exit(74);
    }
    $db = recoveryDb();
    try {
        (new UpdateDatabaseRestorer())->restore(
            $db, $backup['backup_dir'], $backup['database']);
    } finally {
        $db->close();
    }
    exit(0);
}

recoveryAssert(function_exists('proc_open'), 'proc_open недоступен');
$prefix = (string) (getenv('DBPREFIX') ?: '');
recoveryAssert(in_array($prefix, ['', 'nt12_'], true),
    'неизвестный префикс испытательного стенда');
$sandbox = sys_get_temp_dir() . '/notes-recovery-real-' . bin2hex(random_bytes(7));
foreach (['live/core', 'live/uploads', 'backups'] as $folder) {
    recoveryAssert(mkdir($sandbox . '/' . $folder, 0700, true),
        'не удалось создать ' . $folder);
}
$transaction = 'recovery-' . bin2hex(random_bytes(7));
$expectedSource = "<?php\n// Версия 1.1.0\n";
file_put_contents($sandbox . '/live/core/Version.php', $expectedSource);
file_put_contents($sandbox . '/live/index.php', '<?php echo "1.1.0";');
file_put_contents($sandbox . '/live/uploads/preserved.txt', 'Файл пользователя');
$db = recoveryDb();
try {
    if ($prefix !== '') {
        // Посторонняя установка в общей БД не должна попасть в резервную
        // копию Notes и не должна исчезнуть при аварийном восстановлении.
        $foreign = new mysqli(
            (string) getenv('TEST_DB_HOST'),
            (string) getenv('TEST_DB_USER'),
            (string) getenv('TEST_DB_PASS'),
            (string) getenv('TEST_DB_NAME'),
            (int) (getenv('TEST_DB_PORT') ?: 3306)
        );
        try {
            $foreign->query('CREATE TABLE users (id INT PRIMARY KEY, marker VARCHAR(64) NOT NULL) ENGINE=InnoDB');
            $foreign->query("INSERT INTO users VALUES (19, 'Чужая установка')");
            $foreign->query('CREATE TABLE mutation_commands (id INT PRIMARY KEY, marker VARCHAR(64) NOT NULL) ENGINE=InnoDB');
            $foreign->query("INSERT INTO mutation_commands VALUES (31, 'Чужая операция')");
        } finally {
            $foreign->close();
        }
    }
    $db->query('CREATE TABLE users
        (id INT PRIMARY KEY, title VARCHAR(64) NOT NULL) ENGINE=InnoDB');
    $db->query("INSERT INTO users VALUES (1, 'Сохранено')");
    $backup = (new UpdateBackupManager($sandbox . '/backups', $sandbox . '/live'))
        ->create($transaction, $db);
    recoveryAssert(($backup['database']['table_prefix'] ?? null) === $prefix,
        'резервная копия потеряла префикс текущей установки');
    recoveryAssert((int) ($backup['database']['tables'] ?? 0) === 1,
        'в резервную копию попали посторонние таблицы');
    $db->close();

    foreach ([['damage', 73], ['restore-code', 74], ['restore-db', 0]] as [$step, $code]) {
        $proc = proc_open([PHP_BINARY, __FILE__, $step, $sandbox, $transaction],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, $sandbox);
        recoveryAssert(is_resource($proc), 'не удалось запустить ' . $step);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        recoveryAssert(proc_close($proc) === $code,
            'неверный результат ' . $step . ': ' . $output . $error);
    }
    recoveryAssert(file_get_contents($sandbox . '/live/core/Version.php') === $expectedSource,
        'исходный PHP-файл не восстановлен');
    recoveryAssert(!file_exists($sandbox . '/live/new-file.php'),
        'лишний файл кандидата сохранился');
    recoveryAssert(file_get_contents($sandbox . '/live/uploads/preserved.txt') === 'Файл пользователя',
        'пользовательский файл удалён');
    $db = recoveryDb();
    $value = $db->query('SELECT title FROM users WHERE id=1')->fetch_assoc();
    recoveryAssert(($value['title'] ?? '') === 'Сохранено', 'не восстановлена строка БД');
    $physical = $db->namespace->name('mutation_commands');
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name=?'
    );
    $stmt->bind_param('s', $physical);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    recoveryAssert((int) ($result['total'] ?? -1) === 0,
        'лишняя таблица миграции текущей установки не удалена');
    if ($prefix !== '') {
        $foreign = new mysqli(
            (string) getenv('TEST_DB_HOST'),
            (string) getenv('TEST_DB_USER'),
            (string) getenv('TEST_DB_PASS'),
            (string) getenv('TEST_DB_NAME'),
            (int) (getenv('TEST_DB_PORT') ?: 3306)
        );
        try {
            $externalUser = $foreign->query('SELECT marker FROM users WHERE id=19')->fetch_assoc();
            $externalMutation = $foreign->query('SELECT marker FROM mutation_commands WHERE id=31')->fetch_assoc();
            recoveryAssert(($externalUser['marker'] ?? null) === 'Чужая установка'
                && ($externalMutation['marker'] ?? null) === 'Чужая операция',
                'аварийное восстановление изменило данные чужой установки');
        } finally {
            $foreign->close();
        }
    }
    echo "Код и БД восстановлены после двух обрывов PHP; префикс: "
        . ($prefix !== '' ? $prefix : 'без префикса') . "\n";
} finally {
    try {
        $db = recoveryDb();
        $db->query('DROP TABLE IF EXISTS mutation_commands');
        $db->query('DROP TABLE IF EXISTS users');
        $db->close();
        if ($prefix !== '') {
            $foreign = new mysqli(
                (string) getenv('TEST_DB_HOST'),
                (string) getenv('TEST_DB_USER'),
                (string) getenv('TEST_DB_PASS'),
                (string) getenv('TEST_DB_NAME'),
                (int) (getenv('TEST_DB_PORT') ?: 3306)
            );
            try {
                $foreign->query('DROP TABLE IF EXISTS mutation_commands');
                $foreign->query('DROP TABLE IF EXISTS users');
            } finally {
                $foreign->close();
            }
        }
    } finally {
        recoveryDeleteTree($sandbox);
    }
}
