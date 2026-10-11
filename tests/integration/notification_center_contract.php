<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function notificationCenterAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$service = file_get_contents($root . '/app/services/notifications/DatabaseNotificationCenter.php');
$controller = file_get_contents($root . '/app/controllers/NotificationController.php');
$apiAuth = file_get_contents($root . '/app/middlewares/ApiLoginRequired.php');
$routes = file_get_contents($root . '/core/routerConfig.php');

notificationCenterAssert(is_string($service), 'Не удалось прочитать сервис центра уведомлений');
notificationCenterAssert(is_string($controller), 'Не удалось прочитать контроллер центра уведомлений');
notificationCenterAssert(is_string($apiAuth), 'Не удалось прочитать API-проверку сессии');
notificationCenterAssert(is_string($routes), 'Не удалось прочитать таблицу маршрутов');

foreach ([
    "n.recipient_user_id = :recipient_user_id",
    "recipient_user_id = :recipient_user_id",
    'ORDER BY n.created_at DESC, n.id DESC',
    "e.category = :category",
    "e.importance = :importance",
    "e.source_name = :source_name",
    "['all', 'unread', 'read']",
    'MAX_LIMIT = 100',
    'COALESCE(read_at, :read_at)',
    'notification_delivery_jobs',
    'dj_failed.state',
    "'delivery' => \$this->normalizeDelivery(\$row)",
    "'partial'",
] as $fragment) {
    notificationCenterAssert(
        str_contains($service, $fragment),
        'Сервис центра уведомлений обязан сохранять контракт: ' . $fragment
    );
}

foreach (['payload_json', 'config_json', 'secret_ref', 'error_summary'] as $forbidden) {
    notificationCenterAssert(
        !str_contains($service, $forbidden),
        'API центра уведомлений не должен читать чувствительное внутреннее поле: ' . $forbidden
    );
}

foreach ([
    "->group('/api/notifications')",
    "[NotificationController::class, 'index'], [ApiLoginRequired::class]",
    "[NotificationController::class, 'unreadCount'], [ApiLoginRequired::class]",
    "[NotificationController::class, 'preferences'], [ApiLoginRequired::class]",
    "[NotificationController::class, 'updatePreferences'], [ApiLoginRequired::class, CSRFMiddleware::class]",
    "[NotificationController::class, 'markRead'], [ApiLoginRequired::class, CSRFMiddleware::class]",
    "[NotificationController::class, 'markUnread'], [ApiLoginRequired::class, CSRFMiddleware::class]",
    "[NotificationController::class, 'markAllRead'], [ApiLoginRequired::class, CSRFMiddleware::class]",
] as $fragment) {
    notificationCenterAssert(
        str_contains($routes, $fragment),
        'Маршруты центра уведомлений обязаны сохранять защиту: ' . $fragment
    );
}

foreach ([
    "http_response_code(401)",
    "'code' => 'authentication_required'",
    "header('Cache-Control: no-store')",
] as $fragment) {
    notificationCenterAssert(
        str_contains($apiAuth, $fragment),
        'API-проверка сессии обязана сохранять контракт: ' . $fragment
    );
}
notificationCenterAssert(
    !str_contains($apiAuth, 'redirect('),
    'API-проверка сессии не должна перенаправлять JSON-клиент на HTML-форму входа'
);

foreach ([
    "'unread_count'",
    "'invalid_notification_request'",
    "'notification_center_failed'",
    "header('Cache-Control: no-store')",
] as $fragment) {
    notificationCenterAssert(
        str_contains($controller, $fragment),
        'Контроллер центра уведомлений обязан сохранять контракт: ' . $fragment
    );
}

fwrite(STDOUT, "[OK] архитектурный контракт API центра уведомлений 1.1.0\n");
