<main class="register-page">
    <div class="register">
        <h2 class="page-title-login"><? echo "{$data['title']}";?></h2>
        <form action="" method="post" enctype="multipart/form-data">
            <input type="text" name="login" id="login" placeholder="Введите логин" maxlength="15" required>
            <span id="correct_login"></span>
            <input type="password" name="password" id="pass" placeholder="Введите пароль" required>
            <input type="password" name="repeate_password" id="re_pass" placeholder="Повторите пароль" required>
            <span id="match_pass"></span>
            <input type="text" name="first_name" id="first_name" placeholder="Введите имя" maxlength="15" required>
            <input type="text" name="patronymic" id="patronymic" placeholder="Введите отчество" maxlength="15" required>
            <input type="text" name="surname" id="surname" placeholder="Введите фамилию" maxlength="15" required>
            <input type="tel" name="user_phone" id="user_phone" placeholder="Введите телефон" maxlength="15" required>
            <input type="file" name="userphoto" id="user_photo" style="border: none;">
            <span><? echo "{$data['error']}"; ?></span>
            <button id="btn-reg" type="submit">Зарегестрироваться</button>
            <a href="/Auth/login">Авторизация</a>
        </form>
    </div>
</main>
<script src="<?php echo $data['reg-script'];?>"></script>