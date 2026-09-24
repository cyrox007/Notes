<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Команда доступна только из CLI.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/core/Environment.php';
if (is_file($root . '/.env')) {
    \Core\Environment::load($root . '/.env');
}
require_once $root . '/core/config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/app/handlers/CryptMethods.php';
require_once $root . '/modules/messenger/handlers/MessengerCrypto.php';
require_once $root . '/app/services/MaintenanceModeService.php';
require_once $root . '/app/services/DataKeyRotationService.php';

use App\Services\DataKeyRotationService;
use App\Services\MaintenanceModeService;
use Core\DatabaseManager;

$options = getopt('', [
    'transaction:',
    'scope:',
    'old-unique-key-file:',
    'new-unique-key-file:',
    'old-msg-key-file:',
    'new-msg-key-file:',
    'state-root:',
    'batch-size:',
    'max-batches:',
    'rollback',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo "Использование: php bin/rotate_data_keys.php --transaction=ID --scope=all|notes|messenger [параметры]\n";
    echo "  Область notes означает весь домен UNIQUE_KEY: заметки, историю заметок и TOTP-секреты.\n";
    echo "  --old-unique-key-file=PATH  Текущий UNIQUE_KEY в файле с chmod 600.\n";
    echo "  --new-unique-key-file=PATH  Новый UNIQUE_KEY в файле с chmod 600.\n";
    echo "  --old-msg-key-file=PATH     Текущий MSG_SECRET_KEY в файле с chmod 600.\n";
    echo "  --new-msg-key-file=PATH     Новый MSG_SECRET_KEY в файле с chmod 600.\n";
    echo "  --batch-size=N              Размер транзакционного пакета 1..5000 (по умолчанию 250).\n";
    echo "  --max-batches=N             Корректно остановиться после N пакетов; 0 — выполнить всё.\n";
    echo "  --state-root=PATH           Внешний каталог возобновляемого состояния.\n";
    echo "  --rollback                  Перешифровать строки с новыми ключами обратно старыми.\n";
    echo "  --json                      Машиночитаемый результат.\n";
    echo "\nСырые значения ключей намеренно не принимаются через командную строку.\n";
    exit(0);
}

$json = isset($options['json']);
$transaction = trim((string) ($options['transaction'] ?? ''));
$scope = strtolower(trim((string) ($options['scope'] ?? 'all')));
$batchSize = isset($options['batch-size']) ? (int) $options['batch-size'] : 250;
$maxBatches = isset($options['max-batches']) ? (int) $options['max-batches'] : 0;
$rollback = isset($options['rollback']);
$stateRoot = trim((string) ($options['state-root'] ?? ''));

function rotationSecretFile(?string $path, string $label): string
{
    $path = trim((string) $path);
    if ($path === '') {
        throw new RuntimeException("Обязателен параметр {$label}");
    }
    if (is_link($path)) {
        throw new RuntimeException("{$label} не должен быть символической ссылкой");
    }
    $resolved = realpath($path);
    if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
        throw new RuntimeException("{$label}: файл отсутствует или недоступен для чтения");
    }
    $size = filesize($resolved);
    if (!is_int($size) || $size < 32 || $size > 4096) {
        throw new RuntimeException("{$label} должен содержать от 32 до 4096 байт");
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        $perms = fileperms($resolved);
        if (is_int($perms) && (($perms & 0077) !== 0)) {
            throw new RuntimeException("{$label}: права слишком широкие; требуется chmod 600");
        }
    }
    $bytes = file_get_contents($resolved);
    $secret = is_string($bytes) ? trim($bytes) : '';
    if (strlen($secret) < 32) {
        throw new RuntimeException("{$label} должен содержать не менее 32 непробельных символов");
    }
    return $secret;
}

try {
    if ($transaction === '') {
        throw new RuntimeException('--transaction обязателен и должен совпадать с активным maintenance mode');
    }
    if (!in_array($scope, ['all', 'notes', 'messenger'], true)) {
        throw new RuntimeException('--scope должен быть all, notes или messenger');
    }

    $secrets = [];
    if ($scope === 'all' || $scope === 'notes') {
        $secrets['old_unique'] = rotationSecretFile($options['old-unique-key-file'] ?? null, '--old-unique-key-file');
        $secrets['new_unique'] = rotationSecretFile($options['new-unique-key-file'] ?? null, '--new-unique-key-file');
    }
    if ($scope === 'all' || $scope === 'messenger') {
        $secrets['old_msg'] = rotationSecretFile($options['old-msg-key-file'] ?? null, '--old-msg-key-file');
        $secrets['new_msg'] = rotationSecretFile($options['new-msg-key-file'] ?? null, '--new-msg-key-file');
    }

    $service = new DataKeyRotationService(
        DatabaseManager::getInstance(),
        new MaintenanceModeService(),
        $stateRoot !== '' ? $stateRoot : null,
        $root
    );
    $result = $service->run(
        $transaction,
        $scope,
        $secrets,
        $batchSize,
        $maxBatches,
        $rollback
    );

    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        $directionLabel = ($result['direction'] ?? '') === 'rollback' ? 'откат' : 'прямая ротация';
        $scopeLabel = match ($result['scope'] ?? '') {
            'all' => 'все защищённые данные',
            'notes' => 'данные UNIQUE_KEY (заметки, история и TOTP)',
            'messenger' => 'Messenger',
            default => (string) ($result['scope'] ?? ''),
        };
        echo 'Ротация ключей данных: ' . ($result['complete'] ? 'ЗАВЕРШЕНА' : 'НЕ ЗАВЕРШЕНА; требуется продолжение') . PHP_EOL;
        echo 'Направление: ' . $directionLabel . PHP_EOL;
        echo 'Область: ' . $scopeLabel . PHP_EOL;
        echo 'Пакетов в этом запуске: ' . $result['batches_this_run'] . PHP_EOL;
        echo 'Состояние: ' . $result['state_path'] . PHP_EOL;
        if ($result['complete']) {
            echo "Проверка: OK\n";
            if (!$rollback) {
                echo "Оставьте maintenance активным. Обновите UNIQUE_KEY/MSG_SECRET_KEY в secret manager или .env, перезапустите HTTP/WS workers, выполните проверку и только затем выйдите из maintenance.\n";
            } else {
                echo "Проверка отката: OK. База данных снова читается исходными ключами.\n";
            }
        }
    }
    exit(0);
} catch (Throwable $e) {
    if ($json) {
        echo json_encode([
            'status' => 'fail',
            'error' => 'data_key_rotation_failed',
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, '[ОШИБКА] ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
}
