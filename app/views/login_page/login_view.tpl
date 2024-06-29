{extends file='login_page/login_layout.tpl'}
{block name=title}
    Login
{/block}
{block name=body}
<div class="login-page">
    <div class="login-page__box">
        <div class="login-page__title">
            <span>
                {$smarty.env.SITENAME} {$smarty.env.VERSION}
            </span>
        </div>
        <div class="login-page__card">
            <form action="" method="post">
                {csrf_token}
                <input type="text" name="login" id="login" placeholder="Логин" required>
                <input type="password" name="password" id="password" placeholder="Пароль" required>
                {if ($errors)}
                    {foreach $errors as $keyvar=>$itemvar}
                        <span id="{$itemvar['CODE']}">{$itemvar['MESSAGE']}</span>
                    {/foreach}
                {/if}
                <button type="submit">Войти</button>
            </form>
        </div>
    </div>
</div>
{/block}