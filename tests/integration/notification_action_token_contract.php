<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationActionTokenStore.php';
use App\Services\Notifications\NotificationActionTokenStore;

function tokenContract(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
tokenContract(NotificationActionTokenStore::TTL_SECONDS === 900, 'Срок действия 15 минут');

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root . '/app/services/notifications/NotificationActionTokenStore.php');
$controller = (string) file_get_contents($root . '/modules/tasks/controllers/TaskMutationController.php');
$routes = (string) file_get_contents($root . '/modules/tasks/TasksRuntimeProvider.php');
$sql = (string) file_get_contents($root . '/database/migrations/20261021_notification_action_tokens.sql');
$manifest = json_decode((string) file_get_contents($root . '/database/migrations/manifest.json'), true);

foreach ([
    "hash('sha256', " . '$token)',
    'random_bytes(32)',
    'consumed_at IS NULL AND expires_at > UTC_TIMESTAMP()',
    'SET consumed_at = UTC_TIMESTAMP()',
    'recipient_user_id = :user',
    "'complete_task'",
] as $fragment) {
    tokenContract(str_contains($service, $fragment), 'Безопасное хранение токена: ' . $fragment);
}
tokenContract(!str_contains($sql, 'token VARCHAR')
    && str_contains($sql, 'uq_notification_action_token_hash')
    && str_contains($sql, 'fk_notification_action_token_notification')
    && in_array('20261021_notification_action_tokens.sql', $manifest['migrations'] ?? [], true),
    'Миграция должна быть включена и не содержать исходных токенов');
tokenContract(str_contains($routes, 'confirmFromExternalNotification')
    && str_contains($routes, 'RequireTasksUse::class, CSRFMiddleware::class')
    && str_contains($routes, "->add('GET', '/notification/{int:notificationId}/preview'")
    && str_contains($routes, "->add('POST', '/notification/{int:notificationId}/inspect'")
    && !str_contains($routes, "->add('GET', '/notification/{int:notificationId}/confirm'"),
    'Действие разрешено только защищённым POST');
tokenContract(str_contains($controller, '->consume(')
    && str_contains($controller, '->complete(')
    && str_contains($controller, "'action_token'")
    && str_contains($controller, 'Подтверждение истекло или уже использовано'),
    'Токен гасится до изменения задачи');
echo "Одноразовый токен: срок, погашение и защита маршрута проверены\n";
