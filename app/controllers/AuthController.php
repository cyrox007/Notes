<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\CryptMethods;
use App\Models\UserModel;
use App\Services\RequestRateLimiter;
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

        if (!$this->allowRequest('auth-login', strtolower($login), $this->envInt('AUTH_LOGIN_RATE_LIMIT', 10), $this->envInt('AUTH_LOGIN_RATE_WINDOW', 300))) {
            $this->render_template('login_page/login_view', [
                'errors' => [[
                    'CODE' => 'rate_limit',
                    'MESSAGE' => 'Слишком много попыток входа. Повторите позже.',
                ]],
            ]);
            return;
        }

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

        if (!$this->allowRequest('auth-registration', $inviteCode, $this->envInt('AUTH_REGISTER_RATE_LIMIT', 5), $this->envInt('AUTH_REGISTER_RATE_WINDOW', 600))) {
            $data['errors'][] = [
                'CODE' => 'rate_limit',
                'MESSAGE' => 'Слишком много попыток регистрации. Повторите позже.',
            ];
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

        if (
            $username === ''
            || mb_strlen($username) > 100
            || mb_strlen($password) < 10
            || mb_strlen($password) > 200
            || $firstname === ''
            || mb_strlen($firstname) > 100
            || mb_strlen($lastname) > 100
            || mb_strlen($patronymic) > 100
            || mb_strlen($phone) > 50
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || mb_strlen($email) > 255
        ) {
            $data['errors'][] = [
                'CODE' => 'registration_error',
                'MESSAGE' => 'Проверьте обязательные поля. Пароль должен содержать не менее 10 символов.',
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
            'email' => strtolower($email),
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

    private function allowRequest(string $bucket, string $identity, int $limit, int $windowSeconds): bool
    {
        try {
            $subject = RequestRateLimiter::clientSubject($bucket) . '|' . $identity;
            $state = RequestRateLimiter::consume($bucket, $subject, $limit, $windowSeconds);
            if (!$state['allowed']) {
                http_response_code(429);
                header('Retry-After: ' . $state['retry_after']);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            // Authentication must remain available if the limiter storage has an operational issue.
            // Log the condition so production monitoring can detect it.
            error_log('Rate limiter failure for ' . $bucket . ': ' . $e->getMessage());
            return true;
        }
    }

    private function envInt(string $key, int $default): int
    {
        $value = getenv($key);
        if (!is_string($value) || !ctype_digit($value)) {
            return $default;
        }
        return max(1, (int) $value);
    }
}
