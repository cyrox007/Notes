<?php

declare(strict_types=1);

namespace Core;

use mysqli;
use PDO;
use PDOException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Операции установщика, связанные только с БД.
 *
 * HTTP, сессии, файловое хранилище и формирование .env сюда не входят.
 */
final class InstallerDatabaseService
{
    public function connect(
        string $host,
        int $port,
        string $database,
        string $username,
        #[SensitiveParameter] string $password
    ): PDO {
        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
            ]
        );
    }

    public function connectOrCreate(
        string $host,
        int $port,
        string $database,
        string $username,
        #[SensitiveParameter] string $password
    ): PDO {
        $this->assertDatabaseName($database);

        try {
            return $this->connect($host, $port, $database, $username, $password);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1049) {
                throw $exception;
            }
        }

        $this->createDatabase($host, $port, $database, $username, $password);
        return $this->connect($host, $port, $database, $username, $password);
    }

    public function assertServerCompatibility(PDO $pdo): string
    {
        $rawVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $support = HostingCompatibility::databaseServerSupport($rawVersion);
        if (!$support['supported']) {
            throw new RuntimeException($support['message']);
        }

        return $support['message'];
    }

    public function assertSchemaPrivileges(PDO $pdo): void
    {
        $suffix = substr(bin2hex(random_bytes(8)), 0, 16);
        $table = 'wo_install_probe_' . $suffix;
        $trigger = 'wo_install_trigger_' . $suffix;
        $quotedTable = '`' . $table . '`';
        $quotedTrigger = '`' . $trigger . '`';

        $this->createProbeTable($pdo, $quotedTable);

        try {
            $this->assertAlterPrivilege($pdo, $quotedTable);
            $this->assertTriggerPrivilege($pdo, $quotedTable, $quotedTrigger);
            $this->assertProbeTrigger($pdo, $quotedTable);
        } finally {
            $this->dropProbeObjects($pdo, $quotedTable, $quotedTrigger);
        }
    }

    /** @return list<string> */
    public function existingTables(PDO $pdo): array
    {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        return array_map(static fn ($table): string => trim((string) $table, '`'), $tables);
    }

    /** @param list<string> $files */
    public function importSchemas(
        string $host,
        int $port,
        string $database,
        string $username,
        #[SensitiveParameter] string $password,
        array $files
    ): void {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $mysqli = new mysqli($host, $username, $password, $database, $port);
        $mysqli->set_charset('utf8mb4');
        $mysqli->query('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($files as $file) {
                $this->importSchemaFile($mysqli, $file);
            }
        } finally {
            $mysqli->query('SET FOREIGN_KEY_CHECKS=1');
            $mysqli->close();
        }
    }

    public function createAdminUser(
        PDO $pdo,
        string $username,
        string $email,
        #[SensitiveParameter] string $password,
        string $firstname,
        string $lastname
    ): void {
        $passwordHash = password_hash($password, PASSWORD_ARGON2ID);
        if ($passwordHash === false) {
            throw new RuntimeException('Не удалось создать Argon2id-хеш пароля.');
        }

        $statement = $pdo->prepare(
            'INSERT INTO users (uid,username,email,password_hash,firstname,lastname,role,is_active,created_at,updated_at) '
            . 'VALUES (:uid,:username,:email,:password_hash,:firstname,:lastname,1,1,:created_at,:updated_at)'
        );
        $now = date('Y-m-d H:i:s');
        $statement->execute([
            ':uid' => $this->uuidV4(),
            ':username' => $username,
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':firstname' => $firstname,
            ':lastname' => $lastname,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    private function connectServer(
        string $host,
        int $port,
        string $username,
        #[SensitiveParameter] string $password
    ): PDO {
        return new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    private function createDatabase(
        string $host,
        int $port,
        string $database,
        string $username,
        #[SensitiveParameter] string $password
    ): void {
        try {
            $server = $this->connectServer($host, $port, $username, $password);
            $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'База «' . $database . '» не существует, а этот MySQL-пользователь не может создать её. '
                . 'Создайте пустую базу в панели хостинга и повторите установку.',
                0,
                $exception
            );
        }
    }

    private function assertDatabaseName(string $database): void
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) === 1) {
            return;
        }

        throw new RuntimeException('Имя базы может содержать только латиницу, цифры и _.');
    }

    private function createProbeTable(PDO $pdo, string $quotedTable): void
    {
        try {
            $pdo->exec(
                'CREATE TABLE ' . $quotedTable
                . ' (id INT NOT NULL PRIMARY KEY, marker INT NOT NULL DEFAULT 0) ENGINE=InnoDB'
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Пользователь БД не может создавать таблицы в выбранной базе. '
                . 'Для установки нужны права CREATE, ALTER, INDEX, REFERENCES и DROP в собственной базе.',
                0,
                $exception
            );
        }
    }

    private function assertAlterPrivilege(PDO $pdo, string $quotedTable): void
    {
        try {
            $pdo->exec('ALTER TABLE ' . $quotedTable . ' ADD COLUMN probe_value INT NULL');
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Пользователь БД не имеет права ALTER, необходимого для обновлений схемы.',
                0,
                $exception
            );
        }
    }

    private function assertTriggerPrivilege(PDO $pdo, string $quotedTable, string $quotedTrigger): void
    {
        try {
            $pdo->exec(
                'CREATE TRIGGER ' . $quotedTrigger
                . ' BEFORE INSERT ON ' . $quotedTable
                . ' FOR EACH ROW SET NEW.marker = 1'
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Пользователь БД не имеет права CREATE TRIGGER. '
                . 'Текущая схема Workspace Organizer использует триггеры RBAC и истории заметок.',
                0,
                $exception
            );
        }
    }

    private function assertProbeTrigger(PDO $pdo, string $quotedTable): void
    {
        $pdo->exec('INSERT INTO ' . $quotedTable . ' (id) VALUES (1)');
        $marker = (int) $pdo->query(
            'SELECT marker FROM ' . $quotedTable . ' WHERE id = 1'
        )->fetchColumn();

        if ($marker !== 1) {
            throw new RuntimeException('Проверочный триггер БД не выполнился.');
        }
    }

    private function dropProbeObjects(PDO $pdo, string $quotedTable, string $quotedTrigger): void
    {
        try {
            $pdo->exec('DROP TRIGGER IF EXISTS ' . $quotedTrigger);
        } catch (Throwable) {
            // Основную ошибку не маскируем; ниже всё равно пытаемся убрать таблицу.
        }

        try {
            $pdo->exec('DROP TABLE IF EXISTS ' . $quotedTable);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Пользователь БД не может удалить проверочную таблицу. '
                . 'Для безопасной установки и обновлений требуется право DROP.',
                0,
                $exception
            );
        }
    }

    private function importSchemaFile(mysqli $mysqli, string $file): void
    {
        if (!is_file($file) || is_link($file)) {
            throw new RuntimeException('Не найдена безопасная схема: ' . basename($file));
        }

        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Не удалось прочитать схему: ' . basename($file));
        }

        $mysqli->multi_query($sql);
        do {
            $result = $mysqli->store_result();
            if ($result !== false) {
                $result->free();
            }
        } while ($mysqli->more_results() && $mysqli->next_result());
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
