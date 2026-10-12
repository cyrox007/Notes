<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);
require_once $root . '/core/Config.php';
require_once $root . '/modules/messenger/runtime.php';

use App\Helpers\CryptMethods;
use App\Services\MessengerSavedService;
use App\Services\MessengerService;
use Core\DatabaseManager;

$options = getopt('', [
    'confirm:',
    'prefix:',
    'max-users:',
    'notes-per-user:',
    'tasks-per-user:',
    'files-per-user:',
    'messages-per-user:',
    'calendar-per-user:',
    'notifications-per-user:',
    'help',
]);

if (isset($options['help'])) {
    echo "Использование:\n";
    echo "  php tools/performance/prepare-dataset.php --confirm=WORKSPACE_PERFORMANCE_ONLY [параметры]\n\n";
    echo "Команда наполняет только синтетических пользователей с perf-префиксом.\n";
    echo "По умолчанию: 1200 пользователей, 12 заметок, 18 задач, 12 файлов, 12 сообщений, 8 событий Calendar и 8 уведомлений на пользователя.\n";
    echo "Запускать только на отдельном нагрузочном стенде.\n";
    exit(0);
}

$confirm = trim((string) ($options['confirm'] ?? getenv('PERF_FIXTURE_CONFIRM') ?: ''));
$prefix = trim((string) ($options['prefix'] ?? 'perf_11_'));
$maxUsers = positiveOption($options, 'max-users', 1200, 5000);
$targets = [
    'notes' => positiveOption($options, 'notes-per-user', 12, 200),
    'tasks' => positiveOption($options, 'tasks-per-user', 18, 300),
    'files' => positiveOption($options, 'files-per-user', 12, 200),
    'messages' => positiveOption($options, 'messages-per-user', 12, 200),
    'calendar' => positiveOption($options, 'calendar-per-user', 8, 100),
    'notifications' => positiveOption($options, 'notifications-per-user', 8, 100),
];

try {
    if ($confirm !== 'WORKSPACE_PERFORMANCE_ONLY') {
        throw new RuntimeException('Нужно явно подтвердить отдельное нагрузочное окружение: --confirm=WORKSPACE_PERFORMANCE_ONLY');
    }
    if (preg_match('/^perf_[a-z0-9_]{1,24}$/D', $prefix) !== 1) {
        throw new InvalidArgumentException('Префикс обязан начинаться с perf_ и содержать только строчные латинские буквы, цифры и подчёркивания');
    }

    $db = DatabaseManager::getInstance();
    foreach ([
        'users', 'notes', 'tasks', 'user_files', 'dialogs', 'user_to_dialogs', 'messages',
        'calendar_events', 'notification_events', 'notifications',
    ] as $table) {
        requireTable($db, $table);
    }

    $users = $db->fetchAll(
        'SELECT id, uid, username, property FROM users '
        . 'WHERE LEFT(username, :prefix_length) = :prefix AND is_active = 1 '
        . 'ORDER BY id ASC LIMIT ' . $maxUsers,
        [':prefix_length' => strlen($prefix), ':prefix' => $prefix]
    );
    if ($users === []) {
        throw new RuntimeException('Сначала создайте синтетических пользователей через prepare-users.php');
    }

    foreach ($users as $user) {
        $property = json_decode((string) ($user['property'] ?? ''), true);
        if (!is_array($property) || ($property['performance_fixture'] ?? false) !== true) {
            throw new RuntimeException('Найден пользователь с perf-префиксом без признака performance_fixture: ' . (string) $user['username']);
        }
    }

    $stats = [
        'users' => count($users),
        'notes' => 0,
        'tasks' => 0,
        'files' => 0,
        'messages' => 0,
        'calendar' => 0,
        'notifications' => 0,
    ];
    $savedService = new MessengerSavedService($db);
    $messenger = new MessengerService($db);

    foreach ($users as $userIndex => $user) {
        $userId = (int) $user['id'];
        $userUid = (string) $user['uid'];
        $username = (string) $user['username'];

        $db->beginTransaction();
        try {
            $stats['notes'] += ensureNotes($db, $userId, $userUid, $username, $targets['notes']);
            $stats['tasks'] += ensureTasks($db, $userId, $userUid, $username, $targets['tasks']);
            $stats['files'] += ensureFiles($db, $userId, $userUid, $username, $targets['files']);
            $stats['calendar'] += ensureCalendar($db, $userId, $userUid, $username, $targets['calendar'], $userIndex);
            $stats['notifications'] += ensureNotifications($db, $userId, $userUid, $username, $targets['notifications']);
            $db->endTransaction(true);
        } catch (Throwable $e) {
            $db->endTransaction(false);
            throw $e;
        }

        $dialog = $savedService->getOrCreate($userUid);
        $dialogUid = (string) ($dialog['uid'] ?? '');
        if ($dialogUid === '') {
            throw new RuntimeException('Не удалось получить saved-диалог для ' . $username);
        }
        $existingMessages = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM messages m INNER JOIN dialogs d ON d.id = m.dialog_id '
            . 'WHERE d.uid = :dialog_uid AND m.from_user_id = :user_id AND m.is_deleted = 0',
            [':dialog_uid' => $dialogUid, ':user_id' => $userId]
        );
        for ($index = $existingMessages + 1; $index <= $targets['messages']; $index++) {
            $messenger->sendMessage(
                $userUid,
                $dialogUid,
                'Нагрузочное сообщение ' . $index . ' пользователя ' . $username . '. Текст предназначен только для эталонного performance-стенда.'
            );
            $stats['messages']++;
        }
    }

    echo "Репрезентативный набор данных подготовлен.\n";
    echo 'Пользователей: ' . $stats['users'] . PHP_EOL;
    echo 'Добавлено заметок: ' . $stats['notes'] . PHP_EOL;
    echo 'Добавлено задач: ' . $stats['tasks'] . PHP_EOL;
    echo 'Добавлено файловых записей: ' . $stats['files'] . PHP_EOL;
    echo 'Добавлено сообщений: ' . $stats['messages'] . PHP_EOL;
    echo 'Добавлено событий Calendar: ' . $stats['calendar'] . PHP_EOL;
    echo 'Добавлено уведомлений: ' . $stats['notifications'] . PHP_EOL;
    echo "Повторный запуск безопасен: уже существующие детерминированные записи не дублируются.\n";
    DatabaseManager::resetInstance();
    exit(0);
} catch (Throwable $e) {
    DatabaseManager::resetInstance();
    fwrite(STDERR, 'Ошибка подготовки нагрузочного набора данных: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/** @param array<string,mixed> $options */
function positiveOption(array $options, string $name, int $default, int $max): int
{
    $value = isset($options[$name]) ? (int) $options[$name] : $default;
    if ($value < 1 || $value > $max) {
        throw new InvalidArgumentException($name . ' должен быть в диапазоне 1..' . $max);
    }
    return $value;
}

function requireTable(DatabaseManager $db, string $table): void
{
    $exists = (int) $db->fetchValue(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table',
        [':table' => $table]
    );
    if ($exists !== 1) {
        throw new RuntimeException('На стенде отсутствует обязательная таблица: ' . $table);
    }
}

function stableHex(string $seed, int $length): string
{
    return substr(hash('sha256', $seed), 0, $length);
}

function stableUuid(string $seed): string
{
    $hex = hash('sha256', $seed);
    return substr($hex, 0, 8) . '-'
        . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-8' . substr($hex, 17, 3) . '-'
        . substr($hex, 20, 12);
}

function ensureNotes(DatabaseManager $db, int $userId, string $userUid, string $username, int $target): int
{
    $created = 0;
    for ($index = 1; $index <= $target; $index++) {
        $uid = stableHex('performance:note:' . $userUid . ':' . $index, 32);
        if ((int) $db->fetchValue('SELECT COUNT(*) FROM notes WHERE uid = :uid', [':uid' => $uid]) > 0) {
            continue;
        }
        $plain = 'Нагрузочная заметка ' . $index . ' пользователя ' . $username . '. '
            . str_repeat('Репрезентативный текст для измерения списков, поиска и хранения. ', 24);
        $db->execute(
            'INSERT INTO notes (uid,user_id,notename,content,content_type,is_encrypted,created_note,updated_note,is_deleted,is_profile_public) '
            . "VALUES (:uid,:user_id,:name,:content,'text',1,:created_at,:updated_at,0,0)",
            [
                ':uid' => $uid,
                ':user_id' => $userId,
                ':name' => 'Performance заметка ' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                ':content' => CryptMethods::encrypt($plain, $uid),
                ':created_at' => date('Y-m-d H:i:s', time() - ($index * 3600)),
                ':updated_at' => date('Y-m-d H:i:s', time() - ($index * 120)),
            ]
        );
        $created++;
    }
    return $created;
}

function ensureTasks(DatabaseManager $db, int $userId, string $userUid, string $username, int $target): int
{
    $created = 0;
    $statuses = ['pending', 'studying', 'in_progress', 'review', 'completed'];
    $priorities = ['low', 'medium', 'high', 'urgent'];
    for ($index = 1; $index <= $target; $index++) {
        $uid = stableHex('performance:task:' . $userUid . ':' . $index, 32);
        if ((int) $db->fetchValue('SELECT COUNT(*) FROM tasks WHERE uid = :uid', [':uid' => $uid]) > 0) {
            continue;
        }
        $status = $statuses[($index - 1) % count($statuses)];
        $completedAt = $status === 'completed' ? date('Y-m-d H:i:s', time() - 3600) : null;
        $db->execute(
            'INSERT INTO tasks (uid,user_id,title,description,status,priority,due_date,completed_at,is_deleted,is_profile_public,created_at,updated_at) '
            . 'VALUES (:uid,:user_id,:title,:description,:status,:priority,:due_date,:completed_at,0,0,:created_at,:updated_at)',
            [
                ':uid' => $uid,
                ':user_id' => $userId,
                ':title' => 'Performance задача ' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                ':description' => 'Синтетическая задача пользователя ' . $username . ' для нагрузочного стенда.',
                ':status' => $status,
                ':priority' => $priorities[($index - 1) % count($priorities)],
                ':due_date' => date('Y-m-d H:i:s', time() + (($index - 5) * 86400)),
                ':completed_at' => $completedAt,
                ':created_at' => date('Y-m-d H:i:s', time() - ($index * 1800)),
                ':updated_at' => date('Y-m-d H:i:s', time() - ($index * 90)),
            ]
        );
        $created++;
    }
    return $created;
}

function ensureFiles(DatabaseManager $db, int $userId, string $userUid, string $username, int $target): int
{
    $created = 0;
    for ($index = 1; $index <= $target; $index++) {
        $uid = stableHex('performance:file:' . $userUid . ':' . $index, 40);
        if ((int) $db->fetchValue('SELECT COUNT(*) FROM user_files WHERE uid = :uid', [':uid' => $uid]) > 0) {
            continue;
        }
        $folder = $index % 6 === 0;
        $db->execute(
            'INSERT INTO user_files (uid,user_id,parent_id,name,type,mime_type,size,path,extension,is_deleted,is_profile_public,created_at,updated_at) '
            . 'VALUES (:uid,:user_id,NULL,:name,:type,:mime_type,:size,NULL,:extension,0,0,:created_at,:updated_at)',
            [
                ':uid' => $uid,
                ':user_id' => $userId,
                ':name' => $folder
                    ? 'Performance папка ' . str_pad((string) $index, 3, '0', STR_PAD_LEFT)
                    : 'performance-' . $username . '-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT) . '.txt',
                ':type' => $folder ? 'folder' : 'document',
                ':mime_type' => $folder ? null : 'text/plain',
                ':size' => $folder ? 0 : 4096 + ($index * 317),
                ':extension' => $folder ? null : 'txt',
                ':created_at' => date('Y-m-d H:i:s', time() - ($index * 2400)),
                ':updated_at' => date('Y-m-d H:i:s', time() - ($index * 150)),
            ]
        );
        $created++;
    }
    return $created;
}

function ensureCalendar(
    DatabaseManager $db,
    int $userId,
    string $userUid,
    string $username,
    int $target,
    int $userIndex
): int {
    $created = 0;
    for ($index = 1; $index <= $target; $index++) {
        $uid = stableUuid('performance:calendar:' . $userUid . ':' . $index);
        if ((int) $db->fetchValue('SELECT COUNT(*) FROM calendar_events WHERE uid = :uid', [':uid' => $uid]) > 0) {
            continue;
        }
        $start = time() + ((($index - 4) * 86400) + (($userIndex % 8) * 900));
        $db->execute(
            'INSERT INTO calendar_events '
            . '(uid,owner_user_id,title,description,location,is_all_day,timezone,starts_at_utc,ends_at_utc,recurrence_rule,status) '
            . "VALUES (:uid,:user_id,:title,:description,:location,0,'UTC',:starts_at,:ends_at,NULL,'active')",
            [
                ':uid' => $uid,
                ':user_id' => $userId,
                ':title' => 'Performance событие ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                ':description' => 'Синтетическое календарное событие пользователя ' . $username . '.',
                ':location' => 'Нагрузочный стенд',
                ':starts_at' => gmdate('Y-m-d H:i:s', $start),
                ':ends_at' => gmdate('Y-m-d H:i:s', $start + 3600),
            ]
        );
        $created++;
    }
    return $created;
}

function ensureNotifications(DatabaseManager $db, int $userId, string $userUid, string $username, int $target): int
{
    $created = 0;
    for ($index = 1; $index <= $target; $index++) {
        $key = 'performance:' . stableHex($userUid . ':' . $index, 32);
        $event = $db->fetchOne(
            'SELECT id FROM notification_events WHERE event_name = :event_name AND idempotency_key = :key LIMIT 1',
            [':event_name' => 'performance.fixture', ':key' => $key]
        );
        if (!$event) {
            $db->execute(
                'INSERT INTO notification_events '
                . '(event_name,event_version,idempotency_key,source_name,category,importance,occurred_at,payload_json) '
                . "VALUES ('performance.fixture',1,:key,'performance','system',:importance,:occurred_at,:payload)",
                [
                    ':key' => $key,
                    ':importance' => $index % 7 === 0 ? 'high' : 'normal',
                    ':occurred_at' => date('Y-m-d H:i:s', time() - ($index * 600)),
                    ':payload' => json_encode(['fixture' => true, 'index' => $index], JSON_THROW_ON_ERROR),
                ]
            );
            $eventId = (int) $db->getPdo()->lastInsertId();
        } else {
            $eventId = (int) $event['id'];
        }
        $exists = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM notifications WHERE event_id = :event_id AND recipient_user_id = :user_id',
            [':event_id' => $eventId, ':user_id' => $userId]
        );
        if ($exists > 0) {
            continue;
        }
        $db->execute(
            'INSERT INTO notifications (event_id,recipient_user_id,title,body,target_path,read_at,created_at) '
            . 'VALUES (:event_id,:user_id,:title,:body,:target_path,:read_at,:created_at)',
            [
                ':event_id' => $eventId,
                ':user_id' => $userId,
                ':title' => 'Performance уведомление ' . $index,
                ':body' => 'Синтетическое уведомление пользователя ' . $username . ' для измерения центра уведомлений.',
                ':target_path' => '/calendar/',
                ':read_at' => $index % 3 === 0 ? date('Y-m-d H:i:s', time() - 60) : null,
                ':created_at' => date('Y-m-d H:i:s', time() - ($index * 600)),
            ]
        );
        $created++;
    }
    return $created;
}
