<?php
declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$notificationId = max(1, (int) ($notificationId ?? 0));
ob_start();
?>
<section class="module-page-header module-page-header--tasks">
    <div>
        <h1>Подтверждение действия</h1>
        <p>Проверьте действие перед завершением задачи. Открытие ссылки ничего не изменяет.</p>
    </div>
</section>
<section class="tasks" data-notification-action-preview
         data-confirm-url="<?= $view->e($view->route('task_notification_confirm', ['notificationId' => $notificationId])) ?>">
    <div class="tasks__controls" style="max-width:600px">
        <h2>Отметить задачу выполненной?</h2>
        <p>Действие применяется только к вашей задаче. После подтверждения вернуть одноразовую ссылку в рабочее состояние нельзя.</p>
        <p data-notification-action-feedback role="status" aria-live="polite">Проверяем ссылку…</p>
        <button class="filter-btn" type="button" data-notification-action-confirm disabled>Подтвердить выполнение</button>
        <a class="filter-btn" href="<?= $view->e($view->route('tasks')) ?>">Вернуться к задачам</a>
    </div>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Подтверждение выполнения задачи',
    'sitename' => $sitename ?? 'Workspace Organizer',
    'version' => $version ?? '',
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $user ?? [],
    'workspaceAccess' => $workspaceAccess ?? [],
    'module_styles' => [$view->moduleAsset('tasks', 'style.css')],
    'module_scripts' => [$view->moduleAsset('tasks', 'notification-action-preview.js')],
], $content);
