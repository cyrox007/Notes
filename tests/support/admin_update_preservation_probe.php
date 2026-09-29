<?php

declare(strict_types=1);

/**
 * Контроль сохранности пользовательских данных для сквозного updater-drill.
 *
 * Использование:
 *   php tests/support/admin_update_preservation_probe.php --seed
 *   php tests/support/admin_update_preservation_probe.php --verify
 */

function preservationFail(string $message): never
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function preservationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        preservationFail($message);
    }
}

$options = getopt('', ['seed', 'verify']);
$mode = isset($options['seed']) ? 'seed' : (isset($options['verify']) ? 'verify' : '');
if ($mode === '') {
    preservationFail('Укажите --seed или --verify');
}

$dbHost = trim((string) getenv('DBHOST'));
$dbPort = trim((string) getenv('DBPORT'));
$dbUser = trim((string) getenv('DBUSER'));
$dbPass = (string) getenv('DBPASS');
$dbName = trim((string) getenv('DBNAME'));
$username = trim((string) getenv('E2E_USER'));
$privateRoot = rtrim(trim((string) getenv('PRIVATE_STORAGE_PATH')), "/\\");

foreach ([
    'DBHOST' => $dbHost,
    'DBPORT' => $dbPort,
    'DBUSER' => $dbUser,
    'DBNAME' => $dbName,
    'E2E_USER' => $username,
    'PRIVATE_STORAGE_PATH' => $privateRoot,
] as $label => $value) {
    preservationAssert($value !== '', "Не задана переменная {$label}");
}

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $dbHost,
        $dbPort,
        $dbName
    ),
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
$stmt->execute([':username' => $username]);
$userId = (int) $stmt->fetchColumn();
preservationAssert($userId > 0, 'Не найден контрольный пользователь');

$noteUid = 'e2e-preserve-note-00000000000000000000000000000001';
$noteTitle = 'E2E сохранность заметки';
$noteContent = 'sentinel-note-content-1.0.9';

$taskUid = 'e2epreservetask000000000000000001';
$taskTitle = 'E2E сохранность задачи';
$taskDescription = 'sentinel-task-description-1.0.9';

$fileUid = 'e2e-preserve-file-00000000000000000000000000000001';
$fileName = 'e2e-preserve.txt';
$fileRelativePath = 'file_manager/e2e-preserve.txt';
$fileBytes = "Workspace Organizer updater preservation sentinel 1.0.9\n";

$dialogUid = 'e2e-preserve-dialog-0000-4000-8000-000000000001';
$messageUid = 'e2e-preserve-msg-000000-4000-8000-000000000001';
$messageText = 'sentinel-messenger-message-1.0.9';

if ($mode === 'seed') {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO notes (uid,user_id,notename,content,content_type,is_encrypted,is_deleted) '
            . 'VALUES (:uid,:user_id,:name,:content,\'text\',0,0)'
        );
        $stmt->execute([
            ':uid' => $noteUid,
            ':user_id' => $userId,
            ':name' => $noteTitle,
            ':content' => $noteContent,
        ]);

        $stmt = $pdo->prepare(
            'INSERT INTO tasks (uid,user_id,title,description,status,priority,is_deleted) '
            . 'VALUES (:uid,:user_id,:title,:description,\'in_progress\',\'high\',0)'
        );
        $stmt->execute([
            ':uid' => $taskUid,
            ':user_id' => $userId,
            ':title' => $taskTitle,
            ':description' => $taskDescription,
        ]);

        $stmt = $pdo->prepare(
            'INSERT INTO user_files (uid,user_id,name,type,mime_type,size,path,extension,is_deleted) '
            . 'VALUES (:uid,:user_id,:name,\'file\',\'text/plain\',:size,:path,\'txt\',0)'
        );
        $stmt->execute([
            ':uid' => $fileUid,
            ':user_id' => $userId,
            ':name' => $fileName,
            ':size' => strlen($fileBytes),
            ':path' => $fileRelativePath,
        ]);

        $stmt = $pdo->prepare(
            'INSERT INTO dialogs (uid,type,name,created_by) '
            . 'VALUES (:uid,\'saved\',:name,:created_by)'
        );
        $stmt->execute([
            ':uid' => $dialogUid,
            ':name' => 'E2E сохранность Messenger',
            ':created_by' => $userId,
        ]);
        $dialogId = (int) $pdo->lastInsertId();
        preservationAssert($dialogId > 0, 'Не удалось создать контрольный диалог');

        $stmt = $pdo->prepare(
            'INSERT INTO user_to_dialogs (dialog_id,user_id,role,is_deleted) '
            . 'VALUES (:dialog_id,:user_id,\'owner\',0)'
        );
        $stmt->execute([
            ':dialog_id' => $dialogId,
            ':user_id' => $userId,
        ]);

        $stmt = $pdo->prepare(
            'INSERT INTO messages '
            . '(uid,dialog_id,from_user_id,message,message_type,message_status,is_deleted) '
            . 'VALUES (:uid,:dialog_id,:from_user_id,:message,\'text\',\'sent\',0)'
        );
        $stmt->execute([
            ':uid' => $messageUid,
            ':dialog_id' => $dialogId,
            ':from_user_id' => $userId,
            ':message' => $messageText,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $filePath = $privateRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fileRelativePath);
    $directory = dirname($filePath);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        preservationFail('Не удалось создать каталог контрольного файла');
    }
    preservationAssert(
        file_put_contents($filePath, $fileBytes, LOCK_EX) === strlen($fileBytes),
        'Не удалось записать контрольный файл'
    );

    fwrite(STDOUT, "[OK] контрольные пользовательские данные 1.0.9 созданы\n");
}

$scalar = static function (PDO $pdo, string $sql, array $params): mixed {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
};

preservationAssert(
    $scalar(
        $pdo,
        'SELECT COUNT(*) FROM notes WHERE uid=:uid AND user_id=:user_id AND notename=:title '
            . 'AND content=:content AND is_deleted=0',
        [':uid' => $noteUid, ':user_id' => $userId, ':title' => $noteTitle, ':content' => $noteContent]
    ) === 1,
    'Контрольная заметка изменена или потеряна'
);

preservationAssert(
    $scalar(
        $pdo,
        'SELECT COUNT(*) FROM tasks WHERE uid=:uid AND user_id=:user_id AND title=:title '
            . 'AND description=:description AND status=\'in_progress\' AND priority=\'high\' AND is_deleted=0',
        [':uid' => $taskUid, ':user_id' => $userId, ':title' => $taskTitle, ':description' => $taskDescription]
    ) === 1,
    'Контрольная задача изменена или потеряна'
);

preservationAssert(
    $scalar(
        $pdo,
        'SELECT COUNT(*) FROM user_files WHERE uid=:uid AND user_id=:user_id AND name=:name '
            . 'AND path=:path AND is_deleted=0',
        [':uid' => $fileUid, ':user_id' => $userId, ':name' => $fileName, ':path' => $fileRelativePath]
    ) === 1,
    'Метаданные контрольного файла изменены или потеряны'
);

preservationAssert(
    $scalar(
        $pdo,
        'SELECT COUNT(*) FROM messages m '
            . 'INNER JOIN dialogs d ON d.id=m.dialog_id '
            . 'INNER JOIN user_to_dialogs utd ON utd.dialog_id=d.id '
            . 'WHERE m.uid=:message_uid AND d.uid=:dialog_uid AND utd.user_id=:user_id '
            . 'AND m.message=:message AND m.is_deleted=0 AND utd.is_deleted=0',
        [
            ':message_uid' => $messageUid,
            ':dialog_uid' => $dialogUid,
            ':user_id' => $userId,
            ':message' => $messageText,
        ]
    ) === 1,
    'Контрольное сообщение Messenger изменено или потеряно'
);

$filePath = $privateRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fileRelativePath);
preservationAssert(is_file($filePath), 'Контрольный файл отсутствует в private storage');
preservationAssert(
    hash_file('sha256', $filePath) === hash('sha256', $fileBytes),
    'Байты контрольного файла изменились'
);

fwrite(STDOUT, "[OK] пользовательские данные и private storage сохранены\n");
