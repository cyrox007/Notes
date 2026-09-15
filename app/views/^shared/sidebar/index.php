<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$avatar = ltrim((string) ($currentUser['avatar'] ?? ''), '/');
$firstName = (string) ($currentUser['firstname'] ?? '');
$lastName = (string) ($currentUser['lastname'] ?? '');
$username = (string) ($currentUser['username'] ?? '');
$fullName = trim($firstName . ' ' . $lastName);
if ($fullName === '') {
    $fullName = $username !== '' ? $username : 'Пользователь';
}
$avatarUrl = ($avatar === '' || $avatar === 'default_img')
    ? $baseUrl . '/assets/img/default_avatar.png'
    : $baseUrl . '/' . $avatar;
?>
<aside class="sidebar" id="workspaceSidebar" aria-label="Основная навигация">
    <a href="<?= $view->e($view->route('main')) ?>" class="sidebar__site-title" title="<?= $view->e($siteName) ?>">
        <span class="sidebar__brand-mark" aria-hidden="true">W</span>
        <span class="sidebar__brand-copy">
            <strong><?= $view->e($siteName) ?></strong>
            <small>Workspace</small>
        </span>
    </a>

    <div class="sidebar__content">
        <?php if (!empty($access['profile'])): ?>
            <a href="<?= $view->e($view->route('profile')) ?>" class="sidebar__user-panel" title="Открыть профиль">
        <?php else: ?>
            <div class="sidebar__user-panel" aria-label="Профиль недоступен для текущей роли">
        <?php endif; ?>
            <div class="sidebar__user-image">
                <img src="<?= $view->e($avatarUrl) ?>" alt="<?= $view->e($fullName) ?>">
            </div>
            <div class="sidebar__user-info">
                <strong><?= $view->e($fullName) ?></strong>
                <?php if ($username !== ''): ?><span>@<?= $view->e($username) ?></span><?php endif; ?>
            </div>
        <?php if (!empty($access['profile'])): ?></a><?php else: ?></div><?php endif; ?>

        <div class="sidebar__section-label">Рабочее пространство</div>
        <nav class="sidebar__menu" aria-label="Разделы Workspace">
            <?php if (!empty($access['notes'])): ?>
                <a href="<?= $view->e($view->route('notes')) ?>" class="sidebar__menu-link" title="Блокнот">
                    <span class="sidebar__menu-icon"><i class="fa fa-sticky-note-o" aria-hidden="true"></i></span>
                    <span>Блокнот</span>
                </a>
            <?php endif; ?>
            <?php if (!empty($access['tasks'])): ?>
                <a href="<?= $view->e($view->route('tasks')) ?>" class="sidebar__menu-link" title="Задачи">
                    <span class="sidebar__menu-icon"><i class="fa fa-check-square-o" aria-hidden="true"></i></span>
                    <span>Задачи</span>
                </a>
            <?php endif; ?>
            <?php if (!empty($access['files'])): ?>
                <a href="<?= $view->e($view->route('files')) ?>" class="sidebar__menu-link" title="Файлы">
                    <span class="sidebar__menu-icon"><i class="fa fa-folder-o" aria-hidden="true"></i></span>
                    <span>Файлы</span>
                </a>
            <?php endif; ?>
            <?php if (!empty($access['messenger'])): ?>
                <a href="<?= $view->e($view->route('messenger')) ?>" class="sidebar__menu-link" title="Мессенджер">
                    <span class="sidebar__menu-icon"><i class="fa fa-comments-o" aria-hidden="true"></i></span>
                    <span>Мессенджер</span>
                </a>
            <?php endif; ?>
            <?php if (!empty($access['profile'])): ?>
                <a href="<?= $view->e($view->route('profile')) ?>" class="sidebar__menu-link" title="Профиль">
                    <span class="sidebar__menu-icon"><i class="fa fa-user-o" aria-hidden="true"></i></span>
                    <span>Профиль</span>
                </a>
            <?php endif; ?>
            <?php if (!empty($access['admin'])): ?>
                <div class="sidebar__section-label sidebar__section-label--admin">Управление</div>
                <a href="<?= $view->e($view->route('adminpanel')) ?>" class="sidebar__menu-link" title="Админпанель">
                    <span class="sidebar__menu-icon"><i class="fa fa-sliders" aria-hidden="true"></i></span>
                    <span>Админпанель</span>
                </a>
            <?php endif; ?>
        </nav>
    </div>
</aside>
