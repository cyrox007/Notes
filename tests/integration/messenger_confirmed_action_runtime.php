<?php

declare(strict_types=1);

// Контракт проверяет настоящий обработчик подтверждений без подключения к БД.
namespace Core {
    class Controller
    {
    }
}

namespace {
    require_once dirname(__DIR__, 2) . '/modules/messenger/controllers/MessengerRealtimeController.php';

    use App\Controllers\MessengerRealtimeController;

    function checkMessengerAction(bool $condition, string $message): void
    {
        if ($condition) {
            return;
        }

        fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
        exit(1);
    }

    $reflection = new \ReflectionClass(MessengerRealtimeController::class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('actionError');

    $check = static function (string $action, array $events) use ($method, $controller): ?string {
        return $method->invoke($controller, $action, $events);
    };

    $send = 'MessangerSocket:message_send';
    $edit = 'MessangerSocket:edit_message';

    checkMessengerAction(
        $check($send, [['action' => 'send_message', 'message' => ['uid' => 'test']]]) === null,
        'сохранённое сообщение ошибочно отклонено'
    );
    checkMessengerAction(
        $check($edit, [['action' => 'message_edited', 'message' => ['uid' => 'test']]]) === null,
        'изменённое сообщение ошибочно отклонено'
    );
    checkMessengerAction(
        $check($send, []) === 'Не получено подтверждение сохранения сообщения',
        'отсутствие подтверждения отправки не распознано'
    );
    checkMessengerAction(
        $check($edit, []) === 'Не получено подтверждение сохранения сообщения',
        'отсутствие подтверждения изменения не распознано'
    );
    checkMessengerAction(
        $check($send, [['action' => 'error', 'message' => 'Запись отклонена']]) === 'Запись отклонена',
        'отказ обработчика ошибочно принят за сохранение'
    );

    foreach (['MaintenanceMode', 'LicenseReadOnly', 'ModuleUnavailable'] as $denial) {
        checkMessengerAction(
            $check($send, [['action' => $denial]]) === 'Действие отклонено сервером',
            'серверный запрет ' . $denial . ' ошибочно принят'
        );
    }

    checkMessengerAction(
        $check('MessangerSocket:get_dialogs', []) === null,
        'чтение ошибочно требует подтверждения записи'
    );

    echo '[OK] Messenger отличает подтверждённую запись от отказа и молчания обработчика' . PHP_EOL;
}
