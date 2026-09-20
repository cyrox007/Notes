<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$avatar = ltrim((string) ($currentUser['avatar'] ?? ''), '/');
$firstName = trim((string) ($currentUser['firstname'] ?? ''));
$lastName = trim((string) ($currentUser['lastname'] ?? ''));
$username = trim((string) ($currentUser['username'] ?? ''));
$displayName = trim($firstName . ' ' . $lastName);
if ($displayName === '') {
    $displayName = $username !== '' ? $username : 'Профиль';
}
$avatarUrl = ($avatar === '' || $avatar === 'default_img')
    ? $baseUrl . '/assets/img/default_avatar.png'
    : $baseUrl . '/' . $avatar;
?>
<header class="navbar">
    <button type="button" class="navbar__menu-button" data-sidebar-toggle aria-controls="workspaceSidebar" aria-expanded="false" aria-label="Открыть навигацию" title="Меню">
        <i class="fa fa-bars" aria-hidden="true"></i>
    </button>

    <button type="button" class="workspace-command-trigger" data-command-open aria-haspopup="dialog" aria-controls="workspaceCommandPalette">
        <span class="workspace-command-trigger__icon"><i class="fa fa-search" aria-hidden="true"></i></span>
        <span class="workspace-command-trigger__label">Поиск и переход…</span>
        <kbd>Ctrl + K</kbd>
    </button>

    <div class="navbar__spacer"></div>

    <nav class="navbar__actions" aria-label="Пользовательские действия">
        <?php if (!empty($access['profile'])): ?>
            <a class="navbar__profile" href="<?= $view->e($view->route('profile')) ?>" title="Профиль">
                <img src="<?= $view->e($avatarUrl) ?>" alt="">
                <span class="navbar__profile-copy">
                    <strong><?= $view->e($displayName) ?></strong>
                    <?php if ($username !== ''): ?><small>@<?= $view->e($username) ?></small><?php endif; ?>
                </span>
            </a>
        <?php endif; ?>

        <?php if ($view->session('auth')): ?>
            <form action="<?= $view->e($view->route('logout')) ?>" method="post" class="navbar__logout-form">
                <?= $view->csrfInput() ?>
                <button type="submit" class="navbar__icon-button" title="Выйти" aria-label="Выйти">
                    <i class="fa fa-sign-out" aria-hidden="true"></i>
                </button>
            </form>
        <?php endif; ?>
    </nav>
</header>

<div class="workspace-command-palette" id="workspaceCommandPalette" data-command-palette hidden>
    <button type="button" class="workspace-command-palette__backdrop" data-command-close aria-label="Закрыть быстрый переход"></button>
    <section class="workspace-command-palette__surface" role="dialog" aria-modal="true" aria-labelledby="workspaceCommandTitle">
        <header>
            <i class="fa fa-search" aria-hidden="true"></i>
            <input type="search" data-command-input id="workspaceCommandTitle" placeholder="Перейти к разделу…" autocomplete="off">
            <kbd>Esc</kbd>
        </header>
        <div class="workspace-command-palette__list" data-command-list>
            <a href="<?= $view->e($view->route('main')) ?>" data-command-item data-command-text="главная home">
                <i class="fa fa-home" aria-hidden="true"></i><span><strong>Главная</strong><small>Обзор рабочего пространства</small></span>
            </a>
            <?php if (!empty($access['notes'])): ?><a href="<?= $view->e($view->route('notes')) ?>" data-command-item data-command-text="заметки notes блокнот"><i class="fa fa-sticky-note-o" aria-hidden="true"></i><span><strong>Заметки</strong><small>Открыть заметки</small></span></a><?php endif; ?>
            <?php if (!empty($access['tasks'])): ?><a href="<?= $view->e($view->route('tasks')) ?>" data-command-item data-command-text="задачи tasks kanban"><i class="fa fa-check-square-o" aria-hidden="true"></i><span><strong>Задачи</strong><small>Открыть задачи</small></span></a><?php endif; ?>
            <?php if (!empty($access['files'])): ?><a href="<?= $view->e($view->route('files')) ?>" data-command-item data-command-text="файлы files"><i class="fa fa-folder-o" aria-hidden="true"></i><span><strong>Файлы</strong><small>Открыть файловый менеджер</small></span></a><?php endif; ?>
            <?php if (!empty($access['messenger'])): ?><a href="<?= $view->e($view->route('messenger')) ?>" data-command-item data-command-text="мессенджер messenger сообщения"><i class="fa fa-comment-o" aria-hidden="true"></i><span><strong>Мессенджер</strong><small>Открыть чаты</small></span></a><?php endif; ?>
            <?php if (!empty($access['profile'])): ?><a href="<?= $view->e($view->route('profile')) ?>" data-command-item data-command-text="профиль profile настройки"><i class="fa fa-user-o" aria-hidden="true"></i><span><strong>Профиль</strong><small>Профиль и настройки</small></span></a><?php endif; ?>
            <?php if (!empty($access['admin'])): ?><a href="<?= $view->e($view->route('adminpanel')) ?>" data-command-item data-command-text="админ admin пользователи роли"><i class="fa fa-users" aria-hidden="true"></i><span><strong>Админ</strong><small>Управление системой</small></span></a><?php endif; ?>
        </div>
        <p class="workspace-command-palette__empty" data-command-empty hidden>Ничего не найдено</p>
    </section>
</div>

<div id="notification-region" class="notification-region" aria-live="polite" aria-atomic="true"></div>
