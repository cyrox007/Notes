<?php

declare(strict_types=1);

// Запуск: php bin/messenger_doctor.php
// Только чтение конфигурации и БД; ключи и тексты сообщений не выводятся.
$root = dirname(__DIR__);
require_once $root . '/core/Environment.php';
require_once $root . '/core/WebSocketEndpoint.php';
require_once $root . '/modules/messenger/handlers/MessengerCrypto.php';

use App\Helpers\MessengerCrypto;
use Core\Environment;
use Core\WebSocketEndpoint;

$failures = 0;

function messengerDoctorResult(bool $passed, string $label): void
{
    global $failures;
    fwrite(STDOUT, ($passed ? '[OK] ' : '[ОШИБКА] ') . $label . PHP_EOL);
    if (!$passed) {
        $failures++;
    }
}

fwrite(STDOUT, "Диагностика Messenger 1.2 (без изменения данных)\n");
messengerDoctorResult(PHP_VERSION_ID >= 80200, 'Интерпретатор PHP 8.2 или новее');

try {
    Environment::load($root . '/.env');
    messengerDoctorResult(true, 'Файл .env загружен');
} catch (Throwable) {
    messengerDoctorResult(false, 'Не удалось прочитать .env — проверьте путь и права');
    exit(1);
}

$cryptoReady = function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
    && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt');
messengerDoctorResult($cryptoReady, 'Расширение libsodium с XChaCha20-Poly1305 доступно');

$secret = trim((string) (Environment::get('MSG_SECRET_KEY') ?: ''));
messengerDoctorResult(strlen($secret) >= 32, 'MSG_SECRET_KEY задан и содержит не менее 32 символов (значение скрыто)');

if ($cryptoReady && strlen($secret) >= 32) {
    try {
        $uid = bin2hex(random_bytes(16));
        $sample = 'Проверка локального шифрования Messenger';
        $ciphertext = MessengerCrypto::encrypt($sample, $uid);
        messengerDoctorResult(
            str_starts_with($ciphertext, 'v2:') && MessengerCrypto::decrypt($ciphertext, $uid) === $sample,
            'Шифрование и обратное чтение в памяти работают без записи в базу'
        );
    } catch (Throwable) {
        messengerDoctorResult(false, 'Шифрование или обратное чтение сообщения не работает');
    }
}

try {
    if (WebSocketEndpoint::enabled()) {
        $ticketSecret = trim((string) (Environment::get('WS_TICKET_SECRET') ?: ''));
        messengerDoctorResult(
            strlen($ticketSecret) >= 32,
            'Подписывающий ключ WebSocket задан (значение скрыто)'
        );
        fwrite(STDOUT, "[ИНФО] WebSocket включён; состояние его процесса проверяется отдельно командой php bin/ws_doctor.php\n");
    } else {
        fwrite(STDOUT, "[ИНФО] WebSocket отключён — Messenger использует резервный HTTP-канал\n");
    }
} catch (Throwable) {
    messengerDoctorResult(false, 'Некорректная конфигурация WebSocket');
}

$prefix = (string) (Environment::get('DBPREFIX') ?: '');
if ($prefix !== '' && preg_match('/^[A-Za-z0-9_]+$/D', $prefix) !== 1) {
    messengerDoctorResult(false, 'Префикс таблиц БД содержит недопустимые символы');
    exit(1);
}

try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            (string) (Environment::get('DBHOST') ?: '127.0.0.1'),
            (string) (Environment::get('DBPORT') ?: '3306'),
            (string) Environment::get('DBNAME')
        ),
        (string) Environment::get('DBUSER'),
        (string) Environment::get('DBPASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    messengerDoctorResult(true, 'Подключение к рабочей БД успешно');
} catch (Throwable) {
    messengerDoctorResult(false, 'Не удалось подключиться к БД — проверьте реквизиты, порт и доступность MySQL');
    exit(1);
}

foreach (['users', 'dialogs', 'user_to_dialogs', 'messages', 'messenger_attachments'] as $table) {
    try {
        $pdo->query('SELECT 1 FROM ' . $prefix . $table . ' LIMIT 0');
        messengerDoctorResult(true, 'Таблица ' . $prefix . $table . ' доступна');
    } catch (Throwable) {
        messengerDoctorResult(false, 'Таблица ' . $prefix . $table . ' отсутствует или недоступна');
    }
}

try {
    $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . $prefix . 'messages')->fetchColumn();
    fwrite(STDOUT, '[ИНФО] Количество сохранённых сообщений: ' . $count . PHP_EOL);
} catch (Throwable) {
    messengerDoctorResult(false, 'Не удалось прочитать число сообщений');
}

fwrite(STDOUT, $failures === 0
    ? "[ИТОГ] Основные зависимости Messenger готовы; для проверки доставки нужны две сессии\n"
    : "[ИТОГ] Найдены ошибки; устраните их до повторной проверки Messenger\n"
);
exit($failures === 0 ? 0 : 1);
