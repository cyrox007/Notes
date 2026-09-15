<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$displayName = trim((string) ($currentUser['firstname'] ?? ''));
if ($displayName === '') {
    $displayName = (string) ($currentUser['username'] ?? 'Профиль');
}
?>
<header class="navbar">
    <button type="button" id="sidebarControl" class="navbar__menu-button" aria-controls="workspaceSidebar" aria-expanded="true" aria-label="Свернуть или открыть навигацию" title="Меню">
        <i class="fa fa-bars" aria-hidden="true"></i>
    </button>

    <a href="<?= $view->e($view->route('main')) ?>" class="navbar__home" aria-label="На главную">
        <span class="navbar__home-mark" aria-hidden="true">W</span>
        <span class="navbar__home-copy">
            <strong><?= $view->e($siteName) ?></strong>
            <small>рабочее пространство</small>
        </span>
    </a>

    <div class="navbar__spacer"></div>

    <nav class="navbar__actions" aria-label="Пользовательские действия">
        <?php if (!empty($access['admin'])): ?>
            <a class="navbar__action" href="<?= $view->e($view->route('adminpanel')) ?>" title="Админпанель">
                <i class="fa fa-sliders" aria-hidden="true"></i>
                <span>Админ</span>
            </a>
        <?php endif; ?>

        <?php if (!empty($access['profile'])): ?>
            <a class="navbar__action navbar__action--profile" href="<?= $view->e($view->route('profile')) ?>" title="Профиль">
                <i class="fa fa-user-o" aria-hidden="true"></i>
                <span><?= $view->e($displayName) ?></span>
            </a>
        <?php else: ?>
            <span class="navbar__action navbar__action--profile" aria-label="Профиль недоступен для текущей роли">
                <i class="fa fa-user-o" aria-hidden="true"></i>
                <span><?= $view->e($displayName) ?></span>
            </span>
        <?php endif; ?>

        <?php if ($view->session('auth')): ?>
            <form action="<?= $view->e($view->route('logout')) ?>" method="post" class="navbar__logout-form">
                <?= $view->csrfInput() ?>
                <button type="submit" class="navbar__action navbar__action--logout" title="Выйти">
                    <i class="fa fa-sign-out" aria-hidden="true"></i>
                    <span>Выйти</span>
                </button>
            </form>
        <?php endif; ?>
    </nav>
</header>

<div id="notification-region" class="notification-region" aria-live="polite" aria-atomic="true"></div>
