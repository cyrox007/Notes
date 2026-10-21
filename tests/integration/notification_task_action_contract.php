<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/modules/tasks/services/TaskNotificationActionService.php';

use App\Services\Tasks\TaskNotificationActionService;

$valid = ['source_name' => 'tasks', 'event_name' => 'tasks.deadline', 'payload_json' => '{"task_uid":"0123456789abcdef0123456789abcdef"}'];
function actionCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

actionCheck(TaskNotificationActionService::taskUidFromEvent($valid) === '0123456789abcdef0123456789abcdef',
    'Разрешённое событие Tasks должно содержать UID');
foreach ([
    ['source_name' => 'other'],
    ['event_name' => 'other.deadline'],
    ['payload_json' => '{"task_uid":"../../admin"}'],
    ['payload_json' => '{"task_uid":12}'],
    ['payload_json' => '[]'],
] as $change) {
    actionCheck(TaskNotificationActionService::taskUidFromEvent(array_replace($valid, $change)) === null,
        'Недопустимое событие не должно разрешать действие');
}

$root = dirname(__DIR__, 2);
$provider = (string) file_get_contents($root . '/modules/tasks/TasksRuntimeProvider.php');
$service = (string) file_get_contents($root . '/modules/tasks/services/TaskNotificationActionService.php');
$center = (string) file_get_contents($root . '/app/services/notifications/DatabaseNotificationCenter.php');
$ui = (string) file_get_contents($root . '/assets/js/notification-center.js');

actionCheck(str_contains($provider, "RequireTasksUse::class, CSRFMiddleware::class], 'task_notification_complete'"),
    'Маршрут требует авторизацию, право Tasks и защиту от подделки запроса');
actionCheck(str_contains($service, 'recipient_user_id = :user')
    && str_contains($service, 'user_id = :user AND is_deleted = 0')
    && str_contains($service, 'TaskVersionMutationService($this->db)')
    && str_contains($service, 'notification-task-complete-'),
    'Действие проверяет обе принадлежности и использует версионную команду');
$availability = (string) file_get_contents($root . '/app/services/notifications/NotificationActionAvailability.php');
actionCheck(str_contains($center, 'NotificationActionAvailability($this->db)')
    && !str_contains($center, 'payload_json')
    && str_contains($availability, 'recipient_user_id = :user')
    && str_contains($availability, 'complete_task'),
    'Внутреннее событие не должно читаться непосредственно из API центра');
actionCheck(str_contains($ui, "item.actions.includes('complete_task')")
    && str_contains($ui, "'X-CSRF-Token': token")
    && str_contains($ui, "method: 'POST'"),
    'Интерфейс запускает действие через защищённый POST');

echo "Действие из уведомления Tasks проверено\n";
