<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$errors = isset($errors) && is_array($errors) ? $errors : [];
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$account = isset($account) ? (string) $account : '';
$secret = isset($secret) ? (string) $secret : '';
$uri = isset($uri) ? (string) $uri : '';

ob_start();
?>
<div class="login-page">
    <div class="login-page__box">
        <a href="<?= $view->e($view->route('authpage')) ?>" class="login-page__brand" aria-label="<?= $view->e($siteName) ?>">
            <span class="login-page__brand-mark" aria-hidden="true">W</span>
            <span class="login-page__brand-copy">
                <strong><?= $view->e($siteName) ?></strong>
                <small>Workspace <?= $view->e($workspaceVersion) ?></small>
            </span>
        </a>

        <div class="login-page__card">
            <h1 class="login-page__title">Настройте двухфакторную аутентификацию</h1>
            <p class="login-page__subtitle">
                Администратор Workspace сделал 2FA обязательной для всех пользователей.
                Добавьте аккаунт <strong><?= $view->e($account) ?></strong> в приложение-аутентификатор,
                затем подтвердите текущий шестизначный код.
            </p>

            <?php if ($errors !== []): ?>
                <div class="auth-errors" role="alert">
                    <?php foreach ($errors as $item): ?>
                        <?php if (!is_array($item)) { continue; } ?>
                        <p id="<?= $view->e($item['CODE'] ?? '') ?>"><?= $view->e($item['MESSAGE'] ?? '') ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Секретный ключ</label>
                <code><?= $view->e($secret) ?></code>
            </div>
            <p class="login-link">
                <a href="<?= $view->e($uri) ?>">Открыть в приложении-аутентификаторе</a>
            </p>
            <p class="login-page__subtitle">Секрет не отправляется внешнему сервису QR-кодов.</p>

            <form action="<?= $view->e($view->route('auth_two_factor_setup_confirm')) ?>" method="post" autocomplete="off">
                <?= $view->csrfInput() ?>
                <div class="form-group">
                    <label for="two-factor-setup-code">Код из приложения</label>
                    <input
                        type="text"
                        name="code"
                        id="two-factor-setup-code"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        required
                        autofocus
                    >
                </div>
                <button type="submit">Включить 2FA и продолжить</button>
            </form>
        </div>
    </div>
</div>
<?php
$content = (string) ob_get_clean();
echo $view->layout('login_page/login_layout', [
    'title' => 'Обязательная двухфакторная аутентификация',
    'sitename' => $siteName,
    'base_url' => $base_url ?? '',
], $content);
