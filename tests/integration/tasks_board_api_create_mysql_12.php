<?php
declare(strict_types=1);

namespace App\Services {
    /** Изолированная политика; права самих участников проверяет настоящий TaskBoardService. */
    final class RolePolicyService
    {
        public function __construct(mixed $db = null) {}
        public function effectiveValue(int $user, string $module, string $policy): int
        {
            return 0;
        }
    }
}

namespace {
    require_once dirname(__DIR__, 2) . '/core/DatabaseManager.php';
    require_once dirname(__DIR__, 2) . '/core/MutationCommandStore.php';
    require_once dirname(__DIR__, 2) . '/modules/tasks/services/TaskBoardService.php';
    require_once dirname(__DIR__, 2) . '/modules/tasks/services/TaskBoardApiCreateService.php';

    use Core\DatabaseManager;
    use Modules\Tasks\TaskBoardApiCreateService;

    function boardCheck(bool $condition, string $message): void
    {
        if (!$condition) throw new RuntimeException('Создание задач доски: ' . $message);
    }

    $database = getenv('TEST_DB_NAME') ?: '';
    boardCheck(str_contains(strtolower($database), 'test'), 'нельзя использовать рабочую базу');
    $db = DatabaseManager::forConnection(new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            getenv('TEST_DB_HOST') ?: '127.0.0.1',
            (int) (getenv('TEST_DB_PORT') ?: 3306),
            $database),
        getenv('TEST_DB_USER') ?: 'workspace',
        getenv('TEST_DB_PASS') ?: 'workspace',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    ));

    $db->execute("CREATE TABLE users (
        id INT PRIMARY KEY,username VARCHAR(100) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        account_status VARCHAR(20) NOT NULL DEFAULT 'active'
    ) ENGINE=InnoDB");
    $db->execute("CREATE TABLE task_boards (
        id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        uid CHAR(32) NOT NULL UNIQUE,
        owner_user_id INT NOT NULL,
        name VARCHAR(160) NOT NULL, audience VARCHAR(20) NOT NULL DEFAULT 'members',
        is_archived TINYINT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $db->execute("CREATE TABLE task_board_members (
        id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        board_id BIGINT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        role VARCHAR(20) NOT NULL
    ) ENGINE=InnoDB");
    $db->execute("CREATE TABLE task_board_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uid CHAR(32) NOT NULL UNIQUE,
        board_id BIGINT UNSIGNED NOT NULL,creator_user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,description TEXT,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        priority VARCHAR(20) NOT NULL DEFAULT 'medium',
        due_date DATETIME NULL,is_deleted TINYINT NOT NULL DEFAULT 0,
        version BIGINT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $manifest = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20261017_mutation_commands.sql');
    boardCheck(is_string($manifest), 'нет журнала команд');
    $db->execute(substr($manifest, strpos($manifest, 'CREATE TABLE')));

    try {
        $boardUid = str_repeat('a', 32);
        $db->execute("INSERT INTO users(id,username) VALUES
            (11,'Владелец'),(12,'Участник'),(13,'Наблюдатель'),(14,'Посторонний')");
        $db->execute('INSERT INTO task_boards(uid,owner_user_id,name) VALUES (:uid,11,:name)',
            [':uid' => $boardUid, ':name' => 'Проект']);
        $db->execute("INSERT INTO task_board_members(board_id,user_id,role) VALUES
            (1,12,'member'),(1,13,'viewer')");
        $service = new TaskBoardApiCreateService($db);
        $input = ['title' => 'Проверить проект', 'priority' => 'high'];
        $created = $service->create(12, $boardUid, 'board-create-command-001', $input);
        boardCheck(($created['success'] ?? false) && ($created['version'] ?? null) === 1,
            'участник не создал задачу');
        $same = $service->create(12, $boardUid, 'board-create-command-001',
            ['priority' => 'high', 'title' => 'Проверить проект']);
        boardCheck(($same['replayed'] ?? false) && $same['task_uid'] === $created['task_uid'],
            'повтор не возвращает исходную задачу');
        boardCheck((int) $db->fetchValue('SELECT COUNT(*) FROM task_board_items') === 1,
            'при повторе создалась лишняя задача');

        $denied = 0;
        foreach ([13,14] as $actor) {
            try {
                $service->create($actor, $boardUid, 'board-denied-command-00' . $actor, $input);
            } catch (\DomainException) {
                ++$denied;
            }
        }
        boardCheck($denied === 2, 'наблюдатель или посторонний смог создать задачу');
        boardCheck((int) $db->fetchValue('SELECT COUNT(*) FROM mutation_commands') === 1,
            'отказ записался как изменяющая команда');
        $collision = false;
        try {
            $service->create(12, $boardUid, 'board-create-command-001', ['title' => 'Подмена']);
        } catch (\InvalidArgumentException) {
            $collision = true;
        }
        boardCheck($collision, 'повтор command_id с другим телом принят');
        $db->execute('UPDATE task_board_members SET role=:role WHERE user_id=12', [':role' => 'viewer']);
        $revoked = false;
        try {
            $service->create(12, $boardUid, 'board-create-command-001', $input);
        } catch (\DomainException) {
            $revoked = true;
        }
        boardCheck($revoked, 'повтор после отзыва доступа разрешён');
        $db->execute('UPDATE task_board_members SET role=:role WHERE user_id=12', [':role' => 'manager']);
        $second = $service->create(12, $boardUid, 'board-create-command-002', ['title' => 'Следующая']);
        boardCheck($second['task_uid'] !== $created['task_uid'], 'новая команда не создала задачу');
        $db->execute('UPDATE task_boards SET is_archived=1 WHERE id=1');
        $archived = false;
        try {
            $service->create(11, $boardUid, 'board-create-command-003', $input);
        } catch (\DomainException) {
            $archived = true;
        }
        boardCheck($archived, 'архивная доска разрешает запись');
        boardCheck((int) $db->fetchValue('SELECT COUNT(*) FROM task_board_items') === 2,
            'отказы изменили содержимое доски');
        echo "API досок: роли, повторы, конфликт идентификатора, отзыв доступа и архив проверены\n";
    } finally {
        $db->execute('DROP TABLE IF EXISTS mutation_commands');
        $db->execute('DROP TABLE IF EXISTS task_board_items');
        $db->execute('DROP TABLE IF EXISTS task_board_members');
        $db->execute('DROP TABLE IF EXISTS task_boards');
        $db->execute('DROP TABLE IF EXISTS users');
    }
}
