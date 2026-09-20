<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$displayName = trim((string) ($currentUser['firstname'] ?? ''));
if ($displayName === '') {
    $displayName = (string) ($currentUser['username'] ?? '');
}
if ($displayName === '') {
    $displayName = 'пользователь';
}

ob_start();
?>
<section class="workspace-home">
    <header class="workspace-home__hero">
        <div class="workspace-home__hero-copy">
            <span class="workspace-home__eyebrow">Notes <?= $view->e($workspaceVersion !== '' ? $workspaceVersion : '1.0') ?></span>
            <h1>Добро пожаловать, <?= $view->e($displayName) ?></h1>
            <p>Всё рабочее пространство — заметки, задачи, файлы и общение — в одном компактном интерфейсе.</p>
        </div>
        <div class="workspace-home__hero-actions">
            <?php if (!empty($access['notes'])): ?>
                <a href="<?= $view->e($view->route('notes')) ?>" class="workspace-home__primary-action"><i class="fa fa-plus" aria-hidden="true"></i> Новая заметка</a>
            <?php endif; ?>
            <?php if (!empty($access['tasks'])): ?>
                <a href="<?= $view->e($view->route('tasks')) ?>" class="workspace-home__secondary-action"><i class="fa fa-check-square-o" aria-hidden="true"></i> Задачи</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="workspace-home__section-heading">
        <div><span>Разделы</span><h2>Рабочее пространство</h2></div>
    </div>

    <div class="workspace-home__grid">
        <?php if (!empty($access['notes'])): ?>
            <a class="workspace-module workspace-module--notes" href="<?= $view->e($view->route('notes')) ?>">
                <span class="workspace-module__icon"><i class="fa fa-sticky-note-o" aria-hidden="true"></i></span>
                <span class="workspace-module__body"><strong>Заметки</strong><small>Тексты, вложения и публичные ссылки.</small></span>
                <i class="fa fa-angle-right workspace-module__arrow" aria-hidden="true"></i>
            </a>
        <?php endif; ?>
        <?php if (!empty($access['tasks'])): ?>
            <a class="workspace-module workspace-module--tasks" href="<?= $view->e($view->route('tasks')) ?>">
                <span class="workspace-module__icon"><i class="fa fa-check-square-o" aria-hidden="true"></i></span>
                <span class="workspace-module__body"><strong>Задачи</strong><small>Канбан, сроки, приоритеты и подзадачи.</small></span>
                <i class="fa fa-angle-right workspace-module__arrow" aria-hidden="true"></i>
            </a>
        <?php endif; ?>
        <?php if (!empty($access['files'])): ?>
            <a class="workspace-module workspace-module--files" href="<?= $view->e($view->route('files')) ?>">
                <span class="workspace-module__icon"><i class="fa fa-folder-o" aria-hidden="true"></i></span>
                <span class="workspace-module__body"><strong>Файлы</strong><small>Папки и приватное хранилище.</small></span>
                <i class="fa fa-angle-right workspace-module__arrow" aria-hidden="true"></i>
            </a>
        <?php endif; ?>
        <?php if (!empty($access['messenger'])): ?>
            <a class="workspace-module workspace-module--messenger" href="<?= $view->e($view->route('messenger')) ?>">
                <span class="workspace-module__icon"><i class="fa fa-comment-o" aria-hidden="true"></i></span>
                <span class="workspace-module__body"><strong>Мессенджер</strong><small>Личные и групповые чаты в реальном времени.</small></span>
                <i class="fa fa-angle-right workspace-module__arrow" aria-hidden="true"></i>
            </a>
        <?php endif; ?>
        <?php if (!empty($access['profile'])): ?>
            <a class="workspace-module workspace-module--profile" href="<?= $view->e($view->route('profile')) ?>">
                <span class="workspace-module__icon"><i class="fa fa-user-o" aria-hidden="true"></i></span>
                <span class="workspace-module__body"><strong>Профиль</strong><small>Личные данные и настройки аккаунта.</small></span>
                <i class="fa fa-angle-right workspace-module__arrow" aria-hidden="true"></i>
            </a>
        <?php endif; ?>
        <?php if (!empty($access['admin'])): ?>
            <a class="workspace-module workspace-module--admin" href="<?= $view->e($view->route('adminpanel')) ?>">
                <span class="workspace-module__icon"><i class="fa fa-users" aria-hidden="true"></i></span>
                <span class="workspace-module__body"><strong>Админ</strong><small>Пользователи, роли и системные настройки.</small></span>
                <i class="fa fa-angle-right workspace-module__arrow" aria-hidden="true"></i>
            </a>
        <?php endif; ?>
    </div>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Главная',
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $base_url ?? '',
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $access,
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
], $content);
