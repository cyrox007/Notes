<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$workspaceVersion = isset($version) ? trim((string) $version) : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
?>
<aside class="sidebar" id="workspaceSidebar" aria-label="Основная навигация">
    <div class="sidebar__top">
        <a href="<?= $view->e($view->route('main')) ?>" class="sidebar__brand" title="Notes">
            <span class="sidebar__brand-mark" aria-hidden="true">N</span>
            <span class="sidebar__brand-copy">
                <strong>Notes</strong>
                <small><?= $view->e($workspaceVersion !== '' ? $workspaceVersion : '1.0') ?></small>
            </span>
        </a>
    </div>

    <nav class="sidebar__menu" aria-label="Разделы Notes">
        <a href="<?= $view->e($view->route('main')) ?>" class="sidebar__menu-link" data-nav-key="home" title="Главная">
            <span class="sidebar__menu-icon"><i class="fa fa-home" aria-hidden="true"></i></span>
            <span class="sidebar__menu-label">Главная</span>
        </a>

        <?php if (!empty($access['notes'])): ?>
            <a href="<?= $view->e($view->route('notes')) ?>" class="sidebar__menu-link" data-nav-key="notes" title="Заметки">
                <span class="sidebar__menu-icon"><i class="fa fa-sticky-note-o" aria-hidden="true"></i></span>
                <span class="sidebar__menu-label">Заметки</span>
            </a>
        <?php endif; ?>

        <?php if (!empty($access['tasks'])): ?>
            <a href="<?= $view->e($view->route('tasks')) ?>" class="sidebar__menu-link" data-nav-key="tasks" title="Задачи">
                <span class="sidebar__menu-icon"><i class="fa fa-check-square-o" aria-hidden="true"></i></span>
                <span class="sidebar__menu-label">Задачи</span>
            </a>
        <?php endif; ?>

        <?php if (!empty($access['files'])): ?>
            <a href="<?= $view->e($view->route('files')) ?>" class="sidebar__menu-link" data-nav-key="files" title="Файлы">
                <span class="sidebar__menu-icon"><i class="fa fa-folder-o" aria-hidden="true"></i></span>
                <span class="sidebar__menu-label">Файлы</span>
            </a>
        <?php endif; ?>

        <?php if (!empty($access['messenger'])): ?>
            <a href="<?= $view->e($view->route('messenger')) ?>" class="sidebar__menu-link" data-nav-key="messenger" title="Мессенджер">
                <span class="sidebar__menu-icon"><i class="fa fa-comment-o" aria-hidden="true"></i></span>
                <span class="sidebar__menu-label">Мессенджер</span>
            </a>
        <?php endif; ?>

        <?php if (!empty($access['profile'])): ?>
            <a href="<?= $view->e($view->route('profile')) ?>" class="sidebar__menu-link" data-nav-key="profile" title="Профиль">
                <span class="sidebar__menu-icon"><i class="fa fa-user-o" aria-hidden="true"></i></span>
                <span class="sidebar__menu-label">Профиль</span>
            </a>
        <?php endif; ?>

        <?php if (!empty($access['admin'])): ?>
            <a href="<?= $view->e($view->route('adminpanel')) ?>" class="sidebar__menu-link" data-nav-key="admin" title="Админ">
                <span class="sidebar__menu-icon"><i class="fa fa-users" aria-hidden="true"></i></span>
                <span class="sidebar__menu-label">Админ</span>
            </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar__bottom">
        <div class="sidebar__theme" data-theme-picker aria-label="Цветовая тема">
            <button type="button" data-theme-option="light" aria-label="Светлая тема" title="Светлая тема">
                <i class="fa fa-sun-o" aria-hidden="true"></i>
            </button>
            <button type="button" data-theme-option="system" aria-label="Системная тема" title="Системная тема">
                <i class="fa fa-desktop" aria-hidden="true"></i>
            </button>
            <button type="button" data-theme-option="dark" aria-label="Тёмная тема" title="Тёмная тема">
                <i class="fa fa-moon-o" aria-hidden="true"></i>
            </button>
        </div>

        <?php if (!empty($access['profile'])): ?>
            <a href="<?= $view->e($view->route('profile')) ?>" class="sidebar__utility" title="Настройки профиля">
                <span class="sidebar__menu-icon"><i class="fa fa-cog" aria-hidden="true"></i></span>
                <span class="sidebar__menu-label">Настройки</span>
            </a>
        <?php endif; ?>

        <button type="button" class="sidebar__utility sidebar__collapse" data-sidebar-toggle aria-controls="workspaceSidebar" aria-expanded="true" title="Свернуть меню">
            <span class="sidebar__menu-icon"><i class="fa fa-angle-double-left" aria-hidden="true"></i></span>
            <span class="sidebar__menu-label">Свернуть</span>
        </button>
    </div>
</aside>
