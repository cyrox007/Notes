<?php
class Controller_Auth extends Controller {
    public function __construct() {
        $this->config = new Config();
        $this->model = new Model_Auth();
        $this->view = new View();
        $this->images = new Images();
    }

    function action_login() {
        $base_url = ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/';
        $data = [
            'style' => $base_url . 'templates/css/style.css',
            'script' => $base_url . 'templates/js/script.js',
            'title' => 'Авторизация',
            'error' => ''
        ];
        if (isset($_POST['login']) && isset($_POST['password'])) {
            $login = $_POST['login']; // получаем логин
            $password = $_POST['password']; // получаем введенный пароль
            $key = $this->config->hash_key; // ключ хеширования
            $method = $this->config->hash_method; // алгоритм хеширования

            $encrypted_password = openssl_encrypt($password, $method, $key); // хешируем введенный пароль

            $pass = $this->model->get_data_password($login); // получаем значение из БД
            if ($encrypted_password === $pass) {
                $_SESSION['auth_login'] = $login;
                header("Location: /");
            } else {
                $data['error'] = 'Неправильный логин или пароль';
            }
        }

        
        $this->view->render_template('login_page/login_view.php', 'login_page/login_temp.php', $data);
    }

    function action_logout() {
        if (isset($_SESSION['auth_login'])) {
            unset($_SESSION['auth_login']);
        }
        header("Location: /");
    }

    function action_registration() {
        $base_url = ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/';
        $data = [
            'style' => $base_url . 'templates/css/style.css',
            'reg-script' => $base_url . 'templates/js/reg-script.js',
            'title' => 'Регистрация',
            'error' => ''
        ];

        $uri = explode('/', $_SERVER['REQUEST_URI']);
        $invate_code = $uri[3]; // получим код приглашения из ссылки
        
        // Если пришли без кода пришлашения
        if ($invate_code == "")
            header("Location: /Error/invate_error");

        // проверим соответствие ссылки с тем что мы имеем в БД
        $db_invate_code = $this->model->get_data_invate_code($invate_code); // вернет id пользователя или false
        if (!$db_invate_code)
            header("Location: /Error/invate_error");

        $key = $this->config->hash_key; // ключ хеширования
        $method = $this->config->hash_method; // алгоритм хеширования
        
        if($_SERVER['REQUEST_METHOD'] == 'POST') {
            /* собираем данные */
            $user_login = $_POST['login']; // получаем логин из поля ввода
            $user_password = openssl_encrypt($_POST['password'], $method, $key); // получаем и хешируем пароль
            $user_firstname = $_POST['first_name'];
            $user_patronymic = $_POST['patronymic'];
            $user_surname = $_POST['surname'];
            $user_phone = $_POST['user_phone']; // получаем номер телефона пользователя
            $user_role = $this->config->user_role_activate; // получим роль пользователя
            $user_photo = 'app\uploads\us_avatars\user_default.png'; // изображение по умолчанию
            
            // проверяем изображение
            // нужно передать изображение библиотеке
            if ($_FILES['userphoto']['type'] == 'image/jpeg') {
                $user_photo = $this->images->checkAvatar_save($_FILES['userphoto']['tmp_name'], $_FILES['userphoto']['name']);
            }

            $pack1 = [
                'username' => "$user_login",
                'password' => "$user_password",
                'role' => $user_role
            ]; // первый пакет данных в основную таблицу пользователя
            //var_dump($pack1);
            $pack2 = [
                'first_name' => $user_firstname,
                'patronymic' => $user_patronymic,
                'surname' => $user_surname,
                'user_phone' => $user_phone,
                'user_photo' => "$user_photo",
            ]; // второй пакет данных в дополнительную таблицу пользователя

            if ($this->model->update_user_profile_in_register($db_invate_code, $pack1, $pack2)) {
                $this->model->delete_invate($db_invate_code); // удалим код приглашения из базы
                header("Location: /Auth/login");
            }
        }

        $this->view->render_template('login_page/register_view.php', 'login_page/login_temp.php', $data);
    }
}