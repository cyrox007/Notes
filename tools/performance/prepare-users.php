<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);
require_once $root . '/core/Config.php';

use App\Helpers\CryptMethods;
use Core\Config;
use Core\DatabaseManager;
use Core\Uuid;

$options = getopt('', [
    'count:',
    'prefix:',
    'password:',
    'output:',
    'confirm:',
    'help',
]);

if (isset($options['help'])) {
    echo "Использование:\n";
    echo "  php tools/performance/prepare-users.php --confirm=WORKSPACE_PERFORMANCE_ONLY [--count=1200] [--prefix=perf_11_] [--password=...] [--output=/path/users.ndjson]\n\n";
    echo "Команда создаёт/обновляет только синтетические учётные записи с указанным perf-префиксом.\n";
    echo "Запускать только в отдельном эталонном нагрузочном окружении, не на рабочей установке.\n";
    exit(0);
}

$count = isset($options['count']) ? (int) $options['count'] : 1200;
$prefix = trim((string) ($options['prefix'] ?? 'perf_11_'));
$password = (string) ($options['password'] ?? '');
$output = trim((string) ($options['output'] ?? (sys_get_temp_dir() . '/workspace-performance-users.ndjson')));
$confirm = trim((string) ($options['confirm'] ?? getenv('PERF_FIXTURE_CONFIRM') ?: ''));

try {
    if ($confirm !== 'WORKSPACE_PERFORMANCE_ONLY') {
        throw new RuntimeException('Нужно явно подтвердить отдельное нагрузочное окружение: --confirm=WORKSPACE_PERFORMANCE_ONLY');
    }
    if ($count < 1 || $count > 5000) {
        throw new InvalidArgumentException('Количество синтетических пользователей должно быть в диапазоне 1..5000');
    }
    if (preg_match('/^perf_[a-z0-9_]{1,24}$/D', $prefix) !== 1) {
        throw new InvalidArgumentException('Префикс обязан начинаться с perf_ и содержать только строчные латинские буквы, цифры и подчёркивания');
    }
    if ($output === '') {
        throw new InvalidArgumentException('Путь к файлу учётных данных не задан');
    }

    if ($password === '') {
        $password = 'Perf!' . bin2hex(random_bytes(16));
    }
    if (strlen($password) < 10 || strlen($password) > 200) {
        throw new InvalidArgumentException('Пароль должен содержать от 10 до 200 символов');
    }

    $directory = dirname($output);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать каталог для файла учётных данных');
    }

    $db = DatabaseManager::getInstance();
    $passwordHash = CryptMethods::hashPassword($password);
    $now = date('Y-m-d H:i:s');
    $credentials = fopen($output, 'wb');
    if (!is_resource($credentials)) {
        throw new RuntimeException('Не удалось открыть файл учётных данных');
    }

    @chmod($output, 0600);
    $created = 0;
    $batchSize = 100;

    for ($offset = 0; $offset < $count; $offset += $batchSize) {
        $db->beginTransaction();
        try {
            $limit = min($count, $offset + $batchSize);
            for ($index = $offset + 1; $index <= $limit; $index++) {
                $suffix = str_pad((string) $index, 4, '0', STR_PAD_LEFT);
                $username = $prefix . $suffix;
                if (strlen($username) > 50) {
                    throw new RuntimeException('Сформированный логин длиннее 50 символов');
                }
                $email = $username . '@load.invalid';

                $db->execute(
                    'INSERT INTO users '
                    . '(uid,username,email,password_hash,firstname,patronymic,lastname,phone,avatar,property,role,is_active,account_status,created_at,updated_at) '
                    . 'VALUES '
                    . '(:uid,:username,:email,:password_hash,:firstname,NULL,:lastname,NULL,NULL,:property,:role,1,\'active\',:created_at,:updated_at) '
                    . 'ON DUPLICATE KEY UPDATE '
                    . 'password_hash = VALUES(password_hash), is_active = 1, account_status = \'active\', updated_at = VALUES(updated_at)',
                    [
                        ':uid' => Uuid::v4(),
                        ':username' => $username,
                        ':email' => $email,
                        ':password_hash' => $passwordHash,
                        ':firstname' => 'Нагрузка',
                        ':lastname' => $suffix,
                        ':property' => json_encode(['performance_fixture' => true], JSON_THROW_ON_ERROR),
                        ':role' => Config::USER_ROLE_USER,
                        ':created_at' => $now,
                        ':updated_at' => $now,
                    ]
                );

                $line = json_encode(
                    ['username' => $username, 'password' => $password],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                if (fwrite($credentials, $line . PHP_EOL) === false) {
                    throw new RuntimeException('Не удалось записать файл учётных данных');
                }
                $created++;
            }
            $db->endTransaction(true);
        } catch (Throwable $e) {
            $db->endTransaction(false);
            throw $e;
        }
    }

    fflush($credentials);
    fclose($credentials);
    @chmod($output, 0600);

    echo 'Подготовлено синтетических пользователей: ' . $created . PHP_EOL;
    echo 'Файл учётных данных: ' . $output . PHP_EOL;
    echo "Пароль в консоль не выводится. Файл должен оставаться вне web-root и не попадать в Git.\n";
    DatabaseManager::resetInstance();
    exit(0);
} catch (Throwable $e) {
    if (isset($credentials) && is_resource($credentials)) {
        fclose($credentials);
    }
    DatabaseManager::resetInstance();
    fwrite(STDERR, 'Ошибка подготовки нагрузочных пользователей: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
