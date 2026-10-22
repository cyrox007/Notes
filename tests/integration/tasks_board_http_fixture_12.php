<?php
declare(strict_types=1);

/** Изолированная БД для испытания настоящих HTTP-запросов Tasks. */
$name = getenv('DBNAME') ?: '';
if (!is_string($name) || !str_contains(strtolower($name), 'test')) {
    throw new RuntimeException('Удаление допустимо только для отдельной тестовой базы');
}
$db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DBHOST') ?: '127.0.0.1', getenv('DBPORT') ?: '3306', $name),
    getenv('DBUSER') ?: 'workspace', getenv('DBPASS') ?: 'workspace',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$mode = $argv[1] ?? 'setup';
$tables = ['task_api_access_log','task_api_rate_buckets','mutation_commands',
    'task_board_members','task_board_items','task_boards','task_api_tokens',
    'role_permissions','user_roles','permissions','roles','users'];
if (in_array($mode, ['setup','cleanup'], true)) {
    foreach ($tables as $table) $db->exec('DROP TABLE IF EXISTS ' . $table);
}
if ($mode === 'cleanup') exit(0);
if ($mode === 'revoke') {
    $db->exec('UPDATE task_api_tokens SET revoked_at=UTC_TIMESTAMP() WHERE id=2');
    exit(0);
}
if ($mode === 'restrict') {
    $db->exec("UPDATE task_board_members SET role='viewer' WHERE user_id=12");
    exit(0);
}
if ($mode === 'inspect') {
    echo json_encode([
        'role' => $db->query('SELECT role FROM task_board_members WHERE user_id=12')->fetchColumn(),
        'commands' => $db->query('SELECT command_id,status FROM mutation_commands ORDER BY id')->fetchAll(),
        'logs' => $db->query('SELECT action,status_code FROM task_api_access_log ORDER BY id')->fetchAll(),
    ], JSON_THROW_ON_ERROR);
    exit(0);
}
if ($mode !== 'setup') throw new InvalidArgumentException('Неизвестное действие');
foreach ([
    "CREATE TABLE users (id INT PRIMARY KEY,username VARCHAR(100) NOT NULL,is_active TINYINT NOT NULL DEFAULT 1,
        account_status VARCHAR(20) NOT NULL DEFAULT 'active') ENGINE=InnoDB",
    "CREATE TABLE roles (id INT PRIMARY KEY,code VARCHAR(60) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE permissions (id INT PRIMARY KEY,code VARCHAR(80) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE user_roles (user_id INT NOT NULL,role_id INT NOT NULL,PRIMARY KEY(user_id,role_id)) ENGINE=InnoDB",
    "CREATE TABLE role_permissions (role_id INT NOT NULL,permission_id INT NOT NULL,
        PRIMARY KEY(role_id,permission_id)) ENGINE=InnoDB",
    "CREATE TABLE task_boards (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,uid CHAR(32) NOT NULL UNIQUE,
        owner_user_id INT NOT NULL,name VARCHAR(160) NOT NULL,audience VARCHAR(20) NOT NULL DEFAULT 'members',
        is_archived TINYINT NOT NULL DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
    "CREATE TABLE task_board_members (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        board_id BIGINT UNSIGNED NOT NULL,user_id INT NOT NULL,role VARCHAR(20) NOT NULL,
        UNIQUE KEY uq_member(board_id,user_id)) ENGINE=InnoDB",
    "CREATE TABLE task_board_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,uid CHAR(32) NOT NULL UNIQUE,
        board_id BIGINT UNSIGNED NOT NULL,creator_user_id INT NOT NULL,title VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'pending',
        priority VARCHAR(20) NOT NULL DEFAULT 'medium',due_date DATETIME NULL,completed_at DATETIME NULL,
        archived_at DATETIME NULL,is_deleted TINYINT NOT NULL DEFAULT 0,deleted_at DATETIME NULL,
        version INT NOT NULL DEFAULT 1,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE task_api_tokens (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,scope VARCHAR(32) NOT NULL,
        expires_at DATETIME NOT NULL,revoked_at DATETIME NULL) ENGINE=InnoDB",
    "CREATE TABLE task_api_rate_buckets (token_id BIGINT UNSIGNED NOT NULL,window_start DATETIME NOT NULL,
        requests INT NOT NULL DEFAULT 0,PRIMARY KEY(token_id,window_start)) ENGINE=InnoDB",
    "CREATE TABLE task_api_access_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,token_id BIGINT UNSIGNED NOT NULL,
        user_id INT NOT NULL,action VARCHAR(32) NOT NULL,status_code SMALLINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL) ENGINE=InnoDB",
] as $sql) $db->exec($sql);
$journal = file_get_contents(dirname(__DIR__,2).'/database/migrations/20261017_mutation_commands.sql');
if (!is_string($journal) || !str_contains($journal,'CREATE TABLE')) throw new RuntimeException('Нет журнала');
$db->exec(substr($journal, strpos($journal,'CREATE TABLE')));
$db->exec("INSERT INTO users(id,username) VALUES (11,'Владелец'),(12,'Руководитель'),
    (13,'Наблюдатель'),(14,'Посторонний')");
$db->exec("INSERT INTO roles(id,code) VALUES(1,'worker')");
$db->exec("INSERT INTO permissions(id,code) VALUES(1,'tasks.use')");
$db->exec("INSERT INTO role_permissions(role_id,permission_id) VALUES(1,1)");
$db->exec("INSERT INTO user_roles(user_id,role_id) VALUES(11,1),(12,1),(13,1),(14,1)");
$insert = $db->prepare("INSERT INTO task_boards(uid,owner_user_id,name) VALUES(?,11,'Доска')");
$insert->execute([str_repeat('a',32)]);
$db->exec("INSERT INTO task_board_members(board_id,user_id,role) VALUES(1,12,'manager'),(1,13,'viewer')");
$insert = $db->prepare("INSERT INTO task_board_items
    (uid,board_id,creator_user_id,title,description,created_at,updated_at)
    VALUES(?,1,11,'Проверка','Описание',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$insert->execute([str_repeat('b',32)]);
foreach ([1=>11,2=>12,3=>13,4=>14] as $id=>$owner) {
    $token = 'ntk1_' . str_repeat((string)$id,64);
    $insert = $db->prepare("INSERT INTO task_api_tokens(id,user_id,name,token_hash,scope,expires_at)
        VALUES(?,?,?,?,'tasks.write',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))");
    $insert->execute([$id,$owner,'Тестовое подключение',hash('sha256',$token)]);
}
echo "Тестовая база HTTP API создана\n";
