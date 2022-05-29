<?php
class Controller_Auth extends Controller {
    public function __construct() {
        $this->model = new Model_Auth();
        $this->view = new View();
    }

    function action_login() {
        $base_url = ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/';
        $data = [
            'style' => $base_url . 'templates/style/style.css',
            'script' => $base_url . 'templates/js/script.js',
            'title' => 'Авторизация',
            'error' => ''
        ];
        if (isset($_POST['login']) && isset($_POST['password'])) {
            $login = $_POST['login']; // получаем логин
            $password = $_POST['password']; // получаем введенный пароль
            $key = "592e6419d1d04634848f40f22f9f71a7450800611f4e497cdd71b7cef3e3450ae63fd149609d36eb"; // ключ хеширования
            $method = "AES-192-CBC"; // алгоритм хеширования

            $encrypted_password = openssl_encrypt($password, $method, $key); // хешируем введенный пароль
            
            $pass = $this->model->get_data_password($login); // получаем значение из БД
            if ($encrypted_password === $pass) {
                $_SESSION['key'] = 'auth';
                header("Location: /");
            } else {
                $data['error'] = 'Неправильный логин или пароль';
            }
        }

        
        $this->view->render_template('login_page/login_view.php', 'login_page/login_temp.php', $data);
    }

    function action_logout() {
        if (isset($_SESSION['key']) && $_SESSION['key'] == 'auth') {
            unset($_SESSION['key']);
        }
        header("Location: /");
    }

    function action_registration() {
        $base_url = ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/';
        $data = [
            'style' => $base_url . 'templates/style/style.css',
            'script' => $base_url . 'templates/js/reg-script.js',
            'title' => 'Регистрация',
        ];

        $uri = explode('/', $_SERVER['REQUEST_URI']);
        $invate_code = $uri[3]; // получим код приглашения из ссылки
        
        // Если пришли без кода пришлашения
        if ($invate_code == "")
            header("Location: /Error/invate_error");

        // проверим соответствие ссылки с тем что мы имеем в БД
        $db_invate_code = $this->model->get_data_invate_code($invate_code);
        if (!$db_invate_code)
            header("Location: /Error/invate_error");

        /*  тут мы собираем все данные из формы в переменные
            затем мы находим пользователя по id из инвайта
            находим его в таблице users и profile 
            выполняем UPDATE для таблиц
            необходимо написать еще пару функций в модель auth*/

        $this->view->render_template('login_page/register_view.php', 'login_page/login_temp.php', $data);
    }
}