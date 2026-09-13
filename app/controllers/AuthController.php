<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\CryptMethods;
use App\Models\UserModel;
use Core\Config;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Core\Router;

class AuthController extends Controller
{
    public function login(): void
    {
        $this->render_template('login_page/login_view');
    }

    public function sigin(Request $request): void
    {
        $login = trim((string) $request->post('login'));
        $password = (string) $request->post('password');

        $user = UserModel::select()->where('username', '=', $login)->first();
        if (!$user || !CryptMethods::verifyPassword($password, $user->password_hash)) {
            $this->render_template('login_page/login_view', [
                'errors' => [[
                    'CODE' => 'login_error',
                    'MESSAGE' => 'Неверный логин или пароль',
                ]],
            ]);
            return;
        }

        if ((int) $user->is_active !== 1 || !Config::canAuthenticate((int) $user->role)) {
            $this->render_template('login_page/login_view', [
                'errors' => [[
                    'CODE' => 'login_error',
                    'MESSAGE' => 'Учетная запись недоступна',
                ]],
            ]);
            return;
        }

        session_regenerate_id(true);
        $request->setSession('auth', true);
        $request->setSession('user_id', $user->id);
        $request->setSession('user_uid', $user->uid);

        Router::getInstance()->redirect('main', 'name');
    }

    public function logout(Request $request): void
    {
        $request->unsetSession('auth');
        $request->unsetSession('user_id');
        $request->unsetSession('user_uid');
        session_regenerate_id(true);
        Router::getInstance()->redirect('authpage', 'name');
    }

    public function registration(Request $request, ?string $inviteCode = null): void
    {
        $baseUrl = rtrim((string) getenv('SITEURL'), '/') . '/' . ltrim((string) getenv('BASE_PATH'), '/');
        $inviteCode = $inviteCode ?: (string) $request->post('invite_code');
        $data = [
            'style' => $baseUrl . 'assets/css/style.css',
            'reg-script' => $baseUrl . 'assets/js/reg-script.js',
            'title' => 'Регистрация',
            'error' => '',
            'invite_code' => $inviteCode,
        ];

        if ($inviteCode === '') {
            Router::getInstance()->redirect('authpage');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->render_template('login_page/register_view', $data);
            return;
        }

        $username = trim((string) $request->post('login'));
        $password = (string) $request->post('password');
        $firstname = trim((string) $request->post('first_name'));
        $patronymic = trim((string) $request->post('patronymic'));
        $lastname = trim((string) $request->post('surname'));
        $phone = trim((string) $request->post('user_phone'));
        $email = trim((string) $request->post('email'));

        if ($username === '' || $password === '' || $firstname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $data['errors'][] = [
                'CODE' => 'registration_error',
                'MESSAGE' => 'Заполните обязательные поля корректно',
            ];
            $this->render_template('login_page/register_view', $data);
            return;
        }

        $existingUser = UserModel::select()
            ->where('username', '=', $username)
            ->orWhere('email', '=', $email)
            ->first();
        if ($existingUser) {
            $data['errors'][] = [
                'CODE' => 'registration_error',
                'MESSAGE' => 'Пользователь с таким логином или email уже существует',
            ];
            $this->render_template('login_page/register_view', $data);
            return;
        }

        $now = date('Y-m-d H:i:s');
        DatabaseManager::getInstance()->queueInsert([
            'uid' => \UUID::v4(),
            'username' => $username,
            'email' => $email,
            'password_hash' => CryptMethods::hashPassword($password),
            'firstname' => $firstname,
            'patronymic' => $patronymic !== '' ? $patronymic : null,
            'lastname' => $lastname,
            'phone' => $phone !== '' ? $phone : null,
            'avatar' => null,
            'property' => json_encode([], JSON_THROW_ON_ERROR),
            'role' => Config::USER_ROLE_USER,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], 'users');
        DatabaseManager::getInstance()->commit();

        Router::getInstance()->redirect('authpage');
    }
}
