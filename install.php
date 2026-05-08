<?php
/**
 * Web Installer Script
 * 
 * Функционал:
 * 1. Проверка требований (PHP, расширения, права).
 * 2. Настройка подключения к БД и тест соединения ("пинг").
 * 3. Проверка наличия таблиц и импорт схемы при необходимости.
 * 4. Создание файла конфигурации (.env).
 * 5. Создание первичного пользователя (Администратора).
 */

// Отключаем вывод ошибок в браузер, но логируем их (для безопасности)
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Настройки сессии
session_start();

// --- КОНФИГУРАЦИЯ И ПЕРЕМЕННЫЕ ---
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$errors = [];
$success_msg = '';
$db_connection = null;

// Список необходимых таблиц (по схеме messenger_schema.sql и другим файлам)
$REQUIRED_TABLES = [
    'users',
    'dialogs',
    'dialog_users',
    'messages',
    // Добавьте остальные таблицы вашего проекта здесь
];

// Пути
$base_path = __DIR__;
$env_file = $base_path . '/.env';
$schema_files = glob($base_path . '/database/*.sql'); // Ищем SQL файлы со схемой

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
        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        // "Пингуем" базу простым запросом
        $pdo->query("SELECT 1");
        return $pdo;
    } catch (PDOException $e) {
        return false;
    }
}

function getExistingTables($pdo) {
    $stmt = $pdo->query("SHOW TABLES");
    return array_column($stmt->fetchAll(), array_keys($stmt->columnCount() ? $stmt->getColumnMeta(0) : ['name'])[0]);
}

function importSchema($pdo, $files) {
    foreach ($files as $file) {
        $sql = file_get_contents($file);
        // Разделяем запросы по точке с запятой (упрощенно)
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    // Игнорируем ошибки, если таблица уже существует (если в SQL есть IF NOT EXISTS)
                    if (strpos($e->getMessage(), "already exists") === false) {
                        throw $e;
                    }
                }
            }
        }
    }
}

function createAdminUser($pdo, $username, $email, $password, $firstname = 'Admin', $lastname = 'User') {
    // Используем усложненное многоуровневое хеширование пароля как в основной системе
    require_once __DIR__ . '/app/handlers/CryptMethods.php';
    
    try {
        // Создаем временные ключи шифрования если их нет (для установки)
        if (!getenv('UNIQUE_KEY')) {
            putenv('UNIQUE_KEY=' . bin2hex(random_bytes(32)));
        }
        if (!getenv('SECONDARY_KEY')) {
            putenv('SECONDARY_KEY=' . hash('sha256', getenv('UNIQUE_KEY') . '_secondary_salt', true));
        }
        
        // Хешируем пароль используя фирменный метод системы
        $hash = \App\Helpers\CryptMethods::createHashFromPassword($password);
    } catch (\RuntimeException $e) {
        // Если класс не найден или ключи не установлены, используем fallback
        error_log("CryptMethods error: " . $e->getMessage() . ". Using fallback hashing.");
        $hash = password_hash($password, PASSWORD_BCRYPT);
    }
    
    $created_at = date('Y-m-d H:i:s');
    $role = 1; // Администратор
    $is_active = 1;
    
    // Структура таблицы users из messenger_schema.sql:
    // username, email, password_hash, firstname, lastname, role, is_active
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
    
    // Генерируем уникальные ключи шифрования если они не переданы
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

# Ключи шифрования для многоуровневого хеширования паролей
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
        // Шаг 2: Проверка БД и запись конфига
        $db_host = $_POST['db_host'] ?? 'localhost';
        $db_port = $_POST['db_port'] ?? '3306';
        $db_name = $_POST['db_name'];
        $db_user = $_POST['db_user'];
        $db_pass = $_POST['db_pass'];

        // 1. Тест соединения
        $db_connection = testDbConnection($db_host, $db_port, $db_name, $db_user, $db_pass);
        
        if (!$db_connection) {
            $errors[] = "Не удалось подключиться к базе данных. Проверьте логин, пароль и имя базы.";
        } else {
            // 2. Проверка таблиц
            $existing_tables = getExistingTables($db_connection);
            $missing_tables = array_diff($REQUIRED_TABLES, $existing_tables);

            if (!empty($missing_tables)) {
                // Таблиц нет или не всех -> пытаемся импортировать
                if (empty($schema_files)) {
                    $errors[] = "Отсутствуют необходимые таблицы, и не найдены файлы схемы (database/*.sql) для их создания.";
                } else {
                    try {
                        importSchema($db_connection, $schema_files);
                        $success_msg = "Схема базы данных успешно импортирована.";
                        // Перепроверяем таблицы после импорта
                        $existing_tables = getExistingTables($db_connection);
                        $missing_tables = array_diff($REQUIRED_TABLES, $existing_tables);
                        
                        if (!empty($missing_tables)) {
                            $errors[] = "После импорта все равно отсутствуют таблицы: " . implode(', ', $missing_tables);
                        }
                    } catch (Exception $e) {
                        $errors[] = "Ошибка при импорте схемы БД: " . $e->getMessage();
                    }
                }
            } else {
                $success_msg = "Все необходимые таблицы присутствуют в базе данных.";
            }

            // Если ошибок нет, сохраняем конфиг
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
                    $_SESSION['db_config'] = $env_data; // Временно храним для следующего шага
                    $_SESSION['pdo'] = $db_connection; // Передаем соединение (в реальном проде лучше пересоздавать)
                    // Редирект на следующий шаг
                    header("Location: install.php?step=3");
                    exit;
                } else {
                    $errors[] = "Не удалось записать файл .env. Проверьте права на запись в корневую директорию.";
                }
            }
        }
    } elseif ($step === 3) {
        // Шаг 3: Создание админа
        $username = trim($_POST['admin_username'] ?? '');
        $email = trim($_POST['admin_email'] ?? '');
        $password = $_POST['admin_password'] ?? '';
        $confirm_password = $_POST['admin_password_confirm'] ?? '';
        $firstname = trim($_POST['admin_firstname'] ?? 'Admin');
        $lastname = trim($_POST['admin_lastname'] ?? 'User');

        if (empty($username) || empty($email) || empty($password)) {
            $errors[] = "Все поля обязательны для заполнения.";
        }
        
        if ($password !== $confirm_password) {
            $errors[] = "Пароли не совпадают.";
        }

        if (strlen($password) < 6) {
            $errors[] = "Пароль должен быть не менее 6 символов.";
        }

        if (empty($errors)) {
            // Берем соединение из сессии или создаем заново из конфига (который уже записан)
            // Для надежности пересоздадим подключение, так как сессия могла протухнуть или объект PDO не сериализуется корректно в некоторых настройках
            $cfg = $_SESSION['db_config'];
            $pdo = testDbConnection($cfg['db_host'], $cfg['db_port'], $cfg['db_name'], $cfg['db_user'], $cfg['db_pass']);

            if ($pdo) {
                try {
                    if (createAdminUser($pdo, $username, $email, $password, $firstname, $lastname)) {
                        // Установка завершена! Удаляем инсталлер или перенаправляем
                        // $_SESSION['installed'] = true;
                        $step = 4; // Финальный экран
                        $success_msg = "Администратор успешно создан! Система готова к работе.";
                        
                        // Опционально: можно предложить удалить этот файл
                        // unlink(__FILE__); 
                    } else {
                        $errors[] = "Не удалось создать пользователя в базе данных.";
                    }
                } catch (Exception $e) {
                    $errors[] = "Ошибка БД при создании пользователя: " . $e->getMessage();
                }
            } else {
                $errors[] = "Потеряно соединение с БД. Попробуйте начать заново.";
            }
        }
    }
}

// Предварительные проверки для Шага 1
$req_php = version_compare(PHP_VERSION, '8.0.0', '>=');
$req_mbstring = extension_loaded('mbstring');
req_pdo_mysql = extension_loaded('pdo_mysql');
$req_writable = is_writable($base_path);

checkRequirement($req_php, "Требуется версия PHP 8.0 или выше. Ваша версия: " . PHP_VERSION);
checkRequirement($req_mbstring, "Требуется расширение PHP: mbstring");
checkRequirement($req_pdo_mysql, "Требуется расширение PHP: pdo_mysql");
checkRequirement($req_writable, "Директория проекта должна иметь права на запись (chmod 755 или 777)");

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
        .hidden { display: none; }
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

    <!-- ШАГ 1: Проверка требований -->
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
            <li class="<?= $req_writable ? 'ok' : 'fail' ?>">
                <?= $req_writable ? '✔' : '✘' ?> Права на запись в директорию
            </li>
        </ul>

        <?php if (empty($errors)): ?>
            <form method="get">
                <input type="hidden" name="step" value="2">
                <button type="submit">Продолжить &rarr;</button>
            </form>
        <?php else: ?>
            <p style="color: #c0392b;">Пожалуйста, исправьте ошибки выше и обновите страницу.</p>
        <?php endif; ?>

    <!-- ШАГ 2: Настройка БД -->
    <?php elseif ($step === 2): ?>
        <h2>Настройка базы данных</h2>
        <p>Введите данные для подключения. Система автоматически проверит соединение и создаст таблицы, если их нет.</p>
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

    <!-- ШАГ 3: Создание админа -->
    <?php elseif ($step === 3): ?>
        <h2>Создание суперпользователя</h2>
        <p>Придумайте логин и пароль для первого администратора системы.</p>
        <form method="post">
            <input type="hidden" name="step" value="3">
            <div class="form-group">
                <label>Имя (First Name)</label>
                <input type="text" name="admin_firstname" value="Admin" required>
            </div>
            <div class="form-group">
                <label>Фамилия (Last Name)</label>
                <input type="text" name="admin_lastname" value="User" required>
            </div>
            <div class="form-group">
                <label>Логин (Username)</label>
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

    <!-- ШАГ 4: Финиш -->
    <?php elseif ($step === 4): ?>
        <div style="text-align: center;">
            <div style="font-size: 60px; color: #27ae60;">🎉</div>
            <h2>Установка завершена!</h2>
            <p><?= htmlspecialchars($success_msg) ?></p>
            <p>Файл конфигурации <code>.env</code> создан.</p>
            
            <div style="background: #fff3cd; padding: 15px; border-radius: 4px; margin: 20px 0; text-align: left; font-size: 0.9em;">
                <strong>⚠️ Важно для безопасности:</strong><br>
                Пожалуйста, удалите файл <code>install.php</code> с сервера, чтобы никто не мог переустановить систему.
            </div>

            <a href="index.php" style="display: inline-block; background: #27ae60; color: white; padding: 12px 20px; text-decoration: none; border-radius: 4px; margin-top: 10px;">Перейти в систему</a>
        </div>
    <?php endif; ?>

    <div class="footer">
        System Installer v1.0
    </div>
</div>

</body>
</html>
