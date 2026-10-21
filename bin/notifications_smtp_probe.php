<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);
require_once $root . '/core/Config.php';

use App\Services\Notifications\DatabaseNotificationEmailRecipientResolver;
use App\Services\Notifications\EnvironmentNotificationSecretResolver;
use App\Services\Notifications\SmtpNotificationChannel;
use App\Services\Notifications\SocketSmtpTransport;
use Core\CliRuntime;
use Core\DatabaseManager;

$options = getopt('', ['config-id:', 'user-id:', 'send', 'json', 'help']);
if (isset($options['help'])) {
    echo "Использование: php bin/notifications_smtp_probe.php --config-id=N --user-id=N [--send] [--json]\n";
    echo "Без --send проверяет конфигурацию и получателя; с --send отправляет одно пробное письмо.\n";
    exit(0);
}

$json = isset($options['json']);
try {
    $configIdRaw = $options['config-id'] ?? '';
    $userIdRaw = $options['user-id'] ?? '';
    if (!is_string($configIdRaw) || !ctype_digit($configIdRaw)
        || !is_string($userIdRaw) || !ctype_digit($userIdRaw)) {
        throw new InvalidArgumentException('Требуются положительные числовые --config-id и --user-id');
    }

    $configId = (int) $configIdRaw;
    $userId = (int) $userIdRaw;
    if ($configId < 1 || $userId < 1) {
        throw new InvalidArgumentException('Идентификаторы должны быть положительными');
    }

    $db = DatabaseManager::getInstance();
    $config = $db->fetchOne(
        "SELECT id, config_json, secret_ref
         FROM notification_channel_configs
         WHERE id = :id AND channel = 'email' AND enabled = 1
           AND owner_user_id IS NULL
         LIMIT 1",
        [':id' => $configId]
    );
    if ($config === null) {
        throw new InvalidArgumentException('Активная глобальная конфигурация SMTP не найдена');
    }

    $recipientResolver = new DatabaseNotificationEmailRecipientResolver($db);
    if ($recipientResolver->emailForUser($userId) === null) {
        throw new InvalidArgumentException('Указанный пользователь неактивен или не имеет корректного E-mail');
    }

    $result = [
        'status' => 'ready',
        'config_id' => $configId,
        'user_id' => $userId,
        'sent' => false,
        'delivery_confirmed' => false,
        'message' => 'Проверена доступность конфигурации и адреса. Передача письма не выполнялась.',
    ];

    if (isset($options['send'])) {
        $channel = new SmtpNotificationChannel(
            $recipientResolver,
            new EnvironmentNotificationSecretResolver(),
            new SocketSmtpTransport(),
            ''
        );
        $delivery = $channel->deliver([
            'recipient_user_id' => $userId,
            'config_json' => $config['config_json'],
            'secret_ref' => $config['secret_ref'],
            'title' => 'Проверка почтовых уведомлений Notes',
            'body' => 'Это пробное письмо отправлено по явной команде администратора. Если вы его получили, подтвердите доставку вручную.',
        ]);

        $result['status'] = $delivery->success ? 'accepted_by_smtp' : 'smtp_failed';
        $result['sent'] = $delivery->success;
        $result['code'] = $delivery->code;
        $result['retryable'] = $delivery->retryable;
        $result['message'] = $delivery->success
            ? 'SMTP-сервер принял пробное письмо. Получение адресатом ещё не подтверждено.'
            : 'Передача завершилась ошибкой. Диагностический код не содержит учётных данных.';
    }

    if ($json) {
        CliRuntime::writeJson($result, true);
    } else {
        echo $result['message'] . PHP_EOL;
        echo 'Код: ' . ($result['code'] ?? 'preflight_ok') . PHP_EOL;
    }

    DatabaseManager::resetInstance();
    exit($result['status'] === 'smtp_failed' ? 3 : 0);
} catch (Throwable $exception) {
    DatabaseManager::resetInstance();
    // Не передаём в общий обработчик текст исключения из транспортного слоя:
    // диагностический вывод не должен раскрывать конфигурацию или секреты.
    $error = [
        'status' => 'error',
        'code' => 'smtp_probe_preflight_failed',
        'message' => 'Не удалось подготовить проверку SMTP. Убедитесь, что миграции применены, выбран действующий канал и пользователь.',
    ];
    if ($json) {
        CliRuntime::writeJson($error, true);
    } else {
        fwrite(STDERR, $error['message'] . PHP_EOL);
    }
    exit(2);
}
