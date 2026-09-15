<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$pageTitle = isset($title) && is_scalar($title) ? (string) $title : 'Ошибка';
$pageMessage = isset($message) && is_scalar($message) ? (string) $message : 'Не удалось выполнить запрос.';
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];

ob_start();
?>
<section class="workspace-error" role="alert">
    <div class="workspace-error__card">
        <span class="workspace-error__eyebrow">Workspace Organizer</span>
        <h1><?= $view->e($pageTitle) ?></h1>
        <p><?= $view->e($pageMessage) ?></p>
        <div class="workspace-error__actions">
            <button type="button" class="btn btn-secondary" onclick="history.back()">Назад</button>
            <?php if (!empty($access['notes']) || !empty($access['tasks']) || !empty($access['files']) || !empty($access['messenger']) || !empty($access['profile'])): ?>
                <a class="btn btn-primary" href="<?= $view->e($view->route('main')) ?>">На главную</a>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => $pageTitle,
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $base_url ?? '',
    'base_path' => $base_path ?? '',
    'user' => $user ?? [],
    'workspaceAccess' => $access,
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
], $content);
