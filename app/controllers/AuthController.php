<?php

declare(strict_types=1);

namespace App\Controllers;

require_once dirname(__DIR__, 2) . '/core/SecurityEventLog.php';

use App\Helpers\CryptMethods;
use App\Models\UserModel;
use App\Services\RegistrationPolicyService;
use App\Services\UserProvisioningService;
use Core\Controller;
use Core\Request;
use Core\RequestOrigin;
use Core\Router;
use Core\SecurityEventLog;
use Core\SessionSecurity;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class AuthController extends Controller
{
    /**
     * Registration policy, including the legacy REGISTRATION_INVITE_CODE
     * compatibility fallback, is owned by RegistrationPolicyService. Keeping
     * env-secret handling out of this HTTP controller avoids parallel policy
     * paths and makes admin-managed registration the single source of truth.
     */
    public function login(): void
    {
        $this->renderLogin();
    }

    public function sigin(Request $request): void
    {
        $login = trim((string) $request->post('login'));
        $password = (string) $request->rawPost('password');

        $user = UserModel::select()->where('username', '=', $login)->first();
        if (!$user || !CryptMethods::verifyPassword($password, $user->password_hash)) {
            SecurityEventLog::emit(
                'auth.login_failed',
                'warning',
                'auth',
                'anonymous',
                null,
                [
                    'client_ip' => RequestOrigin::clientIp($_SERVER),
                    'login_hash' => substr(hash('sha256', mb_strtolower($login)), 0, 24),
                ]
            );
            $this->renderLogin([[
                'CODE' => 'login_error',
                'MESSAGE' => 'Неверный логин или пароль',
            ]]);
            return;
        }

        if (
            (int) $user->is_active !== 1
            || (string) ($user->account_status ?? '') !== 'active'
        ) {
            SecurityEventLog::emit(
                'auth.login_blocked',
                'warning',
                'auth',
                'user',
                (int) $user->id,
                ['client_ip' => RequestOrigin::clientIp($_SERVER)]
            );
            $this->renderLogin([[
                'CODE' => 'login_error',
                'MESSAGE' => 'Учетная запись недоступна',
            ]]);
            return;
        }

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Не удалось обновить идентификатор сессии');
        }

        $request->setSession('auth', true);
        $request->setSession('user_id', $user->id);
        $request->setSession('user_uid', $user->uid);
        // Rotate the form token together with the authenticated session id. This
        // prevents a token from an anonymous/stale login page from surviving the
        // authentication boundary.
        $request->setSession('_csrf_token', bin2hex(random_bytes(32)));
        SessionSecurity::refreshCurrentSessionCookie();

        SecurityEventLog::emit(
            'auth.login_success',
            'info',
            'auth',
            'user',
            (int) $user->id,
            ['client_ip' => RequestOrigin::clientIp($_SERVER)]
        );
        Router::getInstance()->redirect('main', 'name');
    }

    public function logout(Request $request): void
    {
        $actorId = (int) $request->session('user_id', 0);
        SessionSecurity::destroyCurrentSession();
        SecurityEventLog::emit(
            'auth.logout',
            'info',
            'auth',
            $actorId > 0 ? 'user' : 'anonymous',
            $actorId > 0 ? $actorId : null,
            ['client_ip' => RequestOrigin::clientIp($_SERVER)]
        );
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
            $this->noStoreAuthPage();
            $this->render_template('login_page/register_view', $data);
            return;
        }

        $input = [
            'login' => trim((string) $request->post('login')),
            'password' => (string) $request->rawPost('password'),
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
            $this->noStoreAuthPage();
            $this->render_template('login_page/register_view', $data);
        } catch (Throwable $e) {
            error_log('Registration failed: ' . $e->getMessage());
            $data['errors'][] = [
                'CODE' => 'registration_error',
                'MESSAGE' => 'Не удалось создать аккаунт. Повторите попытку позже.',
            ];
            $this->noStoreAuthPage();
            $this->render_template('login_page/register_view', $data);
        }
    }

    /** @param array<int,array{CODE:string,MESSAGE:string}> $errors */
    private function renderLogin(array $errors = []): void
    {
        $this->noStoreAuthPage();

        $registrationMode = RegistrationPolicyService::MODE_DISABLED;
        try {
            $registrationMode = (new RegistrationPolicyService())->mode();
        } catch (Throwable $e) {
            error_log('Registration policy lookup failed on login page: ' . $e->getMessage());
        }

        $data = ['registration_mode' => $registrationMode];
        if ($errors !== []) {
            $data['errors'] = $errors;
        }

        $this->render_template('login_page/login_view', $data);
    }

    private function noStoreAuthPage(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}
