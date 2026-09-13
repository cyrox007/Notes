<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
session_start();

$basePath = __DIR__;
$envFile = $basePath . '/.env';

if (is_file($envFile) && empty($_SESSION['notes_install_in_progress'])) {
    http_response_code(404);
    exit('Installer is locked.');
}

$_SESSION['notes_install_in_progress'] = true;
$_SESSION['notes_install_csrf'] ??= bin2hex(random_bytes(32));

$step = isset($_GET['step']) ? max(1, min(4, (int) $_GET['step'])) : 1;
$errors = [];
$successMessage = '';
$installationCompleted = false;

$requiredTables = [
    'users',
    'dialogs',
    'user_to_dialogs',
    'messages',
    'message_user_deletions',
    'notes',
    'note_attachments',
    'shared_notes',
    'note_history',
    'note_tags',
    'note_tag_relations',
    'user_files',
    'user_fields',
];

$schemaFiles = glob($basePath . '/database/*.sql') ?: [];
usort($schemaFiles, static function (string $a, string $b): int {
    $order = [
        'messenger_schema.sql' => 1,
        'notes_schema.sql' => 2,
        'file_manager_schema.sql' => 3,
        'user_fields_schema.sql' => 4,
    ];

    return ($order[basename($a)] ?? 99) <=> ($order[basename($b)] ?? 99);
});

function uuidV4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function randomSecret(): string
{
    return bin2hex(random_bytes(32));
}

function verifyInstallerCsrf(): void
{
    $expected = (string) ($_SESSION['notes_install_csrf'] ?? '');
    $provided = (string) ($_POST['csrf_token'] ?? '');

    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(419);
        exit('Invalid installer CSRF token.');
    }
}

function connectDatabase(string $host, int $port, string $database, string $username, string $password): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $database
    );

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
    ]);
}

/** @return list<string> */
function existingTables(PDO $pdo): array
{
    return array_map(
        static fn ($table): string => trim((string) $table, '`'),
        $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)
    );
}

/**
 * Fresh-install schema loader. DELIMITER lines belong to the CLI client and are
 * removed before mysqli::multi_query sends statements to MySQL.
 */
function importSchemas(
    string $host,
    int $port,
    string $database,
    string $username,
    string $password,
    array $files
): void {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $mysqli = new mysqli($host, $username, $password, $database, $port);
    $mysqli->set_charset('utf8mb4');
    $mysqli->query('SET FOREIGN_KEY_CHECKS=0');

    try {
        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Не удалось прочитать схему: ' . basename($file));
            }

            $sql = preg_replace('/^\s*DELIMITER\s+\S+\s*;?\s*$/im', '', $sql) ?? $sql;
            $sql = str_replace('$$', ';', $sql);

            $mysqli->multi_query($sql);
            do {
                if ($result = $mysqli->store_result()) {
                    $result->free();
                }
            } while ($mysqli->more_results() && $mysqli->next_result());
        }
    } finally {
        $mysqli->query('SET FOREIGN_KEY_CHECKS=1');
        $mysqli->close();
    }
}

function createAdminUser(
    PDO $pdo,
    string $username,
    string $email,
    string $password,
    string $firstname,
    string $lastname
): void {
    $passwordHash = password_hash($password, PASSWORD_ARGON2ID);
    if ($passwordHash === false) {
        throw new RuntimeException('Не удалось создать хеш пароля');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users (
            uid, username, email, password_hash, firstname, lastname,
            role, is_active, created_at, updated_at
         ) VALUES (
            :uid, :username, :email, :password_hash, :firstname, :lastname,
            1, 1, :created_at, :updated_at
         )'
    );

    $now = date('Y-m-d H:i:s');
    $stmt->execute([
        ':uid' => uuidV4(),
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => $passwordHash,
        ':firstname' => $firstname,
        ':lastname' => $lastname,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function writeEnvironmentFile(string $file, string $basePath, array $data): void
{
    $privateStorage = dirname($basePath) . '/notes-private-storage';
    $siteUrl = 'http://localhost';
    $wsUrl = 'ws://localhost:27800';

    $content = <<<ENV
# Generated by Notes installer
DBDRIVER=mysql
DBHOST={$data['db_host']}
DBPORT={$data['db_port']}
DBUSER={$data['db_user']}
DBPASS={$data['db_pass']}
DBNAME={$data['db_name']}

UNIQUE_KEY={$data['unique_key']}
MSG_SECRET_KEY={$data['message_key']}
WS_TICKET_SECRET={$data['ws_ticket_secret']}

PRIVATE_STORAGE_PATH={$privateStorage}
UPLOAD_DIR={$basePath}/uploads/file_manager
NOTES_UPLOAD_DIR={$basePath}/uploads/notes
MESSENGER_UPLOAD_DIR={$basePath}/uploads/messenger
MAX_UPLOAD_SIZE=10485760
MAX_NOTE_ATTACHMENTS=10

SITEURL={$siteUrl}
BASE_PATH=/

WS_HOST=0.0.0.0
WS_PORT=27800
WS_PUBLIC_URL={$wsUrl}
WS_ALLOWED_ORIGINS={$siteUrl}

LOG_LEVEL=DEBUG
LOG_FILE={$basePath}/.logs/app.log
SESSION_LIFETIME=3600
MAX_LOGIN_ATTEMPTS=5
CSRF_ENABLED=true
INSTALL_DATE={$data['install_date']}
ENV;

    if (file_put_contents($file, $content, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать .env');
    }

    @chmod($file, 0600);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyInstallerCsrf();
    $postedStep = (int) ($_POST['step'] ?? 0);

    if ($postedStep === 2) {
        $host = trim((string) ($_POST['db_host'] ?? 'localhost'));
        $port = (int) ($_POST['db_port'] ?? 3306);
        $database = trim((string) ($_POST['db_name'] ?? ''));
        $username = trim((string) ($_POST['db_user'] ?? ''));
        $password = (string) ($_POST['db_pass'] ?? '');

        if ($host === '' || $database === '' || $username === '' || $port < 1 || $port > 65535) {
            $errors[] = 'Некорректные параметры базы данных.';
        } else {
            try {
                $pdo = connectDatabase($host, $port, $database, $username, $password);
                $missing = array_diff($requiredTables, existingTables($pdo));

                if ($missing !== []) {
                    if ($schemaFiles === []) {
                        throw new RuntimeException('Файлы database/*.sql не найдены.');
                    }
                    importSchemas($host, $port, $database, $username, $password, $schemaFiles);
                }

                $remaining = array_diff($requiredTables, existingTables($pdo));
                if ($remaining !== []) {
                    throw new RuntimeException(
                        'После импорта отсутствуют таблицы: ' . implode(', ', $remaining)
                    );
                }

                $envData = [
                    'db_host' => $host,
                    'db_port' => $port,
                    'db_name' => $database,
                    'db_user' => $username,
                    'db_pass' => $password,
                    'unique_key' => randomSecret(),
                    'message_key' => randomSecret(),
                    'ws_ticket_secret' => randomSecret(),
                    'install_date' => date('YmdHis'),
                ];

                writeEnvironmentFile($envFile, $basePath, $envData);
                $_SESSION['notes_install_db'] = $envData;
                header('Location: install.php?step=3');
                exit;
            } catch (Throwable $e) {
                error_log('Installer database step failed: ' . $e->getMessage());
                $errors[] = 'Не удалось подготовить базу данных: ' . $e->getMessage();
            }
        }
        $step = 2;
    } elseif ($postedStep === 3) {
        $username = trim((string) ($_POST['admin_username'] ?? ''));
        $email = trim((string) ($_POST['admin_email'] ?? ''));
        $password = (string) ($_POST['admin_password'] ?? '');
        $confirmation = (string) ($_POST['admin_password_confirm'] ?? '');
        $firstname = trim((string) ($_POST['admin_firstname'] ?? 'Admin'));
        $lastname = trim((string) ($_POST['admin_lastname'] ?? 'User'));

        if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $firstname === '' || $lastname === '') {
            $errors[] = 'Заполните данные администратора корректно.';
        }
        if (strlen($password) < 10) {
            $errors[] = 'Пароль должен содержать не менее 10 символов.';
        }
        if ($password !== $confirmation) {
            $errors[] = 'Пароли не совпадают.';
        }

        $db = $_SESSION['notes_install_db'] ?? null;
        if (!is_array($db)) {
            $errors[] = 'Сессия установки потеряна. Вернитесь к настройке базы данных.';
        }

        if ($errors === []) {
            try {
                $pdo = connectDatabase(
                    (string) $db['db_host'],
                    (int) $db['db_port'],
                    (string) $db['db_name'],
                    (string) $db['db_user'],
                    (string) $db['db_pass']
                );

                $exists = $pdo->prepare('SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1');
                $exists->execute([':username' => $username, ':email' => $email]);
                if ($exists->fetch()) {
                    throw new RuntimeException('Пользователь с таким логином или email уже существует.');
                }

                createAdminUser($pdo, $username, $email, $password, $firstname, $lastname);
                $step = 4;
                $installationCompleted = true;
                $successMessage = 'Администратор создан. Основная схема Messenger v2 установлена.';
            } catch (Throwable $e) {
                error_log('Installer admin step failed: ' . $e->getMessage());
                $errors[] = 'Не удалось создать администратора: ' . $e->getMessage();
                $step = 3;
            }
        } else {
            $step = 3;
        }
    }
}

$requirements = [
    'PHP 8.1+' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'mbstring' => extension_loaded('mbstring'),
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'mysqli' => extension_loaded('mysqli'),
    'sodium' => extension_loaded('sodium'),
    'random_bytes' => function_exists('random_bytes'),
    'Запись в корень проекта' => is_writable($basePath),
];

if ($step === 1) {
    foreach ($requirements as $label => $ok) {
        if (!$ok) {
            $errors[] = 'Не выполнено требование: ' . $label;
        }
    }
}

$csrf = htmlspecialchars((string) $_SESSION['notes_install_csrf'], ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Установка Notes</title>
    <style>
        :root { color-scheme: light; font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        body { margin:0; padding:24px; color:#26313d; background:#f2f4f7; }
        .card { width:min(640px,100%); box-sizing:border-box; margin:24px auto; padding:28px; background:#fff; border:1px solid #e0e5eb; border-radius:14px; box-shadow:0 12px 35px rgba(27,39,52,.08); }
        h1 { margin:0 0 8px; font-size:26px; } h2 { margin:24px 0 14px; font-size:19px; }
        p { color:#687481; } .steps { display:flex; gap:8px; margin:20px 0; }
        .steps span { flex:1; height:5px; background:#e6e9ed; border-radius:99px; }
        .steps span.active { background:#3977d5; }
        .notice { margin:14px 0; padding:11px 13px; border-radius:9px; }
        .error { color:#922f2f; background:#fff0f0; border:1px solid #f2cccc; }
        .success { color:#23663f; background:#edf9f1; border:1px solid #cbe9d6; }
        label { display:block; margin:13px 0; font-size:13px; font-weight:600; }
        input { width:100%; box-sizing:border-box; margin-top:5px; padding:10px 11px; font:inherit; border:1px solid #ced5dd; border-radius:8px; }
        button,.button { display:inline-block; box-sizing:border-box; padding:10px 15px; color:#fff; background:#3977d5; border:0; border-radius:8px; text-decoration:none; cursor:pointer; font:inherit; }
        button { width:100%; margin-top:10px; }
        ul { padding-left:20px; } li { margin:7px 0; } .ok { color:#277449; } .fail { color:#ad3e3e; }
        code { padding:2px 5px; background:#f2f4f7; border-radius:4px; }
    </style>
</head>
<body>
<main class="card">
    <h1>Notes — мастер установки</h1>
    <p>Создаёт каноническую схему приложения и отдельные секреты для заметок, Messenger v2 и WebSocket.</p>

    <div class="steps" aria-label="Шаг <?= $step ?> из 4">
        <?php for ($i = 1; $i <= 4; $i++): ?>
            <span class="<?= $i <= $step ? 'active' : '' ?>"></span>
        <?php endfor; ?>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>

    <?php if ($successMessage !== ''): ?>
        <div class="notice success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
        <h2>1. Проверка окружения</h2>
        <ul>
            <?php foreach ($requirements as $label => $ok): ?>
                <li class="<?= $ok ? 'ok' : 'fail' ?>"><?= $ok ? '✓' : '✕' ?> <?= htmlspecialchars($label) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($errors === []): ?>
            <a class="button" href="?step=2">Продолжить</a>
        <?php endif; ?>

    <?php elseif ($step === 2): ?>
        <h2>2. База данных</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="step" value="2">
            <label>Хост<input name="db_host" value="localhost" required></label>
            <label>Порт<input name="db_port" type="number" value="3306" min="1" max="65535" required></label>
            <label>Имя базы<input name="db_name" required></label>
            <label>Пользователь<input name="db_user" required></label>
            <label>Пароль<input name="db_pass" type="password"></label>
            <button type="submit">Импортировать схемы и продолжить</button>
        </form>

    <?php elseif ($step === 3): ?>
        <h2>3. Администратор</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="step" value="3">
            <label>Имя<input name="admin_firstname" value="Admin" required></label>
            <label>Фамилия<input name="admin_lastname" value="User" required></label>
            <label>Логин<input name="admin_username" required></label>
            <label>Email<input name="admin_email" type="email" required></label>
            <label>Пароль<input name="admin_password" type="password" minlength="10" required></label>
            <label>Повтор пароля<input name="admin_password_confirm" type="password" minlength="10" required></label>
            <button type="submit">Завершить установку</button>
        </form>

    <?php else: ?>
        <h2>4. Готово</h2>
        <p>Файл <code>.env</code> создан, installer после завершения блокируется автоматически.</p>
        <p>Перед публикацией настройте <code>SITEURL</code>, <code>WS_PUBLIC_URL</code> и <code>WS_ALLOWED_ORIGINS</code> под реальный домен.</p>
        <a class="button" href="/">Открыть Notes</a>
    <?php endif; ?>
</main>
</body>
</html>
<?php
if ($installationCompleted) {
    unset($_SESSION['notes_install_in_progress'], $_SESSION['notes_install_db'], $_SESSION['notes_install_csrf']);
}
