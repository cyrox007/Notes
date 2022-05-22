<main class="login-page">
    <h2 class="page-title-login"><? echo "{$data['title']}";?></h2>
    <form action="" method="post">
        <input type="text" name="login" id="login" placeholder="Введите логин" requests>
        <input type="password" name="password" id="key" placeholder="Введите пароль" requests>
        <span><? echo "{$data['error']}"; ?></span>
        <button type="submit">Войти</button>
    </form>
</main>