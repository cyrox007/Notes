<?php
namespace App\Controllers;

use Core\Controller;

use App\Helpers\CryptMethods;
use App\Models\UserModel;
use Core\Request;
use Route;

class AuthController extends Controller {
    function login() {
        return $this->render_template('login_page/login_view');
    }

    function sigin(Request $request) {
        $login = $request->post('login'); // получаем логин
        $password = $request->post('password'); // получаем введенный пароль
        
        $userModel = new UserModel();
        $userData = $userModel->get_user_by_login($login);
        
        if (!CryptMethods::verifyPassword($password, $userData['password'])) {
            $data['errors'] = [
                "CODE" => 'login_error',
                "MESSAGE" => "Password error"
            ];
            return $this->render_template('login_page/login_view', $data);
        }
        
        $request->setSession('auth', true);
        $request->setSession('user_uid', $userData['uid']);

        return Route::getInstance()->redirect('main', 'name'); 
    } 

    function logout(Request $request) {
        if (empty($request->session('auth'))) {
            return Route::getInstance()->redirect('main');
        }

        $request->unsetSession("auth");
        return Route::getInstance()->redirect('authpage', 'name');
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

        //return $this->view->render_template('login_page/register_view.php', 'login_page/login_temp.php', $data);
    }
}