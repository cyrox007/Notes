<?php
    class Controller_Auth extends Controller {
        public function __construct() {
            $this->model = new Model_Auth();
            $this->view = new View();
        }

        function action_login() {
            if (isset($_POST['login']) && isset($_POST['password'])) {
                $login = $_POST['login'];
                $password = $_POST['password'];
                $pass = $this->model->get_data_password($login);
                if ($password === $pass) {
                    $_SESSION['key'] = 'auth';
                    header("Location: /");
                }
            }

            $data = [
                'title' => 'Авторизация'
            ];
            $this->view->render_template('login_view.php', 'template_view.php', $data);
        }

        function action_logout() {
            if (isset($_SESSION['key']) && $_SESSION['key'] == 'auth') {
                unset($_SESSION['key']);
            }
            header("Location: /");
        }
    }