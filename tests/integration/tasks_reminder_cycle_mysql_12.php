<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/DatabaseManager.php';
require_once dirname(__DIR__, 2) . '/modules/tasks/services/TaskReminderCycle.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/TaskReminderScheduleHealth.php';

use Core\DatabaseManager;
use App\Services\Notifications\TaskReminderScheduleHealth;
use Modules\Tasks\TaskReminderCycle;

function cycleCheck(bool $valid, string $description): void
{
    if (!$valid) {
        throw new RuntimeException('Фоновый цикл: ' . $description);
    }
}

$name = (string) (getenv('TEST_DB_NAME') ?: '');
cycleCheck($name !== '' && str_contains(strtolower($name), 'test'), 'требуется отдельная тестовая БД');
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('TEST_DB_HOST') ?: '127.0.0.1',
    getenv('TEST_DB_PORT') ?: '3306',
    $name
);
$user = getenv('TEST_DB_USER') ?: 'workspace';
$password = getenv('TEST_DB_PASS') ?: 'workspace';
$connection = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$otherConnection = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db = DatabaseManager::forConnection($connection);
$other = DatabaseManager::forConnection($otherConnection);
$schema = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20261022_tasks_reminder_cycle_runs.sql');
cycleCheck(is_string($schema), 'миграция отсутствует');
$db->getPdo()->exec($schema);

try {
    $cycle = new TaskReminderCycle($db);
    $second = new TaskReminderCycle($other);
    $busy = null;
    $ran = false;
    $first = $cycle->run(
        static function () use (&$busy, &$ran, $second): array {
            $busy = $second->run(
                static function () use (&$ran): array { $ran = true; return []; },
                static fn(): array => [],
                static fn(): array => [],
                static fn(): array => []
            );
            return ['published' => 2];
        },
        static fn(): array => ['published' => 3],
        static fn(): array => ['published' => 1],
        static fn(): array => ['claimed' => 8, 'sent' => 6, 'retry' => 2, 'failed' => 0]
    );
    cycleCheck($busy['status'] === 'busy' && !$ran, 'вторая копия выполнила задания при занятой блокировке');
    cycleCheck($first['status'] === 'ok' && $first['morning_published'] === 2
        && $first['personal_published'] === 3 && $first['board_published'] === 1
        && $first['delivery_sent'] === 6 && $first['delivery_retry'] === 2,
        'счётчики этапов потеряны');
    $diagnostics = $second->diagnose();
    cycleCheck($diagnostics['last']['status'] === 'ok'
        && (int) $diagnostics['last']['delivery_sent'] === 6
        && $diagnostics['last_24h']['runs'] === 1,
        'диагностика неверна');
    $admin = TaskReminderScheduleHealth::readReport($other);
    cycleCheck(($admin['last']['status'] ?? null) === 'ok'
        && (int) ($admin['last_24h']['runs'] ?? 0) === 1
        && (int) ($admin['last_24h']['sent'] ?? 0) === 6,
        'административная диагностика не видит записи фонового цикла');

    $deliveryRan = false;
    $failed = $second->run(
        static function (): array { throw new RuntimeException('секретное содержимое исключения'); },
        static fn(): array => ['published' => 1],
        static fn(): array => ['published' => 0],
        static function () use (&$deliveryRan): array {
            $deliveryRan = true;
            return ['claimed' => 1, 'sent' => 0, 'retry' => 0, 'failed' => 1];
        }
    );
    cycleCheck($deliveryRan && $failed['status'] === 'failed'
        && in_array('morning', $failed['failed_stages'], true)
        && in_array('delivery_failed_jobs', $failed['failed_stages'], true),
        'ошибка этапа скрыла частичный результат');
    $last = $cycle->diagnose()['last'];
    cycleCheck($last['status'] === 'failed' && $last['error_stage'] === 'morning,delivery_failed_jobs'
        && (int) $last['delivery_failed'] === 1,
        'результат сбоя не сохранён без пользовательских данных');

    $after = $cycle->run(
        static fn(): array => [],
        static fn(): array => [],
        static fn(): array => [],
        static fn(): array => []
    );
    cycleCheck($after['status'] === 'ok', 'блокировка осталась после прохода');
    echo "Фоновый цикл: межпроцессная блокировка, сбои, повтор и диагностика проверены\n";
} finally {
    $db->execute('DROP TABLE IF EXISTS task_reminder_cycle_runs');
}
