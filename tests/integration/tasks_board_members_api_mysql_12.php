<?php
declare(strict_types=1);

namespace App\Services {
    /** В изолированном испытании задаём лимит явным параметром службы. */
    final class RolePolicyService {
        public function __construct(mixed $db = null) {}
        public function effectiveValue(int $user, string $module, string $key): int { return 0; }
    }
}

namespace {
    require_once dirname(__DIR__, 2) . '/core/DatabaseManager.php';
    require_once dirname(__DIR__, 2) . '/core/MutationCommandStore.php';
    require_once dirname(__DIR__, 2) . '/modules/tasks/services/TaskBoardService.php';
    require_once dirname(__DIR__, 2) . '/modules/tasks/services/TaskBoardMemberCommandService.php';

    use Core\DatabaseManager;
    use Modules\Tasks\TaskBoardMemberCommandService;

    function membershipAssert(bool $ok, string $message): void {
        if (!$ok) throw new RuntimeException('Участники API: ' . $message);
    }

    $database = getenv('TEST_DB_NAME') ?: '';
    membershipAssert(str_contains(strtolower($database), 'test'), 'требуется выделенная тестовая БД');
    $db = DatabaseManager::forConnection(new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            getenv('TEST_DB_HOST') ?: '127.0.0.1', (int) (getenv('TEST_DB_PORT') ?: 3306), $database),
        getenv('TEST_DB_USER') ?: 'workspace', getenv('TEST_DB_PASS') ?: 'workspace',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    ));
    $db->execute("CREATE TABLE users (id INT PRIMARY KEY,username VARCHAR(100) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 1,account_status VARCHAR(20) NOT NULL DEFAULT 'active') ENGINE=InnoDB");
    $db->execute("CREATE TABLE task_boards (
        id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,uid CHAR(32) NOT NULL UNIQUE,
        owner_user_id INT NOT NULL,name VARCHAR(160) NOT NULL,
        audience VARCHAR(20) NOT NULL DEFAULT 'members',
        is_archived TINYINT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $db->execute("CREATE TABLE task_board_members (
        id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,board_id BIGINT UNSIGNED NOT NULL,
        user_id INT NOT NULL,role VARCHAR(20) NOT NULL,
        joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_board_member(board_id,user_id)
    ) ENGINE=InnoDB");
    $migration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20261017_mutation_commands.sql');
    membershipAssert(is_string($migration), 'не найден журнал платформы');
    $db->execute(substr($migration, strpos($migration, 'CREATE TABLE')));

    try {
        $uid = str_repeat('b', 32);
        $db->execute("INSERT INTO users(id,username) VALUES
            (11,'Владелец'),(12,'Руководитель'),(13,'Участник'),(14,'Наблюдатель'),(15,'Новый'),(16,'Посторонний')");
        $db->execute('INSERT INTO task_boards(uid,owner_user_id,name) VALUES (:uid,11,:name)',
            [':uid' => $uid, ':name' => 'Доска']);
        $db->execute("INSERT INTO task_board_members(board_id,user_id,role) VALUES
            (1,12,'manager'),(1,13,'member'),(1,14,'viewer')");
        $service = new TaskBoardMemberCommandService($db, new \Core\MutationCommandStore(), static fn(): int => 10);

        $changed = $service->change(11, $uid, 13, 'member-change-001', 'member', 'viewer');
        membershipAssert($changed['success'] && $changed['role'] === 'viewer', 'владелец не изменил роль');
        $repeat = $service->change(11, $uid, 13, 'member-change-001', 'member', 'viewer');
        membershipAssert(($repeat['replayed'] ?? false) === true, 'повтор не возвращает сохранённый ответ');

        $conflict = $service->change(11, $uid, 13, 'member-change-002', 'member', 'manager');
        membershipAssert($conflict['error'] === 'role_conflict' && $conflict['current_role'] === 'viewer',
            'нет конфликта прежней роли');
        membershipAssert($db->fetchValue('SELECT role FROM task_board_members WHERE user_id=13') === 'viewer',
            'конфликт изменил роль');

        $denied = 0;
        foreach ([
            [12, 11, null, 'member'], [12, 13, 'viewer', 'manager'],
            [13, 14, 'viewer', 'member'], [12, 12, 'manager', 'member'],
            [16, 13, 'viewer', 'member'],
        ] as [$actor,$target,$previous,$next]) {
            try {
                $service->change($actor, $uid, $target, 'member-denied-' . $actor . '-' . $target, $previous, $next);
            } catch (\DomainException) {
                ++$denied;
            }
        }
        membershipAssert($denied === 5, 'обойдён запрет управления ролями');
        $allowed = $service->change(12, $uid, 14, 'member-change-003', 'viewer', 'member');
        membershipAssert($allowed['success'], 'руководитель не изменил наблюдателя');
        $added = $service->change(11, $uid, 15, 'member-change-004', null, 'viewer');
        membershipAssert($added['success'], 'владелец не добавил участника');
        $removed = $service->change(12, $uid, 15, 'member-change-005', 'viewer', null);
        membershipAssert($removed['success'] && $removed['role'] === null, 'руководитель не удалил наблюдателя');
        membershipAssert($db->fetchValue('SELECT role FROM task_board_members WHERE user_id=15') === null,
            'удалённый участник сохранился');
        $collision = false;
        try {
            $service->change(11, $uid, 13, 'member-change-001', 'viewer', 'member');
        } catch (\InvalidArgumentException) {
            $collision = true;
        }
        membershipAssert($collision, 'командный идентификатор повторно использован с другим телом');

        $limited = new TaskBoardMemberCommandService($db, new \Core\MutationCommandStore(), static fn(): int => 4);
        $limitHit = false;
        try {
            $limited->change(11, $uid, 15, 'member-change-006', null, 'member');
        } catch (\DomainException) {
            $limitHit = true;
        }
        membershipAssert($limitHit, 'не применяется ограничение количества участников');

        $db->execute("UPDATE task_boards SET audience='all_active' WHERE id=1");
        $publicDenied = false;
        try {
            $service->change(11, $uid, 14, 'member-change-007', 'member', 'viewer');
        } catch (\DomainException) {
            $publicDenied = true;
        }
        membershipAssert($publicDenied, 'можно изменять состав доски «Все пользователи»');
        membershipAssert((int) $db->fetchValue('SELECT COUNT(*) FROM task_board_members') === 3,
            'неожиданное изменение состава участников');
        echo "Участники Tasks API: роли, конфликты, повторы, лимит, исключения и удаление проверены\n";
    } finally {
        $db->execute('DROP TABLE IF EXISTS mutation_commands');
        $db->execute('DROP TABLE IF EXISTS task_board_members');
        $db->execute('DROP TABLE IF EXISTS task_boards');
        $db->execute('DROP TABLE IF EXISTS users');
    }
}
