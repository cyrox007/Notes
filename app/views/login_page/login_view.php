<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$errors = isset($errors) && is_array($errors) ? $errors : [];
$registrationMode = isset($registration_mode) ? (string) $registration_mode : 'disabled';
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';

ob_start();
?>
<div class="login-page">
    <div class="login-page__box">
        <a href="<?= $view->e($view->route('main')) ?>" class="login-page__brand" aria-label="<?= $view->e($siteName) ?>">
            <span class="login-page__brand-mark" aria-hidden="true">W</span>
            <span class="login-page__brand-copy">
                <strong><?= $view->e($siteName) ?></strong>
                <small>Workspace <?= $view->e($workspaceVersion) ?></small>
            </span>
        </a>

        <div class="login-page__card">
            <h1 class="login-page__title">Вход в Workspace</h1>
            <p class="login-page__subtitle">Используйте свою учётную запись, чтобы открыть заметки, задачи, файлы и сообщения.</p>

            <?php if ($errors !== []): ?>
                <div class="auth-errors" role="alert">
                    <?php foreach ($errors as $item): ?>
                        <?php if (!is_array($item)) { continue; } ?>
                        <p id="<?= $view->e($item['CODE'] ?? '') ?>"><?= $view->e($item['MESSAGE'] ?? '') ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form action="<?= $view->e($view->route('authpage')) ?>" method="post" autocomplete="on">
                <?= $view->csrfInput() ?>
                <div class="form-group">
                    <label for="login">Логин</label>
                    <input type="text" name="login" id="login" placeholder="Введите логин" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Пароль</label>
                    <input type="password" name="password" id="password" placeholder="Введите пароль" autocomplete="current-password" required>
                </div>
                <button type="submit">Войти</button>
            </form>

            <?php if ($registrationMode === 'open'): ?>
                <p class="login-link">Нет аккаунта? <a href="<?= $view->e($view->route('registration')) ?>">Зарегистрироваться</a></p>
            <?php elseif ($registrationMode === 'invite'): ?>
                <p class="login-link">Есть приглашение? <a href="<?= $view->e($view->route('registration')) ?>">Регистрация по инвайту</a></p>
            <?php endif; ?>

            <div class="auth-security-note">
                <i class="fa fa-lock" aria-hidden="true"></i>
                <span>Для production-среды открывайте Workspace только по HTTPS.</span>
            </div>
        </div>
    </div>
</div>
<?php
$content = (string) ob_get_clean();
echo $view->layout('login_page/login_layout', [
    'title' => 'Вход',
    'sitename' => $siteName,
    'base_url' => $base_url ?? '',
], $content);
