<main class="register-page">
    <div class="overlay"></div>
    <div class="register">
        <h2 class="page-title-login"><? echo "{$data['title']}";?></h2>
        <form action="" method="post">
            <input type="text" name="login" id="login" placeholder="Введите логин" requests>
            <input type="password" name="password" id="pass" placeholder="Введите пароль" requests>
            <input type="password" name="repeate_password" id="re_pass" placeholder="Повторите пароль" requests>
            <span id="match_pass"></span>
            <input type="text" name="user_phone" id="user_phone" placeholder="Введите телефон" requests>
            <span><? echo "{$data['error']}"; ?></span>
            <button id="btn-reg" type="submit">Зарегестрироваться</button>
            <a href="/Auth/login">Авторизация</a>
        </form>
    </div>
</main>
<!-- <script src="<?php echo $data['reg-script'];?>"></script> -->