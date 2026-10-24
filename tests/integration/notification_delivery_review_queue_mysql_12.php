<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/DatabaseManager.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationDeliveryReviewQueue.php';

use App\Services\Notifications\NotificationDeliveryReviewQueue;
use Core\DatabaseManager;

function reviewAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Очередь ручной приёмки: ' . $message);
    }
}

$database = (string) (getenv('TEST_DB_NAME') ?: '');
reviewAssert($database !== '' && str_contains(strtolower($database), 'test'),
    'требуется изолированная тестовая БД');

$pdo = new PDO(
    'mysql:host=' . (getenv('TEST_DB_HOST') ?: '127.0.0.1')
    . ';port=' . (getenv('TEST_DB_PORT') ?: '3306')
    . ';dbname=' . $database . ';charset=utf8mb4',
    getenv('TEST_DB_USER') ?: 'workspace',
    getenv('TEST_DB_PASS') ?: 'workspace',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$pdo->exec(
    'CREATE TABLE notification_delivery_jobs(
        id INT PRIMARY KEY, channel VARCHAR(32),state VARCHAR(32), attempts INT,
        last_result_code VARCHAR(80), updated_at DATETIME,
        recipient_ref VARCHAR(255), secret_ref VARCHAR(255), message_body TEXT)'
);
$pdo->exec(
    'CREATE TABLE notification_receipt_attestations(
        job_id INT PRIMARY KEY,receipt_state VARCHAR(16))'
);

try {
    $write = $pdo->prepare(
        'INSERT INTO notification_delivery_jobs VALUES(?,?,?,?,?,?,?,?,?)'
    );
    $insert = static function (
        int $id, string $state, string $updated, string $code = 'smtp_sent'
    ) use ($write): void {
        $write->execute([
            $id, 'email', $state, 2, $code, $updated,
            'personal-address@example.test', 'env:SECRET_REF', 'Тайный текст задачи'
        ]);
    };

    $insert(1, 'sent', '2026-10-23 09:00:00');
    $insert(2, 'sent', '2026-10-23 10:00:00');
    $insert(3, 'sent', '2026-10-23 11:00:00');
    $insert(4, 'failed', '2026-10-23 12:00:00', 'smtp_permanent');
    $insert(5, 'retry', '2026-10-23 13:00:00');
    $insert(6, 'pending', '2026-10-23 14:00:00');
    $pdo->exec("INSERT INTO notification_receipt_attestations VALUES
        (2,'received'),(3,'not_received')");

    $queue = new NotificationDeliveryReviewQueue(DatabaseManager::forConnection($pdo));
    $data = $queue->overview();
    $attention = array_column($data['attention'], 'id');
    reviewAssert($attention === [4, 3], 'ошибка и неподтверждённое получение не стоят первыми');
    $unverified = array_column($data['unverified'], 'id');
    reviewAssert($unverified === [1], 'неверно выбраны ожидающие проверки сообщения');
    reviewAssert($data['attention'][1]['receipt_state'] === 'not_received',
        'не сохранена отметка отрицательной проверки');
    reviewAssert($data['unverified'][0]['receipt_state'] === 'not_verified',
        'отсутствующая отметка не отличается от отрицательной');

    for ($id = 7; $id <= 37; ++$id) {
        $insert($id, 'sent', '2026-10-23 15:00:00');
    }
    $limited = $queue->overview();
    reviewAssert(count($limited['unverified']) === 20,
        'ограничение размера очереди не действует');
    reviewAssert($limited['unverified'][0]['id'] === 37
        && $limited['unverified'][19]['id'] === 18,
        'порядок при совпадающих датах нестабилен');

    reviewAssert(($limited['pagination']['unverified']['has_next'] ?? null) === true
        && ($limited['pagination']['unverified']['has_previous'] ?? null) === false,
        'не определено наличие следующей страницы');

    $second = $queue->overview(1, 2);
    reviewAssert(array_column($second['attention'], 'id') === [4, 3],
        'переключение второй очереди изменило первую');
    reviewAssert($second['unverified'][0]['id'] === 17
        && end($second['unverified'])['id'] === 1
        && count($second['unverified']) === 12,
        'неверная вторая страница неподтверждённых сообщений');
    reviewAssert($second['pagination']['unverified']['has_previous']
        && !$second['pagination']['unverified']['has_next'],
        'неверная навигация на последней странице');

    for ($id = 38; $id <= 65; ++$id) {
        $insert($id, 'failed', '2026-10-23 16:00:00', 'smtp_permanent');
    }
    $both = $queue->overview(2, 2);
    reviewAssert($both['attention'][0]['id'] === 45
        && end($both['attention'])['id'] === 3,
        'вторая страница ошибок должна сохранять старые обращения');
    reviewAssert($both['unverified'][0]['id'] === 17,
        'раздельная навигация смешала категории');
    reviewAssert($both['pagination']['attention']['has_previous']
        && !$both['pagination']['attention']['has_next'],
        'неверная навигация по окончательным ошибкам');
    foreach ([0, 1001] as $invalidPage) {
        $rejected = false;
        try { $queue->overview($invalidPage, 1); }
        catch (InvalidArgumentException) { $rejected = true; }
        reviewAssert($rejected, 'неверный номер страницы принят');
    }

    $output = json_encode($both, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach (['personal-address', 'SECRET_REF', 'Тайный текст', 'message_body', 'recipient_ref'] as $fragment) {
        reviewAssert(!str_contains($output, $fragment),
            'список раскрыл приватные данные: ' . $fragment);
    }
    echo "Очередь приёмки: внимание, проверка, сортировка и безопасность проверены\n";
} finally {
    $pdo->exec('DROP TABLE notification_receipt_attestations');
    $pdo->exec('DROP TABLE notification_delivery_jobs');
}
