<?php
declare(strict_types=1);

namespace App\Services {
    // На изолированном стенде политика не ограничивает число задач.
    // Реальная проверка ролей выполняется существующим платформенным тестом.
    final class RolePolicyService {
        public function effectiveValue(int $user, string $module, string $key): int {
            return 0;
        }
    }
}

namespace {
    require_once dirname(__DIR__, 2) . '/core/DatabaseManager.php';
    require_once dirname(__DIR__, 2) . '/core/MutationCommandStore.php';
    require_once dirname(__DIR__, 2) . '/modules/tasks/services/TaskApiCreateService.php';
    require_once dirname(__DIR__, 2) . '/modules/tasks/services/TaskVersionMutationService.php';

    use App\Services\Tasks\TaskVersionMutationService;
    use Core\DatabaseManager;
    use Modules\Tasks\TaskApiCreateService;

    $name = getenv('TEST_DB_NAME') ?: '';
    if (!str_contains(strtolower($name), 'test')) {
        throw new RuntimeException('Требуется изолированная тестовая база');
    }
    $db = DatabaseManager::forConnection(new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            getenv('TEST_DB_HOST') ?: '127.0.0.1',
            (int) (getenv('TEST_DB_PORT') ?: 3306), $name),
        getenv('TEST_DB_USER') ?: 'workspace',
        getenv('TEST_DB_PASS') ?: 'workspace',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    ));

    function ensure(bool $value, string $message): void {
        if (!$value) throw new RuntimeException('Tasks API: ' . $message);
    }

    try {
        $db->execute('CREATE TABLE tasks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uid CHAR(32) NOT NULL UNIQUE,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            status VARCHAR(24) NOT NULL DEFAULT "pending",
            priority VARCHAR(24) NOT NULL DEFAULT "medium",
            due_date DATETIME NULL, completed_at DATETIME NULL,
            archived_at DATETIME NULL, is_deleted TINYINT NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL, version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB');
        $ddl = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20261017_mutation_commands.sql');
        ensure(is_string($ddl), 'нет платформенного журнала');
        $db->execute(substr($ddl, strpos($ddl, 'CREATE TABLE')));
        $creator = new TaskApiCreateService($db);
        $created = $creator->create(11, 'task-create-command-001', ['title' => 'Новая задача', 'priority' => 'high']);
        $repeat = $creator->create(11, 'task-create-command-001', ['priority' => 'high', 'title' => 'Новая задача']);
        ensure(($created['success'] ?? false) && ($repeat['replayed'] ?? false), 'повтор создания не идемпотентен');
        ensure($created['task_uid'] === $repeat['task_uid'], 'повтор выдал второй UID');
        ensure((int) $db->fetchValue('SELECT COUNT(*) FROM tasks') === 1, 'создалась вторая задача');

        $mutator = new TaskVersionMutationService($db);
        $updated = $mutator->mutateOwned(11, $created['task_uid'], 1, 'task-update-command-001',
            'task.api.update', ['title' => 'Исправлено']);
        ensure($updated['version'] === 2, 'не повысилась версия');
        $repeated = $mutator->mutateOwned(11, $created['task_uid'], 1, 'task-update-command-001',
            'task.api.update', ['title' => 'Исправлено']);
        ensure($repeated['replayed'] === true && $repeated['version'] === 2, 'повтор изменения не сохранён');

        $conflict = $mutator->mutateOwned(11, $created['task_uid'], 1,
            'task-update-command-002', 'task.api.update', ['title' => 'Устарело']);
        ensure($conflict['error'] === 'version_conflict', 'нет конфликта версии');
        ensure($db->fetchValue('SELECT title FROM tasks WHERE uid=:uid',
            [':uid' => $created['task_uid']]) === 'Исправлено', 'конфликт изменил данные');
        $denied = false;
        try {
            $mutator->mutateOwned(12, $created['task_uid'], 2,
                'task-update-command-003', 'task.api.update', ['title' => 'Чужое']);
        } catch (RuntimeException) {
            $denied = true;
        }
        ensure($denied, 'можно изменить чужую задачу');

        $collision = false;
        try {
            $creator->create(11, 'task-create-command-001', ['title' => 'Иной запрос']);
        } catch (InvalidArgumentException) {
            $collision = true;
        }
        ensure($collision, 'один command_id принят для другого содержимого');
        echo "Tasks API: создание, повтор, изменение, конфликт и владельцы проверены\n";
    } finally {
        $db->execute('DROP TABLE IF EXISTS mutation_commands');
        $db->execute('DROP TABLE IF EXISTS tasks');
    }
}
