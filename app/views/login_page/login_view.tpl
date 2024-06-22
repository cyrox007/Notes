{extends file='login_page/login_layout.tpl'}
{block name=title}
    Login
{/block}
{block name=body}
<div class="login-page">
    <div class="login-page__box">
        <div class="login-page__title">
            <span>
                {$smarty.env.sitename} <?=$site['version']?>
            </span>
        </div>
        <div class="login-page__card">
            <form action="" method="post">
                <input type="text" name="login" id="login" placeholder="Логин" required>
                <input type="password" name="password" id="password" placeholder="Пароль" required>
                <?if (isset($data['error'])):?>
                    <span><?=$data['error']?></span>
                <?endif;?>
                <button type="submit">Войти</button>
            </form>
        </div>
    </div>
</div>
{/block}