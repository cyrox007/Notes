<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\CryptMethods;
use App\Models\UserModel;
use App\Services\RegistrationPolicyService;
use App\Services\UserProvisioningService;
use Core\Controller;
use Core\Request;
use Core\Router;
use DomainException;
use InvalidArgumentException;
use Throwable;

class AuthController extends Controller
{
    public function login(): void
    {
        $registrationMode = RegistrationPolicyService::MODE_DISABLED;
        try {
            $registrationMode = (new RegistrationPolicyService())->mode();
        } catch (Throwable $e) {
            error_log('Registration policy lookup failed on login page: ' . $e->getMessage());
        }

        $this->render_template('login_page/login_view', [
            'registration_mode' => $registrationMode,
        ]);
    }

    public function sigin(Request $request): void
    {
        $login = trim((string) $request->post('login'));
        $password = (string) $request->post('password');

        $user = UserModel::select()->where('username', '=', $login)->first();
        if (!$user || !CryptMethods::verifyPassword($password, $user->password_hash)) {
            $this->render_template('login_page/login_view', [
                'registration_mode' => $this->safeRegistrationMode(),
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
                'registration_mode' => $this->safeRegistrationMode(),
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
        $policy = new RegistrationPolicyService();
        try {
            $mode = $policy->mode();
        } catch (Throwable $e) {
            error_log('Registration policy lookup failed: ' . $e->getMessage());
            http_response_code(503);
            echo 'Регистрация временно недоступна';
            return;
        }

        if ($mode === RegistrationPolicyService::MODE_DISABLED) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Регистрация недоступна';
            return;
        }

        $inviteCode = trim($inviteCode ?: (string) $request->post('invite_code', ''));
        $baseUrl = rtrim((string) getenv('SITEURL'), '/') . '/' . ltrim((string) getenv('BASE_PATH'), '/');
        $data = [
            'style' => $baseUrl . 'assets/css/style.css',
            'reg-script' => $baseUrl . 'assets/js/reg-script.js',
            'title' => 'Регистрация',
            'registration_mode' => $mode,
            'invite_code' => $inviteCode,
            'form_values' => [],
        ];

        if (strtoupper((string) $request->server('REQUEST_METHOD', 'GET')) !== 'POST') {
            $this->render_template('login_page/register_view', $data);
            return;
        }

        $input = [
            'login' => trim((string) $request->post('login')),
            'password' => (string) $request->post('password'),
            'first_name' => trim((string) $request->post('first_name')),
            'patronymic' => trim((string) $request->post('patronymic')),
            'surname' => trim((string) $request->post('surname')),
            'user_phone' => trim((string) $request->post('user_phone')),
            'email' => mb_strtolower(trim((string) $request->post('email'))),
        ];
        $data['form_values'] = $input;
        unset($data['form_values']['password']);

        try {
            $policy->register($input, $inviteCode, new UserProvisioningService());
            Router::getInstance()->redirect('authpage');
        } catch (InvalidArgumentException|DomainException $e) {
            $data['errors'][] = [
                'CODE' => 'registration_error',
                'MESSAGE' => $e->getMessage(),
            ];
            $this->render_template('login_page/register_view', $data);
        } catch (Throwable $e) {
            error_log('Registration failed: ' . $e->getMessage());
            $data['errors'][] = [
                'CODE' => 'registration_error',
                'MESSAGE' => 'Не удалось создать аккаунт. Повторите попытку позже.',
            ];
            $this->render_template('login_page/register_view', $data);
        }
    }

    private function safeRegistrationMode(): string
    {
        try {
            return (new RegistrationPolicyService())->mode();
        } catch (Throwable) {
            return RegistrationPolicyService::MODE_DISABLED;
        }
    }
}
