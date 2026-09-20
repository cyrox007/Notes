<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$registrationMode = isset($registration_mode) ? (string) $registration_mode : 'disabled';
$inviteCode = isset($invite_code) ? (string) $invite_code : '';
$errors = isset($errors) && is_array($errors) ? $errors : [];
$formValues = isset($form_values) && is_array($form_values) ? $form_values : [];
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';

ob_start();
?>
<div class="register-page">
    <div class="register">
        <a href="<?= $view->e($view->route('main')) ?>" class="login-page__brand" aria-label="<?= $view->e($siteName) ?>">
            <span class="login-page__brand-mark" aria-hidden="true">W</span>
            <span class="login-page__brand-copy">
                <strong><?= $view->e($siteName) ?></strong>
                <small>Создание аккаунта</small>
            </span>
        </a>

        <form action="<?= $view->e($view->route('register_submit')) ?>" method="post" id="registration-form">
            <?= $view->csrfInput() ?>

            <h1 class="page-title-login">Регистрация пользователя</h1>
            <?php if ($registrationMode === 'invite'): ?>
                <p class="register-page__subtitle">Регистрация доступна по приглашению. Введите код инвайта и заполните профиль.</p>
                <div class="form-group">
                    <label for="invite_code">Код приглашения *</label>
                    <input type="text" name="invite_code" id="invite_code" value="<?= $view->e($inviteCode) ?>" autocomplete="off" maxlength="128" required>
                </div>
            <?php else: ?>
                <input type="hidden" name="invite_code" value="">
                <p class="register-page__subtitle">Свободная регистрация включена администратором. Заполните профиль. Пароль должен содержать не менее 10 символов.</p>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <div class="error-messages" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <?php if (!is_array($error)) { continue; } ?>
                        <p class="error"><?= $view->e($error['MESSAGE'] ?? '') ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="login">Логин *</label>
                <input type="text" name="login" id="login" value="<?= $view->e($formValues['login'] ?? '') ?>" placeholder="Например, alex.t" autocomplete="username" autocapitalize="none" spellcheck="false" minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]+" required>
                <span id="correct_login" class="field-error" aria-live="polite"></span>
            </div>

            <div class="form-group">
                <label for="email">Email *</label>
                <input type="email" name="email" id="email" value="<?= $view->e($formValues['email'] ?? '') ?>" placeholder="name@example.com" autocomplete="email" maxlength="190" required>
            </div>

            <div class="form-group">
                <label for="password">Пароль *</label>
                <input type="password" name="password" id="password" placeholder="Минимум 10 символов" autocomplete="new-password" minlength="10" maxlength="200" required>
                <span id="password_hint" class="form-hint">Не используйте пароль от другого сервиса.</span>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">Имя *</label>
                    <input type="text" name="first_name" id="first_name" value="<?= $view->e($formValues['first_name'] ?? '') ?>" placeholder="Имя" autocomplete="given-name" maxlength="80" required>
                </div>

                <div class="form-group">
                    <label for="patronymic">Отчество</label>
                    <input type="text" name="patronymic" id="patronymic" value="<?= $view->e($formValues['patronymic'] ?? '') ?>" placeholder="Отчество" autocomplete="additional-name" maxlength="80">
                </div>

                <div class="form-group">
                    <label for="surname">Фамилия *</label>
                    <input type="text" name="surname" id="surname" value="<?= $view->e($formValues['surname'] ?? '') ?>" placeholder="Фамилия" autocomplete="family-name" maxlength="80" required>
                </div>
            </div>

            <div class="form-group">
                <label for="user_phone">Телефон</label>
                <input type="tel" name="user_phone" id="user_phone" value="<?= $view->e($formValues['user_phone'] ?? '') ?>" placeholder="+49 ..." autocomplete="tel" maxlength="32">
            </div>

            <button id="btn-reg" type="submit">Создать аккаунт</button>
            <p class="login-link">Уже есть аккаунт? <a href="<?= $view->e($view->route('authpage')) ?>">Войти</a></p>
        </form>
    </div>
</div>
<?php
$content = (string) ob_get_clean();
echo $view->layout('login_page/login_layout', [
    'title' => 'Регистрация',
    'sitename' => $siteName,
    'base_url' => $base_url ?? '',
], $content);
