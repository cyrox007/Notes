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
    <div class="workspace-home__hero">
        <div class="workspace-home__hero-copy">
            <span class="workspace-home__eyebrow">Личное рабочее пространство</span>
            <h1>Добро пожаловать, <?= $view->e($displayName) ?>!</h1>
            <p>Заметки, задачи, файлы и сообщения собраны в одном интерфейсе. Продолжите работу в нужном разделе или быстро перейдите к основным инструментам.</p>
            <?php if (!empty($access['notes']) || !empty($access['tasks'])): ?>
                <div class="workspace-home__hero-actions">
                    <?php if (!empty($access['notes'])): ?>
                        <a href="<?= $view->e($view->route('notes')) ?>" class="workspace-home__primary-action">
                            <i class="fa fa-plus" aria-hidden="true"></i>
                            Новая заметка
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($access['tasks'])): ?>
                        <a href="<?= $view->e($view->route('tasks')) ?>" class="workspace-home__secondary-action">
                            <i class="fa fa-check-square-o" aria-hidden="true"></i>
                            Открыть задачи
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="workspace-home__hero-status" aria-label="Статус рабочей сессии">
            <div class="workspace-home__status-icon"><i class="fa fa-shield" aria-hidden="true"></i></div>
            <div>
                <strong>Защищённая сессия</strong>
                <span>Данные доступны только после авторизации</span>
            </div>
        </div>
    </div>

    <div class="workspace-home__section-heading">
        <div>
            <span>Инструменты</span>
            <h2>Что хотите открыть?</h2>
        </div>
    </div>

    <div class="workspace-home__grid">
        <?php if (!empty($access['notes'])): ?>
            <a class="workspace-module workspace-module--notes" href="<?= $view->e($view->route('notes')) ?>">
                <span class="workspace-module__icon"><i class="fa fa-sticky-note-o" aria-hidden="true"></i></span>
                <span class="workspace-module__body">
                    <strong>Блокнот</strong>
                    <small>Личные зашифрованные заметки, вложения и ссылки для просмотра.</small>
                </span>
                <i class="fa fa-arrow-right workspace-module__arrow" aria-hidden="true"></i>
            </a>
        <?php endif; ?>

        <?php if (!empty($access['tasks'])): ?>
            <a class="workspace-module workspace-module--tasks" href="<?= $view->e($view->route('tasks')) ?>">
                <span class="workspace-module__icon"><i class="fa fa-check-square-o" aria-hidden="true"></i></span>
                <span class="workspace-module__body">
                    <strong>Задачи</strong>
                    <small>Приоритеты, сроки, категории, статусы и подзадачи.</small>
                </span>
                <i class="fa fa-arrow-right workspace-module__arrow" aria-hidden="true"></i>
            </a>
        <?php endif; ?>

        <?php if (!empty($access['files'])): ?>
            <a class="workspace-module workspace-module--files" href="<?= $view->e($view->route('files')) ?>">
                <span class="workspace-module__icon"><i class="fa fa-folder-open-o" aria-hidden="true"></i></span>
                <span class="workspace-module__body">
                    <strong>Файлы</strong>
                    <small>Личное приватное хранилище с папками и защищённой выдачей.</small>
                </span>
                <i class="fa fa-arrow-right workspace-module__arrow" aria-hidden="true"></i>
            </a>
        <?php endif; ?>

        <?php if (!empty($access['messenger'])): ?>
            <a class="workspace-module workspace-module--messenger" href="<?= $view->e($view->route('messenger')) ?>">
                <span class="workspace-module__icon"><i class="fa fa-comments-o" aria-hidden="true"></i></span>
                <span class="workspace-module__body">
                    <strong>Мессенджер</strong>
                    <small>Личные и групповые чаты, медиа, голосовые сообщения и реакции.</small>
                </span>
                <i class="fa fa-arrow-right workspace-module__arrow" aria-hidden="true"></i>
            </a>
        <?php endif; ?>

        <?php if (!empty($access['profile'])): ?>
            <a class="workspace-module workspace-module--profile" href="<?= $view->e($view->route('profile')) ?>">
                <span class="workspace-module__icon"><i class="fa fa-user-o" aria-hidden="true"></i></span>
                <span class="workspace-module__body">
                    <strong>Профиль</strong>
                    <small>Контактные данные, аватар, пароль и управление аккаунтом.</small>
                </span>
                <i class="fa fa-arrow-right workspace-module__arrow" aria-hidden="true"></i>
            </a>
        <?php endif; ?>

        <?php if (!empty($access['admin'])): ?>
            <a class="workspace-module workspace-module--admin" href="<?= $view->e($view->route('adminpanel')) ?>">
                <span class="workspace-module__icon"><i class="fa fa-sliders" aria-hidden="true"></i></span>
                <span class="workspace-module__body">
                    <strong>Администрирование</strong>
                    <small>Пользователи, роли, политики доступа и системные настройки.</small>
                </span>
                <i class="fa fa-arrow-right workspace-module__arrow" aria-hidden="true"></i>
            </a>
        <?php endif; ?>
    </div>

    <div class="workspace-home__notice">
        <i class="fa fa-lock" aria-hidden="true"></i>
        <div>
            <strong>Workspace хранит приватные файлы вне web-root.</strong>
            <span>Для production используйте HTTPS/WSS, резервные копии private storage и актуальные ключи шифрования.</span>
        </div>
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
