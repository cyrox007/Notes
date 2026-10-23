<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = file_get_contents($root . '/modules/admin/controllers/NotificationSettingsController.php');
$view = file_get_contents($root . '/modules/admin/views/notifications.php');
if (!is_string($controller) || !is_string($view)) {
    throw new RuntimeException('Файлы экрана диагностики отсутствуют');
}
foreach ([
    "get('inspect_job', '')",
    'ctype_digit($jobRaw)',
    'NotificationLiveAcceptanceReport(DatabaseManager::getInstance())',
    "'receipt_inspection' => " . '$inspection',
] as $fragment) {
    if (!str_contains($controller, $fragment)) {
        throw new RuntimeException('Просмотр не проверяет обязательное условие');
    }
}
foreach ([
    'name="inspect_job"',
    "'admin_notifications'",
    "'recipient_receipt_status'",
    "'transport_accepted'",
    "'history'",
] as $fragment) {
    if (!str_contains($view, $fragment)) {
        throw new RuntimeException('В экране отсутствует элемент безопасного просмотра: ' . $fragment);
    }
}
if (str_contains($view, "<?= " . '$inspected[')) {
    throw new RuntimeException('Поля из базы должны проходить экранирование');
}
echo "Проверка предварительного просмотра и обезличивания пройдена\n";
