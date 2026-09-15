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

        if (
            (int) $user->is_active !== 1
            || (string) ($user->account_status ?? '') !== 'active'
        ) {
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
        $configuredInvite = trim((string) (getenv('REGISTRATION_INVITE_CODE') ?: ''));
        $inviteCode = trim($inviteCode ?: (string) $request->post('invite_code'));
        if ($configuredInvite === '' || $inviteCode === '' || !hash_equals($configuredInvite, $inviteCode)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Регистрация недоступна';
            return;
        }

        $baseUrl = rtrim((string) getenv('SITEURL'), '/') . '/' . ltrim((string) getenv('BASE_PATH'), '/');
        $data = [
            'style' => $baseUrl . 'assets/css/style.css',
            'reg-script' => $baseUrl . 'assets/js/reg-script.js',
            'title' => 'Регистрация',
            'error' => '',
            'invite_code' => $inviteCode,
        ];

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
        $email = mb_strtolower(trim((string) $request->post('email')));

        $validationError = $this->registrationValidationError(
            $username,
            $password,
            $firstname,
            $patronymic,
            $lastname,
            $phone,
            $email
        );
        if ($validationError !== null) {
            $data['errors'][] = [
                'CODE' => 'registration_error',
                'MESSAGE' => $validationError,
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
            // Legacy role remains compatibility metadata during the 0.14
            // migration. The canonical access-control trigger assigns the RBAC
            // `user` role atomically with this INSERT.
            'role' => Config::USER_ROLE_USER,
            'is_active' => 1,
            'account_status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], 'users');
        DatabaseManager::getInstance()->commit();

        Router::getInstance()->redirect('authpage');
    }

    private function registrationValidationError(
        string $username,
        string $password,
        string $firstname,
        string $patronymic,
        string $lastname,
        string $phone,
        string $email
    ): ?string {
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            return 'Логин должен содержать 3–50 латинских букв, цифр, точек, дефисов или подчёркиваний';
        }
        if (strlen($password) < 10 || strlen($password) > 200) {
            return 'Пароль должен содержать от 10 до 200 символов';
        }
        if ($firstname === '' || mb_strlen($firstname) > 80) {
            return 'Укажите корректное имя длиной до 80 символов';
        }
        if ($lastname === '' || mb_strlen($lastname) > 80) {
            return 'Укажите корректную фамилию длиной до 80 символов';
        }
        if ($patronymic !== '' && mb_strlen($patronymic) > 80) {
            return 'Отчество слишком длинное';
        }
        if ($phone !== '' && mb_strlen($phone) > 32) {
            return 'Телефон слишком длинный';
        }
        if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Укажите корректный email';
        }
        return null;
    }
}
