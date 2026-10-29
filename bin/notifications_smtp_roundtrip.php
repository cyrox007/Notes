<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);
require_once $root . '/core/Config.php';
require_once $root . '/app/services/notifications/NotificationService.php';
require_once $root . '/app/services/notifications/NotificationDeliveryRuntime.php';
require_once $root . '/app/services/notifications/DatabaseNotificationEmailRecipientResolver.php';

use App\Services\Notifications\DatabaseNotificationEmailRecipientResolver;
use App\Services\Notifications\NotificationDeliveryRuntime;
use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\NotificationService;
use Core\DatabaseManager;

$options = getopt('', ['user-id:', 'category:', 'confirm:', 'json', 'help']);
if (isset($options['help'])) {
    echo "Сквозная проверка отправки SMTP на установленном Notes.\n";
    echo "Использование: php bin/notifications_smtp_roundtrip.php --user-id=N --category=ИМЯ "
        . "--confirm=SEND_ONE_LIVE_EMAIL [--json]\n";
    echo "Публикует ровно одно событие, проверяет создание очереди и запускает целевую попытку SMTP.\n";
    echo "Проверка принимает сообщение почтовым сервером, но не доказывает получение письма.\n";
    exit(0);
}

$json = isset($options['json']);
$result = [
    'status' => 'not_started',
    'published' => false,
    'email_job_created' => false,
    'smtp_accepted' => false,
    'recipient_confirmed' => false,
];

try {
    if (($options['confirm'] ?? null) !== 'SEND_ONE_LIVE_EMAIL') {
        throw new InvalidArgumentException('Требуется явное подтверждение одной реальной отправки');
    }
    $userIdRaw = $options['user-id'] ?? '';
    $category = $options['category'] ?? '';
    if (!is_string($userIdRaw) || preg_match('/^[1-9][0-9]{0,11}$/D', $userIdRaw) !== 1
        || !is_string($category) || preg_match('/^[a-z][a-z0-9_.-]{1,79}$/D', $category) !== 1) {
        throw new InvalidArgumentException('Укажите действующий user-id и точную категорию подписки');
    }

    $userId = (int) $userIdRaw;
    $db = DatabaseManager::getInstance();
    if ((new DatabaseNotificationEmailRecipientResolver($db))->emailForUser($userId) === null) {
        throw new InvalidArgumentException('Получатель недоступен или его адрес некорректен');
    }
    $subscription = $db->fetchOne(
        'SELECT user_id FROM notification_subscriptions '
        . 'WHERE user_id=:user AND category=:category AND enabled=1 AND email_enabled=1 LIMIT 1',
        ['user' => $userId, 'category' => $category]
    );
    if ($subscription === null) {
        throw new InvalidArgumentException('У пользователя не включена SMTP-подписка этой категории');
    }
    $config = $db->fetchOne(
        "SELECT id FROM notification_channel_configs "
        . "WHERE channel='email' AND enabled=1 AND (owner_user_id IS NULL OR owner_user_id=:user) "
        . 'ORDER BY owner_user_id IS NULL ASC,id ASC LIMIT 1',
        ['user' => $userId]
    );
    if ($config === null) {
        throw new InvalidArgumentException('Для пользователя нет активной конфигурации SMTP');
    }

    // Письмо отправляется только по явно подтверждённой команде оператора.
    // В отчёт не попадают адрес, сервер SMTP, ссылка на пароль и содержимое сообщений.
    $nonce = bin2hex(random_bytes(12));
    $event = new NotificationEvent(
        'operator.smtp_roundtrip',
        1,
        'smtp-roundtrip-' . $nonce,
        'operator.smtp_roundtrip',
        $category,
        NotificationEvent::IMPORTANCE_NORMAL,
        'Сквозная проверка почты Notes',
        'Это одно пробное уведомление для проверки канала SMTP. Подтвердите получение вручную.',
        [$userId],
        ['probe' => 'smtp_roundtrip'],
        '/notifications/'
    );
    $published = (new NotificationService())->publish($event);
    $result['published'] = ($published['notification_ids'] ?? []) !== [];
    $result['event_id'] = (int) ($published['event_id'] ?? 0);
    if (!$result['published']) {
        $result['status'] = 'recipient_not_eligible';
    } else {
        $notificationId = (int) $published['notification_ids'][0];
        $row = $db->fetchOne(
            "SELECT id,state FROM notification_delivery_jobs "
            . "WHERE notification_id=:notification AND channel='email' ORDER BY id DESC LIMIT 1",
            ['notification' => $notificationId]
        );
        if ($row === null) {
            $result['status'] = 'email_job_not_created';
        } else {
            $jobId = (int) $row['id'];
            $result['email_job_created'] = true;
            $result['job_id'] = $jobId;
            $workerId = 'smtp-roundtrip-' . $nonce;
            $stats = (new NotificationDeliveryRuntime($db))->runJob($workerId, $jobId);
            $attempt = $db->fetchOne(
                'SELECT state,last_result_code,attempts FROM notification_delivery_jobs WHERE id=:id',
                ['id' => $jobId]
            );
            $result['delivery_state'] = (string) ($attempt['state'] ?? 'unknown');
            $result['result_code'] = (string) ($attempt['last_result_code'] ?? 'none');
            $result['attempts'] = (int) ($attempt['attempts'] ?? 0);
            $result['claimed'] = (int) ($stats['claimed'] ?? 0);
            $result['smtp_accepted'] = $result['delivery_state'] === 'sent'
                && (int) ($stats['sent'] ?? 0) === 1;
            $result['status'] = $result['smtp_accepted']
                ? 'accepted_by_smtp' : 'delivery_not_confirmed';
        }
    }

    DatabaseManager::resetInstance();
    if ($json) {
        \Core\CliRuntime::writeJson($result, true);
    } else {
        echo 'Сквозная проверка SMTP: ' . $result['status'] . PHP_EOL;
        echo 'Событие: ' . ($result['event_id'] ?? 'нет') . PHP_EOL;
        echo 'Задание: ' . ($result['job_id'] ?? 'не создано') . PHP_EOL;
        echo 'Состояние: ' . ($result['delivery_state'] ?? 'не запускалось') . PHP_EOL;
        echo 'Код результата: ' . ($result['result_code'] ?? 'неизвестно') . PHP_EOL;
        echo "Получение письма адресатом необходимо проверить отдельно.\n";
    }
    exit($result['smtp_accepted'] ? 0 : 3);
} catch (Throwable $error) {
    DatabaseManager::resetInstance();
    // Не выводим исключение: оно может содержать детали сетевого соединения и окружения.
    $result['status'] = 'probe_failed';
    $result['error_type'] = $error::class;
    if ($json) {
        \Core\CliRuntime::writeJson($result, true);
    } else {
        fwrite(STDERR, 'Сквозная проверка SMTP остановлена. Проверьте конфигурацию, подписку и журнал очереди.' . PHP_EOL);
    }
    exit(2);
}
