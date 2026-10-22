<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/modules/tasks/services/TaskApiTokenService.php';

use Core\DatabaseManager;
use Modules\Tasks\TaskApiTokenService;

function apiTokenCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('Ключи Tasks: ' . $message);
    }
}

$connection = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$connection->exec('CREATE TABLE task_api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    scope TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    revoked_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TEXT
)');
$db = DatabaseManager::forConnection($connection);
$service = new TaskApiTokenService($db);

$created = $service->issue(11, 'Мобильный клиент', 30);
apiTokenCheck(preg_match('/^ntk1_[0-9a-f]{64}$/D', $created['token']) === 1,
    'секрет слишком короткий или неслучайный');
apiTokenCheck($created['scope'] === 'tasks.read', 'выдана лишняя область доступа');
$row = $db->fetchOne('SELECT * FROM task_api_tokens WHERE id=:id', [':id' => $created['id']]);
apiTokenCheck(is_array($row) && $row['token_hash'] === hash('sha256', $created['token']),
    'в БД не сохранён отпечаток');
apiTokenCheck(!str_contains(json_encode($row, JSON_THROW_ON_ERROR), $created['token']),
    'секрет присутствует в таблице');

$auth = 'Bearer ' . $created['token'];
apiTokenCheck($service->authenticate($auth) === ['user_id' => 11, 'scope' => 'tasks.read'],
    'не удалось проверить действительный ключ');
apiTokenCheck($service->authenticate($auth, 'tasks.write') === null,
    'ключ чтения получил право изменять задачи');
apiTokenCheck($service->authenticate('Bearer ntk1_' . str_repeat('0', 64)) === null,
    'принят посторонний секрет');
apiTokenCheck($service->authenticate('Basic ' . $created['token']) === null,
    'принята неправильная схема авторизации');

apiTokenCheck($service->revoke(12, $created['id']) === false,
    'чужой пользователь отозвал ключ');
apiTokenCheck($service->listOwned(12) === [], 'чужой ключ появился в списке');
apiTokenCheck($service->revoke(11, $created['id']) === true,
    'владелец не может отозвать ключ');
apiTokenCheck($service->authenticate($auth) === null,
    'отозванный ключ продолжает работать');

$write = $service->issue(11, 'Интеграция', 1, TaskApiTokenService::SCOPE_WRITE);
apiTokenCheck($service->authenticate('Bearer ' . $write['token'], TaskApiTokenService::SCOPE_WRITE) !== null,
    'ключ записи не работает');
apiTokenCheck($service->authenticate('Bearer ' . $write['token']) !== null,
    'ключ записи не может читать');
apiTokenCheck($service->authenticate($auth, TaskApiTokenService::SCOPE_WRITE) === null,
    'отозванный ключ чтения получил запись');
$readAgain = $service->issue(11, 'Только чтение', 1);
apiTokenCheck($service->authenticate('Bearer ' . $readAgain['token'], TaskApiTokenService::SCOPE_WRITE) === null,
    'ключ чтения позволяет запись');
$expired = $service->issue(11, 'Просроченный', 1);
$db->execute('UPDATE task_api_tokens SET expires_at=:at WHERE id=:id', [
    ':at' => '2020-01-01 00:00:00',
    ':id' => $expired['id'],
]);
apiTokenCheck($service->authenticate('Bearer ' . $expired['token']) === null,
    'просроченный ключ работает');
apiTokenCheck(count($service->listOwned(11)) === 4, 'неполная история личных ключей');

$rejected = 0;
foreach ([0, 91] as $days) {
    try {
        $service->issue(11, 'Проверка', $days);
    } catch (InvalidArgumentException) {
        ++$rejected;
    }
}
apiTokenCheck($rejected === 2, 'не ограничен срок существования ключа');

echo "Ключи API Tasks: выпуск, секрет, область, изоляция, отзыв и срок действия проверены\n";
