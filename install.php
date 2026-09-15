<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

function installerIsHttps(): bool
{
    $forwarded = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    if (in_array($forwarded, ['http', 'https'], true)) {
        return $forwarded === 'https';
    }

    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    return in_array($https, ['on', '1', 'true'], true) || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

session_set_cookie_params([
    'httponly' => true,
    'secure' => installerIsHttps(),
    'samesite' => 'Lax',
]);
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
$warnings = [];
$successMessage = '';
$installationCompleted = false;
$installedAppUrl = '';

$requiredTables = [
    'users',
    'dialogs',
    'user_to_dialogs',
    'messages',
    'message_user_deletions',
    'messenger_attachments',
    'message_reactions',
    'notes',
    'note_attachments',
    'shared_notes',
    'note_history',
    'note_tags',
    'note_tag_relations',
    'user_files',
    'user_fields',
    'tasks',
    'subtasks',
    'task_categories',
    'task_category_relations',
    'task_reminders',
    'task_boards',
    'task_board_members',
    'task_board_items',
    'task_board_assignees',
    'system_settings',
    'user_storage_quotas',
    'roles',
    'permissions',
    'role_permissions',
    'user_roles',
    'role_module_policies',
    'module_lifecycle',
];

$schemaFiles = glob($basePath . '/database/*.sql') ?: [];
usort($schemaFiles, static function (string $a, string $b): int {
    $order = [
        'messenger_schema.sql' => 1,
        'notes_schema.sql' => 2,
        'file_manager_schema.sql' => 3,
        'user_fields_schema.sql' => 4,
        'tasks_schema.sql' => 5,
        'access_control_schema.sql' => 6,
        'settings_schema.sql' => 7,
        'module_lifecycle_schema.sql' => 8,
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

function normalizeFsPath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '') {
        return '';
    }

    return rtrim(preg_replace('#/+#', '/', $path) ?? $path, '/');
}

function pathIsInside(string $path, string $parent): bool
{
    $path = normalizeFsPath($path);
    $parent = normalizeFsPath($parent);
    if ($path === '' || $parent === '') {
        return false;
    }

    return $path === $parent || str_starts_with($path . '/', $parent . '/');
}

function requestHost(): string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
    if (preg_match('/^(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\])(?::[0-9]{1,5})?$/', $host) !== 1) {
        return 'localhost';
    }

    return $host;
}

function detectedSiteUrl(): string
{
    return (installerIsHttps() ? 'https' : 'http') . '://' . requestHost();
}

function detectedBasePath(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php'));
    $directory = trim(str_replace('\\', '/', dirname($script)), '/.');
    return $directory === '' ? '/' : '/' . $directory . '/';
}

function normalizeBasePath(string $path): string
{
    $path = '/' . trim($path, '/') . '/';
    if ($path === '//') {
        return '/';
    }
    if (preg_match('#^/(?:[A-Za-z0-9._~-]+/)*$#', $path) !== 1) {
        throw new InvalidArgumentException('Некорректный BASE_PATH.');
    }

    return $path;
}

function normalizeSiteUrl(string $url): string
{
    $url = rtrim(trim($url), '/');
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $host = (string) parse_url($url, PHP_URL_HOST);
    $port = parse_url($url, PHP_URL_PORT);
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || ($path !== '' && $path !== '/')) {
        throw new InvalidArgumentException('SITEURL должен быть origin вида https://example.com без пути.');
    }

    return $scheme . '://' . $host . ($port !== null ? ':' . (int) $port : '');
}

function normalizeWebSocketUrl(string $url, string $siteUrl): string
{
    $url = rtrim(trim($url), '/');
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $host = (string) parse_url($url, PHP_URL_HOST);
    if (!in_array($scheme, ['ws', 'wss'], true) || $host === '') {
        throw new InvalidArgumentException('WS_PUBLIC_URL должен начинаться с ws:// или wss://.');
    }

    if (str_starts_with($siteUrl, 'https://') && $scheme !== 'wss') {
        throw new InvalidArgumentException('Для HTTPS сайта WebSocket URL должен использовать wss://.');
    }

    return $url;
}

function appUrl(string $siteUrl, string $basePath): string
{
    return rtrim($siteUrl, '/') . ($basePath === '/' ? '/' : $basePath);
}

function defaultWebSocketUrl(string $siteUrl, string $basePath): string
{
    $scheme = str_starts_with($siteUrl, 'https://') ? 'wss://' : 'ws://';
    $authority = preg_replace('#^https?://#', '', $siteUrl) ?: 'localhost';
    $prefix = $basePath === '/' ? '' : rtrim($basePath, '/');
    return $scheme . $authority . $prefix . '/ws';
}

function isAbsolutePath(string $path): bool
{
    return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
}

function privateStorageCandidate(string $basePath): string
{
    $suffix = substr(hash('sha256', normalizeFsPath($basePath)), 0, 10);
    $home = trim((string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')));
    $candidates = [
        dirname($basePath) . '/.workspace-organizer-private-' . $suffix,
        $home !== '' ? $home . '/.workspace-organizer-private-' . $suffix : '',
    ];

    $appReal = realpath($basePath) ?: normalizeFsPath($basePath);
    $documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $documentReal = $documentRoot !== '' ? (realpath($documentRoot) ?: normalizeFsPath($documentRoot)) : '';

    foreach (array_unique(array_filter($candidates)) as $candidate) {
        $candidate = normalizeFsPath($candidate);
        if (pathIsInside($candidate, $appReal) || ($documentReal !== '' && pathIsInside($candidate, $documentReal))) {
            continue;
        }

        $parent = dirname($candidate);
        if (is_dir($parent) && is_writable($parent)) {
            return $candidate;
        }
    }

    return '';
}

function preparePrivateStorage(string $path, string $basePath): string
{
    $path = normalizeFsPath($path);
    if ($path === '' || !isAbsolutePath($path)) {
        throw new RuntimeException('Укажите абсолютный путь к private storage.');
    }

    $appReal = realpath($basePath) ?: normalizeFsPath($basePath);
    $documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $documentReal = $documentRoot !== '' ? (realpath($documentRoot) ?: normalizeFsPath($documentRoot)) : '';
    if (pathIsInside($path, $appReal) || ($documentReal !== '' && pathIsInside($path, $documentReal))) {
        throw new RuntimeException('PRIVATE_STORAGE_PATH должен находиться вне каталога приложения и document root.');
    }

    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Не удалось создать private storage: ' . $path);
    }
    @chmod($path, 0700);

    foreach (['file_manager', 'messenger', 'notes', 'users', 'rate-limit', 'logs', 'legacy'] as $directory) {
        $target = $path . '/' . $directory;
        if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
            throw new RuntimeException('Не удалось создать private storage каталог: ' . $directory);
        }
        @chmod($target, 0700);
    }

    $probe = $path . '/.installer-write-test-' . bin2hex(random_bytes(6));
    if (file_put_contents($probe, 'ok', LOCK_EX) === false) {
        throw new RuntimeException('PHP не может записывать в private storage.');
    }
    @chmod($probe, 0600);
    @unlink($probe);

    $real = realpath($path);
    if ($real === false || !is_writable($real)) {
        throw new RuntimeException('Private storage не доступен PHP на запись.');
    }

    return $real;
}

function prepareRuntimeDirectories(string $basePath): void
{
    foreach (['compile', 'cache'] as $directory) {
        $path = $basePath . '/' . $directory;
        if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
            throw new RuntimeException('Не удалось создать runtime каталог: ' . $directory);
        }
        if (!is_writable($path)) {
            throw new RuntimeException('Runtime каталог недоступен на запись: ' . $directory);
        }
    }
}

function connectDatabase(string $host, int $port, string $database, string $username, string $password): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
    ]);
}

function connectDatabaseServer(string $host, int $port, string $username, string $password): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function connectOrCreateDatabase(string $host, int $port, string $database, string $username, string $password): PDO
{
    if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
        throw new RuntimeException('Имя базы может содержать только латиницу, цифры и _.');
    }

    try {
        return connectDatabase($host, $port, $database, $username, $password);
    } catch (PDOException $e) {
        $mysqlCode = (int) ($e->errorInfo[1] ?? 0);
        if ($mysqlCode !== 1049) {
            throw $e;
        }

        try {
            $server = connectDatabaseServer($host, $port, $username, $password);
            $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (Throwable $createError) {
            throw new RuntimeException(
                'База «' . $database . '» не существует, а этот MySQL-пользователь не может создать её. ' .
                'Создайте пустую базу в панели хостинга и повторите установку.',
                0,
                $createError
            );
        }

        return connectDatabase($host, $port, $database, $username, $password);
    }
}

/** @return list<string> */
function existingTables(PDO $pdo): array
{
    return array_map(
        static fn ($table): string => trim((string) $table, '`'),
        $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)
    );
}

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
        throw new RuntimeException('Не удалось создать Argon2id хеш пароля.');
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

function envQuoted(string $value): string
{
    return '"' . str_replace(["\\", '"', "\r", "\n"], ['\\\\', '\\"', '', '\\n'], $value) . '"';
}

function writeEnvironmentFile(string $file, array $data): void
{
    $privateStorage = rtrim((string) $data['private_storage'], '/');
    $lines = [
        '# Generated by Workspace Organizer web installer',
        'DBDRIVER=mysql',
        'DBHOST=' . envQuoted((string) $data['db_host']),
        'DBPORT=' . (int) $data['db_port'],
        'DBUSER=' . envQuoted((string) $data['db_user']),
        'DBPASS=' . envQuoted((string) $data['db_pass']),
        'DBNAME=' . envQuoted((string) $data['db_name']),
        '',
        'UNIQUE_KEY=' . envQuoted((string) $data['unique_key']),
        'MSG_SECRET_KEY=' . envQuoted((string) $data['message_key']),
        'WS_TICKET_SECRET=' . envQuoted((string) $data['ws_ticket_secret']),
        '',
        'PRIVATE_STORAGE_PATH=' . envQuoted($privateStorage),
        'UPLOAD_DIR=' . envQuoted($privateStorage . '/legacy/file_manager'),
        'NOTES_UPLOAD_DIR=' . envQuoted($privateStorage . '/legacy/notes'),
        'MESSENGER_UPLOAD_DIR=' . envQuoted($privateStorage . '/legacy/messenger'),
        'MAX_UPLOAD_SIZE=10485760',
        'NOTES_MAX_UPLOAD_SIZE=10485760',
        'MAX_NOTE_ATTACHMENTS=10',
        'MESSENGER_MAX_UPLOAD_SIZE=10485760',
        'MESSENGER_MAX_VOICE_SIZE=5242880',
        'MESSENGER_GROUP_AVATAR_MAX_SIZE=2097152',
        'PROFILE_AVATAR_MAX_SIZE=2097152',
        'MESSENGER_ORPHAN_TTL_SECONDS=86400',
        'MESSENGER_SEARCH_SCAN_LIMIT=1000',
        '',
        'SITEURL=' . envQuoted((string) $data['site_url']),
        'BASE_PATH=' . envQuoted((string) $data['base_path']),
        'REGISTRATION_INVITE_CODE=',
        '',
        'WS_HOST=127.0.0.1',
        'WS_PORT=27800',
        'WS_PUBLIC_URL=' . envQuoted((string) $data['ws_public_url']),
        'WS_ALLOWED_ORIGINS=' . envQuoted((string) $data['site_url']),
        'WS_MAX_CONNECTIONS=256',
        'WS_MAX_PAYLOAD_BYTES=2097152',
        '',
        'LOG_LEVEL=INFO',
        'LOG_FILE=' . envQuoted($privateStorage . '/logs/app.log'),
        'SESSION_LIFETIME=3600',
        'MAX_LOGIN_ATTEMPTS=5',
        'AUTH_RATE_LIMIT_WINDOW_SECONDS=300',
        'UPLOAD_RATE_LIMIT_ATTEMPTS=60',
        'UPLOAD_RATE_LIMIT_WINDOW_SECONDS=60',
        'CSRF_ENABLED=true',
        'INSTALL_DATE=' . envQuoted((string) $data['install_date']),
        '',
    ];

    $temp = $file . '.installing-' . bin2hex(random_bytes(6));
    if (file_put_contents($temp, implode("\n", $lines), LOCK_EX) === false) {
        throw new RuntimeException('Не удалось подготовить .env. Проверьте права корня проекта.');
    }
    @chmod($temp, 0600);

    if (!rename($temp, $file)) {
        @unlink($temp);
        throw new RuntimeException('Не удалось атомарно создать .env.');
    }
    @chmod($file, 0600);
}

function installerRequirements(string $basePath): array
{
    $checks = [
        'PHP 8.1+' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'Native core runtime' => is_file($basePath . '/core/Environment.php')
            && is_file($basePath . '/core/NativeViewRenderer.php'),
        'Native WebSocket runtime' => is_file($basePath . '/app/socket/NativeMessengerServer.php')
            && is_file($basePath . '/app/socket/SocketHandshake.php')
            && is_file($basePath . '/app/socket/SocketFrameCodec.php'),
        'mbstring' => extension_loaded('mbstring'),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'mysqli' => extension_loaded('mysqli'),
        'sodium' => extension_loaded('sodium'),
        'fileinfo' => extension_loaded('fileinfo'),
        'gd' => extension_loaded('gd'),
        'Argon2id password hashing' => in_array('argon2id', password_algos(), true),
        'random_bytes' => function_exists('random_bytes'),
        'Запись .env в корень проекта' => is_writable($basePath),
        'database/*.sql' => is_file($basePath . '/database/messenger_schema.sql')
            && is_file($basePath . '/database/notes_schema.sql')
            && is_file($basePath . '/database/file_manager_schema.sql')
            && is_file($basePath . '/database/user_fields_schema.sql')
            && is_file($basePath . '/database/tasks_schema.sql')
            && is_file($basePath . '/database/access_control_schema.sql')
            && is_file($basePath . '/database/settings_schema.sql')
            && is_file($basePath . '/database/module_lifecycle_schema.sql'),
    ];

    try {
        prepareRuntimeDirectories($basePath);
        $checks['Writable runtime directories'] = true;
    } catch (Throwable) {
        $checks['Writable runtime directories'] = false;
    }

    return $checks;
}

$detectedSiteUrl = detectedSiteUrl();
$detectedBasePath = detectedBasePath();
$detectedWsUrl = defaultWebSocketUrl($detectedSiteUrl, $detectedBasePath);
$detectedPrivateStorage = privateStorageCandidate($basePath);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verifyInstallerCsrf();
    $postedStep = (int) ($_POST['step'] ?? 0);

    if ($postedStep === 2) {
        $host = trim((string) ($_POST['db_host'] ?? 'localhost'));
        $port = (int) ($_POST['db_port'] ?? 3306);
        $database = trim((string) ($_POST['db_name'] ?? ''));
        $username = trim((string) ($_POST['db_user'] ?? ''));
        $password = (string) ($_POST['db_pass'] ?? '');
        $privateStorage = trim((string) ($_POST['private_storage_path'] ?? $detectedPrivateStorage));

        try {
            $siteUrl = normalizeSiteUrl((string) ($_POST['site_url'] ?? $detectedSiteUrl));
            $baseUrlPath = normalizeBasePath((string) ($_POST['base_path'] ?? $detectedBasePath));
            $wsPublicUrl = normalizeWebSocketUrl(
                (string) ($_POST['ws_public_url'] ?? defaultWebSocketUrl($siteUrl, $baseUrlPath)),
                $siteUrl
            );
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            $siteUrl = $detectedSiteUrl;
            $baseUrlPath = $detectedBasePath;
            $wsPublicUrl = $detectedWsUrl;
        }

        if ($host === '' || $database === '' || $username === '' || $port < 1 || $port > 65535) {
            $errors[] = 'Некорректные параметры базы данных.';
        }

        if ($errors === []) {
            try {
                $storageReal = preparePrivateStorage($privateStorage, $basePath);
                $pdo = connectOrCreateDatabase($host, $port, $database, $username, $password);
                $existing = existingTables($pdo);
                $appTables = array_values(array_intersect($requiredTables, $existing));
                $missing = array_values(array_diff($requiredTables, $existing));

                if ($existing === []) {
                    if ($schemaFiles === []) {
                        throw new RuntimeException('Файлы database/*.sql не найдены.');
                    }
                    importSchemas($host, $port, $database, $username, $password, $schemaFiles);
                } elseif ($appTables === []) {
                    throw new RuntimeException('Для Workspace Organizer нужна отдельная пустая база данных. В указанной базе уже есть чужие таблицы.');
                } elseif ($missing !== []) {
                    throw new RuntimeException(
                        'Обнаружена существующая база старой/неполной версии. Web-installer не изменяет существующие данные. ' .
                        'Для upgrade используйте versioned migration path. Отсутствуют таблицы: ' . implode(', ', $missing)
                    );
                }

                $remaining = array_values(array_diff($requiredTables, existingTables($pdo)));
                if ($remaining !== []) {
                    throw new RuntimeException('После импорта отсутствуют таблицы: ' . implode(', ', $remaining));
                }

                $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                if ($userCount > 0) {
                    throw new RuntimeException(
                        'В базе уже есть пользователи. Это похоже на существующую установку. ' .
                        'Восстановите её .env и используйте migrations вместо повторного installer.'
                    );
                }

                $_SESSION['notes_install_db'] = [
                    'db_host' => $host,
                    'db_port' => $port,
                    'db_name' => $database,
                    'db_user' => $username,
                    'db_pass' => $password,
                    'private_storage' => $storageReal,
                    'site_url' => $siteUrl,
                    'base_path' => $baseUrlPath,
                    'ws_public_url' => $wsPublicUrl,
                    'unique_key' => randomSecret(),
                    'message_key' => randomSecret(),
                    'ws_ticket_secret' => randomSecret(),
                    'install_date' => date('YmdHis'),
                ];
                header('Location: install.php?step=3');
                exit;
            } catch (Throwable $e) {
                error_log('Installer database/config step failed: ' . $e->getMessage());
                $errors[] = 'Не удалось подготовить установку: ' . $e->getMessage();
            }
        }
        $step = 2;
    } elseif ($postedStep === 3) {
        $username = strtolower(trim((string) ($_POST['admin_username'] ?? '')));
        $email = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
        $password = (string) ($_POST['admin_password'] ?? '');
        $confirmation = (string) ($_POST['admin_password_confirm'] ?? '');
        $firstname = trim((string) ($_POST['admin_firstname'] ?? 'Admin'));
        $lastname = trim((string) ($_POST['admin_lastname'] ?? 'User'));

        if (preg_match('/^[a-z0-9_.-]{3,50}$/', $username) !== 1) {
            $errors[] = 'Логин: 3–50 символов, латиница, цифры, точка, _ или -.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors[] = 'Укажите корректный email.';
        }
        if ($firstname === '' || mb_strlen($firstname) > 80 || $lastname === '' || mb_strlen($lastname) > 80) {
            $errors[] = 'Имя и фамилия обязательны и не должны превышать 80 символов.';
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

                $pdo->beginTransaction();
                try {
                    createAdminUser($pdo, $username, $email, $password, $firstname, $lastname);
                    writeEnvironmentFile($envFile, $db);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if (is_file($envFile)) {
                        @unlink($envFile);
                    }
                    throw $e;
                }

                $step = 4;
                $installationCompleted = true;
                $installedAppUrl = appUrl((string) $db['site_url'], (string) $db['base_path']);
                $successMessage = 'Установка завершена: база, private storage, секреты и первый администратор готовы.';
            } catch (Throwable $e) {
                error_log('Installer admin/finalize step failed: ' . $e->getMessage());
                $errors[] = 'Не удалось завершить установку: ' . $e->getMessage();
                $step = 3;
            }
        } else {
            $step = 3;
        }
    }
}

$requirements = installerRequirements($basePath);
if ($step === 1) {
    foreach ($requirements as $label => $ok) {
        if (!$ok) {
            $errors[] = 'Не выполнено требование: ' . $label;
        }
    }
    if ($detectedPrivateStorage === '') {
        $warnings[] = 'Автоматически подобрать private storage вне web-root не удалось. На следующем шаге укажите абсолютный writable путь из панели хостинга.';
    }
}

$csrf = htmlspecialchars((string) $_SESSION['notes_install_csrf'], ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Установка Workspace Organizer</title>
    <style>
        :root { color-scheme:light; font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; --primary:#2563eb; --border:#d9e0e8; --muted:#657284; }
        * { box-sizing:border-box; }
        body { margin:0; padding:24px; color:#1f2937; background:#f4f6f9; }
        .card { width:min(760px,100%); margin:24px auto; padding:30px; background:#fff; border:1px solid var(--border); border-radius:18px; box-shadow:0 16px 45px rgba(31,41,55,.08); }
        h1 { margin:0 0 8px; font-size:clamp(24px,4vw,32px); } h2 { margin:26px 0 14px; font-size:20px; }
        p { color:var(--muted); line-height:1.55; } .steps { display:flex; gap:8px; margin:22px 0; }
        .steps span { flex:1; height:6px; background:#e7ebf0; border-radius:99px; } .steps span.active { background:var(--primary); }
        .notice { margin:14px 0; padding:12px 14px; border-radius:10px; line-height:1.45; }
        .error { color:#8b2525; background:#fff1f1; border:1px solid #efcaca; }
        .warning { color:#79520c; background:#fff8e7; border:1px solid #f1dfac; }
        .success { color:#1f683e; background:#edf9f2; border:1px solid #c9e8d5; }
        label { display:block; margin:14px 0; font-size:13px; font-weight:650; }
        input { width:100%; margin-top:6px; padding:11px 12px; font:inherit; border:1px solid #cbd3dd; border-radius:9px; background:#fff; }
        input:focus { outline:3px solid rgba(37,99,235,.14); border-color:var(--primary); }
        fieldset { margin:18px 0; padding:16px; border:1px solid var(--border); border-radius:12px; }
        legend { padding:0 8px; font-weight:700; } small { display:block; margin-top:5px; color:var(--muted); font-weight:400; line-height:1.4; }
        button,.button { display:inline-flex; justify-content:center; align-items:center; min-height:44px; padding:10px 16px; color:#fff; background:var(--primary); border:0; border-radius:9px; text-decoration:none; cursor:pointer; font:inherit; font-weight:650; }
        button { width:100%; margin-top:10px; } ul { padding-left:22px; } li { margin:8px 0; } .ok { color:#237046; } .fail { color:#a43434; }
        code { padding:2px 5px; background:#f2f4f7; border-radius:5px; } details { margin-top:18px; } summary { cursor:pointer; font-weight:700; }
        .summary { padding:14px; background:#f8fafc; border:1px solid var(--border); border-radius:12px; }
        @media (max-width:600px) { body { padding:10px; } .card { margin:8px auto; padding:20px; border-radius:14px; } }
    </style>
</head>
<body>
<main class="card">
    <h1>Workspace Organizer — установка</h1>
    <p>Fresh install рассчитан на обычный PHP/MySQL hosting: мастер сам создаёт схему, private storage, секреты, конфигурацию домена и первого admin. Composer и каталог <code>vendor/</code> для runtime не нужны.</p>

    <div class="steps" aria-label="Шаг <?= $step ?> из 4">
        <?php for ($i = 1; $i <= 4; $i++): ?>
            <span class="<?= $i <= $step ? 'active' : '' ?>"></span>
        <?php endfor; ?>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="notice error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>
    <?php foreach ($warnings as $warning): ?>
        <div class="notice warning"><?= htmlspecialchars($warning, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>
    <?php if ($successMessage !== ''): ?>
        <div class="notice success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
        <h2>1. Проверка хостинга</h2>
        <ul>
            <?php foreach ($requirements as $label => $ok): ?>
                <li class="<?= $ok ? 'ok' : 'fail' ?>"><?= $ok ? '✓' : '✕' ?> <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
        <div class="summary">
            <strong>Автоопределение</strong>
            <p>Сайт: <code><?= htmlspecialchars(appUrl($detectedSiteUrl, $detectedBasePath), ENT_QUOTES, 'UTF-8') ?></code><br>
            WebSocket: <code><?= htmlspecialchars($detectedWsUrl, ENT_QUOTES, 'UTF-8') ?></code><br>
            Private storage: <code><?= htmlspecialchars($detectedPrivateStorage !== '' ? $detectedPrivateStorage : 'нужно указать', ENT_QUOTES, 'UTF-8') ?></code></p>
        </div>
        <?php if ($errors === []): ?>
            <a class="button" href="?step=2">Продолжить</a>
        <?php endif; ?>

    <?php elseif ($step === 2): ?>
        <h2>2. База и окружение</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="step" value="2">
            <fieldset>
                <legend>MySQL</legend>
                <label>Хост<input name="db_host" value="<?= htmlspecialchars((string) ($_POST['db_host'] ?? 'localhost'), ENT_QUOTES, 'UTF-8') ?>" required></label>
                <label>Порт<input name="db_port" type="number" value="<?= (int) ($_POST['db_port'] ?? 3306) ?>" min="1" max="65535" required></label>
                <label>Имя базы<input name="db_name" value="<?= htmlspecialchars((string) ($_POST['db_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" pattern="[A-Za-z0-9_]{1,64}" required><small>Если база отсутствует и hosting разрешает CREATE DATABASE, installer создаст её сам.</small></label>
                <label>Пользователь<input name="db_user" value="<?= htmlspecialchars((string) ($_POST['db_user'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></label>
                <label>Пароль<input name="db_pass" type="password" autocomplete="new-password"></label>
            </fieldset>

            <fieldset>
                <legend>Автонастройка приложения</legend>
                <label>Private storage<input name="private_storage_path" value="<?= htmlspecialchars((string) ($_POST['private_storage_path'] ?? $detectedPrivateStorage), ENT_QUOTES, 'UTF-8') ?>" required><small>Абсолютный путь вне document root. В большинстве shared-hosting аккаунтов мастер уже подставляет подходящий путь.</small></label>
                <details>
                    <summary>Проверить домен / reverse proxy</summary>
                    <label>SITEURL<input name="site_url" value="<?= htmlspecialchars((string) ($_POST['site_url'] ?? $detectedSiteUrl), ENT_QUOTES, 'UTF-8') ?>" required></label>
                    <label>BASE_PATH<input name="base_path" value="<?= htmlspecialchars((string) ($_POST['base_path'] ?? $detectedBasePath), ENT_QUOTES, 'UTF-8') ?>" required><small>Для установки в корень — <code>/</code>; в подкаталог — например <code>/workspace/</code>.</small></label>
                    <label>WS_PUBLIC_URL<input name="ws_public_url" value="<?= htmlspecialchars((string) ($_POST['ws_public_url'] ?? $detectedWsUrl), ENT_QUOTES, 'UTF-8') ?>" required><small>По умолчанию используется same-site <code>/ws</code>, подходящий для WSS reverse proxy.</small></label>
                </details>
            </fieldset>
            <button type="submit">Подготовить проект</button>
        </form>

    <?php elseif ($step === 3): ?>
        <h2>3. Первый администратор</h2>
        <p><code>.env</code> будет создан только после успешного создания admin — незавершённая установка не блокирует повторный запуск мастера.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="step" value="3">
            <label>Имя<input name="admin_firstname" value="<?= htmlspecialchars((string) ($_POST['admin_firstname'] ?? 'Admin'), ENT_QUOTES, 'UTF-8') ?>" maxlength="80" required></label>
            <label>Фамилия<input name="admin_lastname" value="<?= htmlspecialchars((string) ($_POST['admin_lastname'] ?? 'User'), ENT_QUOTES, 'UTF-8') ?>" maxlength="80" required></label>
            <label>Логин<input name="admin_username" minlength="3" maxlength="50" pattern="[a-z0-9_.-]{3,50}" required autocomplete="username"></label>
            <label>Email<input name="admin_email" type="email" maxlength="190" required autocomplete="email"></label>
            <label>Пароль<input name="admin_password" type="password" minlength="10" required autocomplete="new-password"></label>
            <label>Повтор пароля<input name="admin_password_confirm" type="password" minlength="10" required autocomplete="new-password"></label>
            <button type="submit">Завершить установку</button>
        </form>

    <?php else: ?>
        <h2>4. Готово</h2>
        <p>Схема БД, private storage, секреты, <code>.env</code> и первый admin созданы. Повторный запуск installer автоматически закрыт.</p>
        <p>Realtime Messenger использует встроенный native WebSocket process. Запустите <code>php ws_server/server.php start</code> через systemd/Supervisor/панель и проксируйте публичный <code>/ws</code> на локальный <code>WS_PORT</code>.</p>
        <p>Если hosting bundle развернут в подкаталоге, ссылка ниже уже учитывает <code>BASE_PATH</code>.</p>
        <a class="button" href="<?= htmlspecialchars($installedAppUrl !== '' ? $installedAppUrl : '/', ENT_QUOTES, 'UTF-8') ?>">Открыть Workspace Organizer</a>
    <?php endif; ?>
</main>
</body>
</html>
<?php
if ($installationCompleted) {
    unset($_SESSION['notes_install_in_progress'], $_SESSION['notes_install_db'], $_SESSION['notes_install_csrf']);
}
