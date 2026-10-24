<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = file_get_contents($root . '/modules/admin/views/notifications.php');
$controller = file_get_contents($root . '/modules/admin/controllers/NotificationSettingsController.php');
$service = file_get_contents($root . '/app/services/notifications/NotificationDeliveryReviewQueue.php');
if (!is_string($view) || !is_string($controller) || !is_string($service)) {
    throw new RuntimeException('Не найдены файлы интерфейса приёмки');
}
foreach ([
    'Требуют внимания',
    'Ожидают подтверждения',
    'История и проверка',
    'inspect_job=',
    'name="job_id"',
    'readonly',
    'attention_page',
    'unverified_page',
    'Предыдущая страница',
    'Следующая страница',
    'has_previous',
    'has_next',
    "receipt_review",
] as $part) {
    if (!str_contains($view, $part) && !str_contains($controller, $part)) {
        throw new RuntimeException('Отсутствует элемент интерфейса: ' . $part);
    }
}
if (!str_contains($controller, 'NotificationDeliveryReviewQueue(')
    || !str_contains($controller, 'RequireAdminSettingsManage')
        && !str_contains(file_get_contents($root . '/modules/admin/AdminRuntimeProvider.php'), 'RequireAdminSettingsManage')) {
    throw new RuntimeException('Обзор нельзя открывать вне защищённого маршрута');
}
foreach ([
    'LEFT JOIN notification_receipt_attestations',
    "j.state='failed'",
    "r.receipt_state='not_received'",
    "j.state='sent'",
    'r.job_id IS NULL',
    'LIMIT ',
] as $part) {
    if (!str_contains($service, $part)) {
        throw new RuntimeException('Нарушена политика очереди ручной приёмки: ' . $part);
    }
}
foreach (['n.title', 'n.body', 'recipient_ref', 'secret_ref', 'config_json'] as $part) {
    if (str_contains($service, $part)) {
        throw new RuntimeException('Недопустимое чтение персональных данных: ' . $part);
    }
}
echo "Интерфейс приёмки: защищённый маршрут, действия и приватность проверены\n";
