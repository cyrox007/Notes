<?php
namespace App\Controllers;

use Core\Controller;

use App\Helpers\CryptMethods;
use App\Models\UserModel;
use Core\Config;
use Core\Request;
use Core\Router;
use Core\DatabaseManager;

class AuthController extends Controller {
    public function __construct() {
        parent::__construct();
    }
    
    function login() {
        return $this->render_template('login_page/login_view');
    }

    function sigin(Request $request) {
        $login = $request->post('login');
        $password = $request->post('password');
        
        $user = UserModel::select()
        ->where('username', '=', $login)
        ->first();
        
        // Проверка существования пользователя
        if (!$user) {
            $data['errors'][] = [
                "CODE" => 'login_error',
                "MESSAGE" => "Пользователь не найден"
            ];
            return $this->render_template('login_page/login_view', $data);
        }
        
        if (!CryptMethods::verifyPassword($password, $user->password_hash)) {
            $data['errors'][] = [
                "CODE" => 'login_error',
                "MESSAGE" => "Неверный пароль"
            ];
            return $this->render_template('login_page/login_view', $data);
        }

        if (!Config::canAuthenticate((int)$user->role)) {
            $data['errors'][] = [
                "CODE" => 'login_error',
                "MESSAGE" => "Учетная запись недоступна"
            ];
            return $this->render_template('login_page/login_view', $data);
        }
        
        session_regenerate_id(true);
        $request->setSession('auth', true);
        $request->setSession('user_id', $user->id);
        $request->setSession('user_uid', $user->uid);

        return Router::getInstance()->redirect('main', 'name'); 
    } 

    function logout(Request $request) {
        if (empty($request->session('auth'))) {
            return Router::getInstance()->redirect('authpage', 'name');
        }

        $request->unsetSession("auth");
        $request->unsetSession("user_id");
        $request->unsetSession("user_uid");
        session_regenerate_id(true);
        return Router::getInstance()->redirect('authpage', 'name');
    }

    function registration(Request $request, ?string $inviteCode = null) {
        $base_url = rtrim(getenv('SITEURL'), '/') . '/' . ltrim(getenv('BASE_PATH'), '/');
        $inviteCode = $inviteCode ?: $request->post('invite_code');
        $data = [
            'style' => $base_url . 'assets/css/style.css',
            'reg-script' => $base_url . 'assets/js/reg-script.js',
            'title' => 'Регистрация',
            'error' => '',
            'invite_code' => $inviteCode
        ];

        // Если пришли без кода приглашения
        if (empty($data['invite_code'])) {
            return Router::getInstance()->redirect('authpage');
        }

        // TODO: Проверка кода приглашения в БД (нужно создать таблицу invite_codes и методы в модели)
        // Пока пропускаем проверку и позволяем регистрацию
        
        if($_SERVER['REQUEST_METHOD'] == 'POST') {
            // Собираем данные
            $user_login = $request->post('login');
            $user_password = CryptMethods::hashPassword($request->post('password'));
            $user_firstname = $request->post('first_name');
            $user_patronymic = $request->post('patronymic');
            $user_lastname = $request->post('surname');
            $user_phone = $request->post('user_phone');
            $user_email = $request->post('email');
            $user_role = Config::USER_ROLE_USER;
            $user_photo = 'default_img';
            
            // Проверяем изображение
            if (!empty($_FILES['userphoto']['tmp_name']) && in_array($_FILES['userphoto']['type'], ['image/jpeg', 'image/png', 'image/webp'])) {
                // TODO: Реализовать сохранение аватара через Images handler
                $user_photo = 'default_img';
            }

            // Проверяем занятость логина и email
            $existingUser = UserModel::select()
                ->where('username', '=', $user_login)
                ->orWhere('email', '=', $user_email)
                ->first();
            
            if ($existingUser) {
                $data['errors'][] = [
                    "CODE" => 'registration_error',
                    "MESSAGE" => "Пользователь с таким логином или email уже существует"
                ];
                return $this->render_template('login_page/register_view', $data);
            }

            // Создаем нового пользователя
            $newUser = new UserModel();
            $newUser->uid = \UUID::guidv4();
            $newUser->username = $user_login;
            $newUser->email = $user_email;
            $newUser->password_hash = $user_password;
            $newUser->firstname = $user_firstname;
            $newUser->patronymic = $user_patronymic;
            $newUser->lastname = $user_lastname;
            $newUser->phone = $user_phone;
            $newUser->role = $user_role;
            $newUser->user_image = $user_photo;
            $newUser->property = json_encode([]);
            
            $dbManager = DatabaseManager::getInstance();
            $dbManager->queueInsert((array)$newUser, 'users');
            $dbManager->commit();
            
            // TODO: Удалить код приглашения после успешной регистрации
            
            return Router::getInstance()->redirect('authpage');
        }

        return $this->render_template('login_page/register_view', $data);
    }
}
