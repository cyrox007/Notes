{extends file='login_page/login_layout.tpl'}
{block name=title}Регистрация{/block}
{block name=body}
<div class="register-page">
    <div class="register">
        <a href="{route_path name='main'}" class="login-page__brand" aria-label="{$sitename}">
            <span class="login-page__brand-mark" aria-hidden="true">W</span>
            <span class="login-page__brand-copy">
                <strong>{$sitename}</strong>
                <small>Создание аккаунта</small>
            </span>
        </a>

        <form action="{route_path name='register_submit'}" method="post" id="registration-form">
            {csrf_token}
            <input type="hidden" name="invite_code" value="{$invite_code}">

            <h1 class="page-title-login">Регистрация пользователя</h1>
            <p class="register-page__subtitle">Заполните профиль. Пароль должен содержать не менее 10 символов. Аватар можно добавить после входа.</p>

            {if $errors}
                <div class="error-messages" role="alert">
                    {foreach $errors as $error}
                        <p class="error">{$error.MESSAGE}</p>
                    {/foreach}
                </div>
            {/if}

            <div class="form-group">
                <label for="login">Логин *</label>
                <input type="text" name="login" id="login" placeholder="Например, alex.t" autocomplete="username" autocapitalize="none" spellcheck="false" minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]+" required>
                <span id="correct_login" class="field-error" aria-live="polite"></span>
            </div>

            <div class="form-group">
                <label for="email">Email *</label>
                <input type="email" name="email" id="email" placeholder="name@example.com" autocomplete="email" maxlength="190" required>
            </div>

            <div class="form-group">
                <label for="password">Пароль *</label>
                <input type="password" name="password" id="password" placeholder="Минимум 10 символов" autocomplete="new-password" minlength="10" maxlength="200" required>
                <span id="password_hint" class="form-hint">Не используйте пароль от другого сервиса.</span>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">Имя *</label>
                    <input type="text" name="first_name" id="first_name" placeholder="Имя" autocomplete="given-name" maxlength="80" required>
                </div>

                <div class="form-group">
                    <label for="patronymic">Отчество</label>
                    <input type="text" name="patronymic" id="patronymic" placeholder="Отчество" autocomplete="additional-name" maxlength="80">
                </div>

                <div class="form-group">
                    <label for="surname">Фамилия *</label>
                    <input type="text" name="surname" id="surname" placeholder="Фамилия" autocomplete="family-name" maxlength="80" required>
                </div>
            </div>

            <div class="form-group">
                <label for="user_phone">Телефон</label>
                <input type="tel" name="user_phone" id="user_phone" placeholder="+49 ..." autocomplete="tel" maxlength="32">
            </div>

            <button id="btn-reg" type="submit">Создать аккаунт</button>

            <p class="login-link">Уже есть аккаунт? <a href="{route_path name='authpage'}">Войти</a></p>
        </form>
    </div>
</div>
{/block}
