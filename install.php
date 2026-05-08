<?php
/**
 * Web Installer Script
 * Версия с исправленным импортом через mysqli::multi_query
 */

// Отключаем вывод ошибок в браузер, но логируем их
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();

// --- КОНФИГУРАЦИЯ И ПЕРЕМЕННЫЕ ---
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$errors = [];
$success_msg = '';
$db_connection = null;

$REQUIRED_TABLES = [
    'users', 'dialogs', 'dialog_users', 'messages', 'message_statuses',
    'notes', 'note_attachments', 'shared_notes', 'note_history', 'note_tags', 'note_tag_relations',
    'user_files',
];

$base_path = __DIR__;
$env_file = $base_path . '/.env';
$schema_files = glob($base_path . '/database/*.sql');

// Сортировка файлов: messenger -> notes -> file_manager
usort($schema_files, function($a, $b) {
    $order = ['messenger_schema.sql' => 1, 'notes_schema.sql' => 2, 'file_manager_schema.sql' => 3];
    $a_name = basename($a);
    $b_name = basename($b);
    $a_val = $order[$a_name] ?? 99;
    $b_val = $order[$b_name] ?? 99;
    return $a_val - $b_val;
});

// --- ФУНКЦИИ ---

function checkRequirement($condition, $message) {
    global $errors;
    if (!$condition) {
        $errors[] = $message;
        return false;
    }
    return true;
}

function generateRandomString($length = 32) {
    return bin2hex(random_bytes($length));
}

function testDbConnection($host, $port, $db, $user, $pass) {
    try {
        $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]);
        
        $stmt = $pdo->query("SHOW DATABASES LIKE '$db'");
        if ($stmt->rowCount() === 0) {
            return false;
        }

        $pdo->exec("USE `$db`");
        $pdo->query("SELECT 1");
        
        return $pdo;
    } catch (PDOException $e) {
        error_log("DB Connection Error: " . $e->getMessage());
        return false;
    }
}

function getExistingTables($pdo) {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    return array_map(function($table) {
        return trim($table, '`');
    }, $tables);
}

/**
 * Импорт схемы через mysqli::multi_query (поддерживает триггеры)
 */
function importSchemaRaw($host, $user, $pass, $db, $port, $files)
{
    // Включаем строгую отчетность для mysqli
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $mysqli = new mysqli($host, $user, $pass, $db, $port);
    $mysqli->set_charset("utf8mb4");

    // Отключаем проверки внешних ключей на время импорта
    $mysqli->query("SET FOREIGN_KEY_CHECKS=0");

    // Увеличиваем время выполнения скрипта, так как импорт может быть долгим
    set_time_limit(300); 

    $logFile = __DIR__ . '/install_debug.log';
    file_put_contents($logFile, "\n=== Начало импорта через mysqli: " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND);

    foreach ($files as $file) {
        $fileName = basename($file);
        file_put_contents($logFile, "Обработка файла: {$fileName}\n", FILE_APPEND);

        $sql = file_get_contents($file);

        if ($sql === false) {
            throw new Exception("Не удалось прочитать файл: $file");
        }

        // --- ГЛАВНОЕ ИСПРАВЛЕНИЕ ---
        
        // 1. Удаляем строки с DELIMITER (они нужны только для консольного клиента)
        $sql = preg_replace('/^\s*DELIMITER\s+\S+\s*;?\s*$/im', '', $sql);
        
        // 2. Заменяем пользовательские разделители $$ на стандартные ;
        // Это критично для триггеров, процедур и функций
        $sql = str_replace('$$', ';', $sql);
        
        // 3. Удаляем лишние пустые строки, образовавшиеся после чистки
        $sql = preg_replace('/\n\s*\n/', "\n", $sql);
        
        // --------------------------

        // Выполняем мульти-запрос
        if (!$mysqli->multi_query($sql)) {
            $error = "Ошибка импорта {$fileName}: " . $mysqli->error;
            file_put_contents($logFile, "КРИТИЧЕСКАЯ ОШИБКА: {$error}\n", FILE_APPEND);
            throw new Exception($error);
        }

        // Обязательно вычитываем все результаты, чтобы освободить соединение для следующего файла
        $queryCount = 0;
        do {
            if ($result = $mysqli->store_result()) {
                $result->free();
                $queryCount++;
            } else {
                // Если результата нет (например, CREATE TABLE), но запрос успешен - это тоже шаг
                if ($mysqli->affected_rows !== -1) { 
                     // Просто продолжаем
                }
            }
        } while ($mysqli->more_results() && $mysqli->next_result());

        file_put_contents($logFile, "Файл {$fileName} выполнен успешно (обработано блоков: {$queryCount})\n", FILE_APPEND);
    }

    $mysqli->query("SET FOREIGN_KEY_CHECKS=1");
    $mysqli->close();
    
    file_put_contents($logFile, "=== Импорт завершен успешно ===\n", FILE_APPEND);
}

function createAdminUser($pdo, $username, $email, $password, $firstname = 'Admin', $lastname = 'User') {
    require_once __DIR__ . '/app/handlers/CryptMethods.php';

    try {
        if (!getenv('UNIQUE_KEY')) {
            putenv('UNIQUE_KEY=' . bin2hex(random_bytes(32)));
        }
        if (!getenv('SECONDARY_KEY')) {
            putenv('SECONDARY_KEY=' . hash('sha256', getenv('UNIQUE_KEY') . '_secondary_salt', true));
        }

        $hash = \App\Helpers\CryptMethods::createHashFromPassword($password);
    } catch (\RuntimeException $e) {
        error_log("CryptMethods error: " . $e->getMessage() . ". Using fallback hashing.");
        $hash = password_hash($password, PASSWORD_BCRYPT);
    }

    $created_at = date('Y-m-d H:i:s');
    $role = 1;
    $is_active = 1;

    $sql = "INSERT INTO users (username, email, password_hash, firstname, lastname, role, is_active, created_at)
            VALUES (:username, :email, :password_hash, :firstname, :lastname, :role, :is_active, :created_at)";

    $stmt = $pdo->prepare($sql);
    return $stmt->execute([
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => $hash,
        ':firstname' => $firstname,
        ':lastname' => $lastname,
        ':role' => $role,
        ':is_active' => $is_active,
        ':created_at' => $created_at
    ]);
}

function writeEnvFile($data) {
    global $env_file;

    if (empty($data['unique_key'])) {
        $data['unique_key'] = bin2hex(random_bytes(32));
    }
    if (empty($data['secondary_key'])) {
        $data['secondary_key'] = hash('sha256', $data['unique_key'] . '_secondary_salt', true);
    }

    $content = <<<ENV
APP_ENV=production
APP_DEBUG=false
APP_KEY={$data['app_key']}

# Ключи шифрования
UNIQUE_KEY={$data['unique_key']}
SECONDARY_KEY={$data['secondary_key']}

DB_CONNECTION=mysql
DB_HOST={$data['db_host']}
DB_PORT={$data['db_port']}
DB_DATABASE={$data['db_name']}
DB_USERNAME={$data['db_user']}
DB_PASSWORD={$data['db_pass']}

INSTALL_DATE={$data['install_date']}
ENV;

    return file_put_contents($env_file, $content);
}

// --- ОБРАБОТКА ШАГОВ ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 2) {
        $db_host = $_POST['db_host'] ?? 'localhost';
        $db_port = $_POST['db_port'] ?? '3306';
        $db_name = $_POST['db_name'];
        $db_user = $_POST['db_user'];
        $db_pass = $_POST['db_pass'];

        $db_connection = testDbConnection($db_host, $db_port, $db_name, $db_user, $db_pass);

        if (!$db_connection) {
            $errors[] = "Не удалось подключиться к базе данных. Проверьте логин, пароль и имя базы.";
        } else {
            $existing_tables = getExistingTables($db_connection);
            $missing_tables = array_diff($REQUIRED_TABLES, $existing_tables);

            if (!empty($missing_tables)) {
                if (empty($schema_files)) {
                    $errors[] = "Отсутствуют необходимые таблицы, и не найдены файлы схемы (database/*.sql).";
                } else {
                    try {
                        error_log("Начало импорта схемы. Файлы: " . implode(', ', array_map('basename', $schema_files)));
                        
                        // Вызов новой функции импорта
                        importSchemaRaw(
                            $db_host,
                            $db_user,
                            $db_pass,
                            $db_name,
                            $db_port,
                            $schema_files
                        );

                        error_log("Импорт схемы завершен.");
                        $success_msg = "Схема базы данных успешно импортирована.";
                        
                        $existing_tables = getExistingTables($db_connection);
                        $missing_tables_after = array_diff($REQUIRED_TABLES, $existing_tables);

                        if (!empty($missing_tables_after)) {
                            $errors[] = "После импорта отсутствуют таблицы: " . implode(', ', $missing_tables_after);
                        }
                    } catch (Exception $e) {
                        error_log("Ошибка при импорте схемы БД: " . $e->getMessage());
                        $errors[] = "Ошибка при импорте схемы БД: " . $e->getMessage();
                    }
                }
            } else {
                $success_msg = "Все необходимые таблицы присутствуют в базе данных.";
            }

            if (empty($errors)) {
                $env_data = [
                    'db_host' => $db_host,
                    'db_port' => $db_port,
                    'db_name' => $db_name,
                    'db_user' => $db_user,
                    'db_pass' => $db_pass,
                    'app_key' => generateRandomString(),
                    'unique_key' => bin2hex(random_bytes(32)),
                    'secondary_key' => hash('sha256', bin2hex(random_bytes(32)) . '_secondary_salt', true),
                    'install_date' => date('Y-m-d H:i:s')
                ];

                if (writeEnvFile($env_data)) {
                    $_SESSION['db_config'] = $env_data;
                    header("Location: install.php?step=3");
                    exit;
                } else {
                    $errors[] = "Не удалось записать файл .env.";
                }
            }
        }
    } elseif ($step === 3) {
        $username = trim($_POST['admin_username'] ?? '');
        $email = trim($_POST['admin_email'] ?? '');
        $password = $_POST['admin_password'] ?? '';
        $confirm_password = $_POST['admin_password_confirm'] ?? '';
        $firstname = trim($_POST['admin_firstname'] ?? 'Admin');
        $lastname = trim($_POST['admin_lastname'] ?? 'User');

        if (empty($username) || empty($email) || empty($password)) {
            $errors[] = "Все поля обязательны.";
        }
        if ($password !== $confirm_password) {
            $errors[] = "Пароли не совпадают.";
        }
        if (strlen($password) < 6) {
            $errors[] = "Пароль должен быть не менее 6 символов.";
        }

        if (empty($errors)) {
            $cfg = $_SESSION['db_config'];
            $pdo = testDbConnection($cfg['db_host'], $cfg['db_port'], $cfg['db_name'], $cfg['db_user'], $cfg['db_pass']);

            if ($pdo) {
                try {
                    if (createAdminUser($pdo, $username, $email, $password, $firstname, $lastname)) {
                        $step = 4;
                        $success_msg = "Администратор успешно создан! Система готова к работе.";
                    } else {
                        $errors[] = "Не удалось создать пользователя.";
                    }
                } catch (Exception $e) {
                    $errors[] = "Ошибка БД: " . $e->getMessage();
                }
            } else {
                $errors[] = "Потеряно соединение с БД.";
            }
        }
    }
}

// Предварительные проверки
$req_php = version_compare(PHP_VERSION, '8.0.0', '>=');
$req_mbstring = extension_loaded('mbstring');
$req_pdo_mysql = extension_loaded('pdo_mysql');
$req_mysqli = extension_loaded('mysqli'); // Важно для нового импортера
$req_writable = is_writable($base_path);

checkRequirement($req_php, "Требуется PHP 8.0+. Ваша: " . PHP_VERSION);
checkRequirement($req_mbstring, "Требуется расширение mbstring");
checkRequirement($req_pdo_mysql, "Требуется расширение pdo_mysql");
checkRequirement($req_mysqli, "Требуется расширение mysqli (для импорта схемы)");
checkRequirement($req_writable, "Нет прав на запись в директорию");

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Установка системы</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f4f6f9; color: #333; line-height: 1.6; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #2c3e50; margin-bottom: 30px; }
        h2 { border-bottom: 2px solid #eee; padding-bottom: 10px; margin-top: 0; }
        .step-indicator { display: flex; justify-content: space-between; margin-bottom: 20px; font-size: 0.9em; color: #7f8c8d; }
        .step-indicator span.active { color: #3498db; font-weight: bold; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: 600; }
        input[type="text"], input[type="password"], input[type="email"], input[type="number"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { background: #3498db; color: white; border: none; padding: 12px 20px; border-radius: 4px; cursor: pointer; width: 100%; font-size: 16px; transition: background 0.3s; }
        button:hover { background: #2980b9; }
        .error { background: #ffebee; color: #c62828; padding: 10px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #c62828; }
        .success { background: #e8f5e9; color: #2e7d32; padding: 10px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #2e7d32; }
        ul { list-style-type: none; padding: 0; }
        li { padding: 8px 0; border-bottom: 1px solid #eee; }
        li.ok { color: #27ae60; }
        li.fail { color: #c0392b; }
        .footer { text-align: center; margin-top: 20px; font-size: 0.8em; color: #95a5a6; }
    </style>
</head>
<body>

<div class="container">
    <h1>🚀 Мастер установки</h1>

    <div class="step-indicator">
        <span class="<?= $step === 1 ? 'active' : '' ?>">1. Требования</span>
        <span class="<?= $step === 2 ? 'active' : '' ?>">2. База данных</span>
        <span class="<?= $step === 3 ? 'active' : '' ?>">3. Администратор</span>
        <span class="<?= $step === 4 ? 'active' : '' ?>">4. Готово</span>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <strong>Внимание!</strong><br>
            <ul style="margin: 5px 0 0 20px;">
                <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success_msg && $step !== 4): ?>
        <div class="success"><?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
        <h2>Проверка системных требований</h2>
        <ul>
            <li class="<?= $req_php ? 'ok' : 'fail' ?>">
                <?= $req_php ? '✔' : '✘' ?> Версия PHP >= 8.0 (Текущая: <?= PHP_VERSION ?>)
            </li>
            <li class="<?= $req_mbstring ? 'ok' : 'fail' ?>">
                <?= $req_mbstring ? '✔' : '✘' ?> Расширение mbstring
            </li>
            <li class="<?= $req_pdo_mysql ? 'ok' : 'fail' ?>">
                <?= $req_pdo_mysql ? '✔' : '✘' ?> Расширение pdo_mysql
            </li>
            <li class="<?= $req_mysqli ? 'ok' : 'fail' ?>">
                <?= $req_mysqli ? '✔' : '✘' ?> Расширение mysqli (критично)
            </li>
            <li class="<?= $req_writable ? 'ok' : 'fail' ?>">
                <?= $req_writable ? '✔' : '✘' ?> Права на запись
            </li>
        </ul>

        <?php if (empty($errors)): ?>
            <form method="get">
                <input type="hidden" name="step" value="2">
                <button type="submit">Продолжить &rarr;</button>
            </form>
        <?php else: ?>
            <p style="color: #c0392b;">Исправьте ошибки и обновите страницу.</p>
        <?php endif; ?>

    <?php elseif ($step === 2): ?>
        <h2>Настройка базы данных</h2>
        <p>Введите данные. Таблицы будут созданы автоматически.</p>
        <form method="post">
            <input type="hidden" name="step" value="2">
            <div class="form-group">
                <label>Хост БД</label>
                <input type="text" name="db_host" value="localhost" required>
            </div>
            <div class="form-group">
                <label>Порт</label>
                <input type="number" name="db_port" value="3306" required>
            </div>
            <div class="form-group">
                <label>Имя базы данных</label>
                <input type="text" name="db_name" required placeholder="my_database">
            </div>
            <div class="form-group">
                <label>Пользователь БД</label>
                <input type="text" name="db_user" required placeholder="root">
            </div>
            <div class="form-group">
                <label>Пароль БД</label>
                <input type="password" name="db_pass" placeholder="******">
            </div>
            <button type="submit">Проверить и установить</button>
        </form>
        <br>
        <a href="?step=1" style="color: #7f8c8d; text-decoration: none;">&larr; Назад</a>

    <?php elseif ($step === 3): ?>
        <h2>Создание администратора</h2>
        <form method="post">
            <input type="hidden" name="step" value="3">
            <div class="form-group">
                <label>Имя</label>
                <input type="text" name="admin_firstname" value="Admin" required>
            </div>
            <div class="form-group">
                <label>Фамилия</label>
                <input type="text" name="admin_lastname" value="User" required>
            </div>
            <div class="form-group">
                <label>Логин</label>
                <input type="text" name="admin_username" required placeholder="admin">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="admin_email" required>
            </div>
            <div class="form-group">
                <label>Пароль</label>
                <input type="password" name="admin_password" required minlength="6">
            </div>
            <div class="form-group">
                <label>Подтверждение пароля</label>
                <input type="password" name="admin_password_confirm" required>
            </div>
            <button type="submit">Завершить установку</button>
        </form>
        <br>
        <a href="?step=2" style="color: #7f8c8d; text-decoration: none;">&larr; Назад</a>

    <?php elseif ($step === 4): ?>
        <div style="text-align: center;">
            <div style="font-size: 60px; color: #27ae60;">🎉</div>
            <h2>Установка завершена!</h2>
            <p><?= htmlspecialchars($success_msg) ?></p>
            <p>Файл конфигурации <code>.env</code> создан.</p>
            <div style="background: #fff3cd; padding: 15px; border-radius: 4px; margin: 20px 0; text-align: left; font-size: 0.9em;">
                <strong>⚠️ Безопасность:</strong> Удалите файл <code>install.php</code>.
            </div>
            <a href="index.php" style="display: inline-block; background: #27ae60; color: white; padding: 12px 20px; text-decoration: none; border-radius: 4px; margin-top: 10px;">Перейти в систему</a>
        </div>
    <?php endif; ?>

    <div class="footer">System Installer v1.1 (mysqli fix)</div>
</div>

</body>
</html>