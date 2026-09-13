{extends file='login_page/login_layout.tpl'}
{block name=title}Вход{/block}
{block name=body}
<div class="login-page">
    <div class="login-page__box">
        <a href="/" class="login-page__brand" aria-label="{$sitename}">
            <span class="login-page__brand-mark" aria-hidden="true">W</span>
            <span class="login-page__brand-copy">
                <strong>{$sitename}</strong>
                <small>Workspace {$version}</small>
            </span>
        </a>

        <div class="login-page__card">
            <h1 class="login-page__title">Вход в Workspace</h1>
            <p class="login-page__subtitle">Используйте свою учётную запись, чтобы открыть заметки, задачи, файлы и сообщения.</p>

            {if ($errors)}
                <div class="auth-errors" role="alert">
                    {foreach $errors as $keyvar=>$itemvar}
                        <p id="{$itemvar['CODE']}">{$itemvar['MESSAGE']}</p>
                    {/foreach}
                </div>
            {/if}

            <form action="" method="post">
                {csrf_token}
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

            <div class="auth-security-note">
                <i class="fa fa-lock" aria-hidden="true"></i>
                <span>Для production-среды открывайте Workspace только по HTTPS.</span>
            </div>
        </div>
    </div>
</div>
{/block}