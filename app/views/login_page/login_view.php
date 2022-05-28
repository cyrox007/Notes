<main class="login-page">
    <div class="overlay">
        <p class="sitename">My Workspace</p>
        <div class="date-time">
            <p class="time">00:00</p>
            <p class="date">00.00.0000</p>
        </div>
    </div>
    <div class="login">
        <h2 class="page-title-login"><? echo "{$data['title']}";?></h2>
        <form action="" method="post">
            <input type="text" name="login" id="login" placeholder="Введите логин" requests>
            <input type="password" name="password" id="key" placeholder="Введите пароль" requests>
            <span><? echo "{$data['error']}"; ?></span>
            <button type="submit">Войти</button>
            <a href="/Auth/registration">Регистрация</a>
        </form>
    </div>
</main>