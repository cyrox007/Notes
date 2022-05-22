<?php
    class Controller_Auth extends Controller {
        public function __construct() {
            $this->model = new Model_Auth();
            $this->view = new View();
        }

        function action_login() {
            $data = [
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

            
            $this->view->render_template('login_view.php', 'template_view.php', $data);
        }

        function action_logout() {
            if (isset($_SESSION['key']) && $_SESSION['key'] == 'auth') {
                unset($_SESSION['key']);
            }
            header("Location: /");
        }
    }