<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$errors = isset($errors) && is_array($errors) ? $errors : [];
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$account = isset($account) ? (string) $account : '';

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
            <h1 class="login-page__title">Двухфакторная проверка</h1>
            <p class="login-page__subtitle">
                Введите шестизначный код из приложения-аутентификатора
                <?php if ($account !== ''): ?>для <strong><?= $view->e($account) ?></strong><?php endif; ?>.
                Вместо него можно использовать один резервный код.
            </p>

            <?php if ($errors !== []): ?>
                <div class="auth-errors" role="alert">
                    <?php foreach ($errors as $item): ?>
                        <?php if (!is_array($item)) { continue; } ?>
                        <p id="<?= $view->e($item['CODE'] ?? '') ?>"><?= $view->e($item['MESSAGE'] ?? '') ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form action="<?= $view->e($view->route('auth_two_factor_verify')) ?>" method="post" autocomplete="on">
                <?= $view->csrfInput() ?>
                <div class="form-group">
                    <label for="two-factor-code">Код подтверждения</label>
                    <input
                        type="text"
                        name="code"
                        id="two-factor-code"
                        placeholder="123456 или резервный код"
                        inputmode="text"
                        autocomplete="one-time-code"
                        autocapitalize="characters"
                        spellcheck="false"
                        maxlength="32"
                        required
                        autofocus
                    >
                </div>
                <button type="submit">Подтвердить вход</button>
            </form>

            <p class="login-link"><a href="<?= $view->e($view->route('authpage')) ?>">Вернуться к вводу логина и пароля</a></p>

            <div class="auth-security-note">
                <i class="fa fa-shield" aria-hidden="true"></i>
                <span>Коды действуют короткое время. Никому их не сообщайте.</span>
            </div>
        </div>
    </div>
</div>
<?php
$content = (string) ob_get_clean();
echo $view->layout('login_page/login_layout', [
    'title' => 'Двухфакторная проверка',
    'sitename' => $siteName,
    'base_url' => $base_url ?? '',
], $content);
