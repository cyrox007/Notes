{extends file='login_page/login_layout.tpl'}
{block name=title}
    Регистрация
{/block}
{block name=body}
<div class="login-page">
    <div class="login-page__box">
        <div class="login-page__title">
            <span>
                Регистрация пользователя
            </span>
        </div>
        <div class="login-page__card">
            {if $errors}
                <div class="error-messages">
                    {foreach $errors as $error}
                        <p class="error">{$error.MESSAGE}</p>
                    {/foreach}
                </div>
            {/if}
            
            <form action="{route_path name='register_submit'}" method="post" enctype="multipart/form-data">
                {csrf_token}
                <input type="hidden" name="invite_code" value="{$invite_code}">
                
                <div class="form-group">
                    <label for="login">Логин *</label>
                    <input type="text" name="login" id="login" placeholder="Придумайте логин" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input type="email" name="email" id="email" placeholder="Введите email" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Пароль *</label>
                    <input type="password" name="password" id="password" placeholder="Придумайте пароль" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">Имя *</label>
                        <input type="text" name="first_name" id="first_name" placeholder="Имя" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="patronymic">Отчество</label>
                        <input type="text" name="patronymic" id="patronymic" placeholder="Отчество">
                    </div>
                    
                    <div class="form-group">
                        <label for="surname">Фамилия *</label>
                        <input type="text" name="surname" id="surname" placeholder="Фамилия" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="user_phone">Телефон</label>
                    <input type="tel" name="user_phone" id="user_phone" placeholder="+7 (___) ___-__-__">
                </div>
                
                <div class="form-group">
                    <label for="userphoto">Фото профиля</label>
                    <input type="file" name="userphoto" id="userphoto" accept="image/jpeg,image/png,image/webp">
                </div>
                
                <button type="submit">Зарегистрироваться</button>
                
                <p class="login-link">
                    Уже есть аккаунт? <a href="{route_path name='authpage'}">Войти</a>
                </p>
            </form>
        </div>
    </div>
</div>
{/block}
