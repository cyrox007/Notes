<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$routes = (string) file_get_contents($root . '/modules/tasks/TasksRuntimeProvider.php');
$controller = (string) file_get_contents($root . '/modules/tasks/controllers/TaskMutationController.php');
$view = (string) file_get_contents($root . '/modules/tasks/views/notification-confirm.php');
$javascript = (string) file_get_contents($root . '/modules/tasks/assets/notification-action-preview.js');

function previewContract(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

previewContract(str_contains($routes, "'previewExternalNotification'")
    && str_contains($routes, "'inspectExternalNotification'")
    && str_contains($routes, 'RequireTasksUse::class, CSRFMiddleware::class'),
    'Предпросмотр и проверка должны работать только в авторизованной сессии');
previewContract(str_contains($controller, 'previewExternalNotification')
    && str_contains($controller, 'recipient_user_id = :user')
    && str_contains($controller, "header('Cache-Control: no-store')")
    && str_contains($controller, "header('Referrer-Policy: no-referrer')"),
    'Страница должна проверять принадлежность и не кэшироваться');
previewContract(str_contains($controller, '->inspect(')
    && str_contains($controller, '$match !== $notificationId')
    && str_contains($view, 'data-inspect-url')
    && str_contains($view, 'data-confirm-url'),
    'Проверка подтверждения должна быть привязана к уведомлению');
previewContract(str_contains($javascript, 'window.location.hash.slice(1)')
    && str_contains($javascript, 'history.replaceState')
    && str_contains($javascript, "method: 'POST'")
    && str_contains($javascript, 'root.dataset.inspectUrl')
    && str_contains($javascript, 'root.dataset.confirmUrl'),
    'Токен не должен передаваться в GET или храниться в строке браузера');
previewContract(!str_contains($javascript, 'localStorage')
    && !str_contains($javascript, 'sessionStorage'),
    'Одноразовый токен не сохраняется в браузерном хранилище');

echo "Безопасный предпросмотр внешнего подтверждения проверен\n";
