<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/DatabaseManager.php';
require_once dirname(__DIR__, 2) . '/modules/tasks/services/TaskApiTrafficService.php';
require_once dirname(__DIR__, 2) . '/core/ModuleRetentionCleanup.php';
require_once dirname(__DIR__, 2) . '/core/RetentionFileCleanup.php';
require_once dirname(__DIR__, 2) . '/core/RetentionCleanupContext.php';
require_once dirname(__DIR__, 2) . '/modules/tasks/services/TasksRetentionService.php';

use Core\DatabaseManager;
use Modules\Tasks\TaskApiTrafficService;
use Modules\Tasks\TasksRetentionService;
use Core\RetentionCleanupContext;
use Core\RetentionFileCleanup;

function trafficCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('Лимиты Tasks API: ' . $message);
    }
}

$name = (string) getenv('TEST_DB_NAME');
trafficCheck($name !== '' && str_contains(strtolower($name), 'test'), 'нужна изолированная тестовая БД');
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        getenv('TEST_DB_HOST') ?: '127.0.0.1',
        (int) (getenv('TEST_DB_PORT') ?: 3306), $name),
    getenv('TEST_DB_USER') ?: 'workspace',
    getenv('TEST_DB_PASS') ?: 'workspace',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$db = DatabaseManager::forConnection($pdo);
$db->execute('CREATE TABLE users (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB');
$db->execute('CREATE TABLE task_api_tokens (id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    user_id INT NOT NULL,name VARCHAR(100) NOT NULL) ENGINE=InnoDB');
$db->execute("INSERT INTO users(id) VALUES (11),(12)");
$db->execute("INSERT INTO task_api_tokens(id,user_id,name) VALUES (31,11,'Телефон'),(32,12,'Планшет')");
$sql = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20261022_tasks_api_rate_audit.sql');
trafficCheck(is_string($sql), 'не найдена реальная миграция');
$sql = preg_replace('/^\s*--[^\n]*(?:\n|$)/m', '', $sql) ?? '';
foreach (explode(';', $sql) as $statement) {
    if (trim($statement) !== '') {
        $db->execute($statement);
    }
}

try {
    $traffic = new TaskApiTrafficService($db);
    $minute = strtotime('2026-10-22 11:00:00 UTC');
    for ($i = 0; $i < TaskApiTrafficService::WRITE_PER_MINUTE; ++$i) {
        trafficCheck($traffic->consume(31, 'create', $minute)['allowed'], 'запрос отклонён до квоты');
    }
    $blocked = $traffic->consume(31, 'update', $minute + 12);
    trafficCheck(!$blocked['allowed'] && $blocked['retry_after'] === 48,
        'квота записи не блокирует 31-й запрос или неверен Retry-After');
    trafficCheck($traffic->consume(32, 'create', $minute)['allowed'], 'лимиты разных ключей смешаны');
    trafficCheck($traffic->consume(31, 'create', $minute + 60)['allowed'],
        'лимит не сброшен в следующем минутном окне');

    for ($i = 0; $i < TaskApiTrafficService::READ_PER_MINUTE; ++$i) {
        $result = $traffic->consume(32, 'read', $minute + 120);
        trafficCheck($result['allowed'], 'неверный предел чтения');
    }
    trafficCheck(!$traffic->consume(32, 'read', $minute + 120)['allowed'],
        'чтение выше квоты не заблокировано');

    $traffic->record(31, 11, 'create', 201);
    $traffic->record(31, 11, 'update', 409);
    $traffic->record(32, 12, 'read', 429);
    $owned = $traffic->recentForOwner(11);
    trafficCheck(count($owned) === 2 && $owned[0]['status_code'] == 409,
        'журнал не упорядочен');
    trafficCheck(!array_key_exists('token_hash', $owned[0])
        && !array_key_exists('authorization', $owned[0]), 'секрет попал в журнал');
    trafficCheck(count($traffic->recentForOwner(12)) === 1, 'нарушена изоляция журнала');
    trafficCheck($db->fetchValue('SELECT COUNT(*) FROM task_api_access_log') == 3,
        'записи журнала потеряны');

    // Испытание реального задания общей фоновой очистки и ограничения размера пачки.
    $oldWindow = gmdate('Y-m-d H:i:s', time() - 4 * 86400);
    $recentWindow = gmdate('Y-m-d H:i:s', time() - 60);
    foreach ([31, 32] as $token) {
        $db->execute(
            'INSERT INTO task_api_rate_buckets(token_id,window_start,requests) VALUES(:id,:window,1)',
            [':id' => $token, ':window' => $oldWindow]
        );
        $db->execute(
            'INSERT INTO task_api_rate_buckets(token_id,window_start,requests) VALUES(:id,:window,1)',
            [':id' => $token, ':window' => $recentWindow]
        );
    }
    $db->execute(
        'UPDATE task_api_access_log SET created_at=:old WHERE token_id=31',
        [':old' => gmdate('Y-m-d H:i:s', time() - 40 * 86400)]
    );
    $cleanup = new TasksRetentionService();
    $context = new RetentionCleanupContext(
        $db, new RetentionFileCleanup(dirname(__DIR__, 2), sys_get_temp_dir())
    );
    $cutoff = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
    $preview = $cleanup->preview($cutoff, 1, $context);
    trafficCheck(($preview['task_api_rate_buckets'] ?? 0) === 1,
        'предпросмотр счётчиков не ограничен размером пачки');
    trafficCheck(($preview['task_api_access_log'] ?? 0) === 1,
        'предпросмотр записей журнала неверен');
    $cleanupResult = ['purged' => []];
    $cleanup->apply($cutoff, 1, $context, $cleanupResult);
    trafficCheck(($cleanupResult['purged']['task_api_rate_buckets'] ?? 0) === 1,
        'фоновая очистка не удалила одно устаревшее окно');
    trafficCheck(($cleanupResult['purged']['task_api_access_log'] ?? 0) === 1,
        'фоновая очистка не удалила одну устаревшую запись');
    trafficCheck((int) $db->fetchValue(
        'SELECT COUNT(*) FROM task_api_rate_buckets WHERE window_start=:recent',
        [':recent' => $recentWindow]
    ) === 2, 'актуальные счётчики были удалены');
    $cleanup->apply($cutoff, 20, $context, $cleanupResult);
    trafficCheck((int) $db->fetchValue(
        'SELECT COUNT(*) FROM task_api_rate_buckets WHERE window_start=:old',
        [':old' => $oldWindow]
    ) === 0, 'старые окна не удалены при повторной очистке');

    echo "Квоты и журнал Tasks API: раздельные ключи, пределы, окна, 429 и владельцы проверены\n";
} finally {
    $db->execute('DROP TABLE IF EXISTS task_api_access_log');
    $db->execute('DROP TABLE IF EXISTS task_api_rate_buckets');
    $db->execute('DROP TABLE IF EXISTS task_api_tokens');
    $db->execute('DROP TABLE IF EXISTS users');
}
