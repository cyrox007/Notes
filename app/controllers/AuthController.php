<?php

declare(strict_types=1);

namespace App\Controllers;

require_once dirname(__DIR__, 2) . '/core/SecurityEventLog.php';

use App\Helpers\CryptMethods;
use App\Models\UserModel;
use App\Services\RegistrationPolicyService;
use App\Services\TwoFactorPolicyService;
use App\Services\TwoFactorService;
use App\Services\UserProvisioningService;
use Core\Controller;
use Core\DatabaseManager;
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
        $this->clearTwoFactorPending($request);
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

        if ((int) ($user->totp_enabled ?? 0) === 1) {
            $this->beginTwoFactor($request, (int) $user->id);
            Router::getInstance()->redirect('auth_two_factor', 'name');
            return;
        }

        if ((new TwoFactorPolicyService())->required()) {
            $this->beginRequiredTwoFactorEnrollment($request, (int) $user->id, (string) $user->uid);
            Router::getInstance()->redirect('auth_two_factor_setup', 'name');
            return;
        }

        $this->completeLogin($request, (int) $user->id, (string) $user->uid, false, false);
    }

    public function twoFactor(Request $request): void
    {
        $user = $this->pendingTwoFactorUser($request);
        if ($user === null) {
            $this->clearTwoFactorPending($request);
            Router::getInstance()->redirect('authpage', 'name');
            return;
        }

        $this->renderTwoFactor($user);
    }

    public function verifyTwoFactor(Request $request): void
    {
        $user = $this->pendingTwoFactorUser($request);
        if ($user === null) {
            $this->clearTwoFactorPending($request);
            Router::getInstance()->redirect('authpage', 'name');
            return;
        }

        $code = trim((string) $request->rawPost('code', ''));
        $result = (new TwoFactorService())->verifyAndConsume(
            DatabaseManager::getInstance(),
            (int) $user['id'],
            $code
        );

        if (!$result['ok']) {
            SecurityEventLog::emit(
                'auth.two_factor_failed',
                'warning',
                'auth',
                'user',
                (int) $user['id'],
                ['client_ip' => RequestOrigin::clientIp($_SERVER)]
            );
            $this->renderTwoFactor($user, [[
                'CODE' => 'two_factor_error',
                'MESSAGE' => 'Неверный или уже использованный код подтверждения',
            ]]);
            return;
        }

        SecurityEventLog::emit(
            'auth.two_factor_success',
            'info',
            'auth',
            'user',
            (int) $user['id'],
            [
                'client_ip' => RequestOrigin::clientIp($_SERVER),
                'used_recovery_code' => (bool) $result['used_recovery'],
            ]
        );

        $this->clearTwoFactorPending($request);
        $this->completeLogin(
            $request,
            (int) $user['id'],
            (string) $user['uid'],
            true,
            (bool) $result['used_recovery']
        );
    }

    public function requiredTwoFactorSetup(Request $request): void
    {
        $enrollment = $this->pendingRequiredTwoFactorEnrollment($request);
        if ($enrollment === null) {
            $this->clearTwoFactorPending($request);
            Router::getInstance()->redirect('authpage', 'name');
            return;
        }

        $this->renderRequiredTwoFactorSetup($enrollment);
    }

    public function confirmRequiredTwoFactorSetup(Request $request): void
    {
        $enrollment = $this->pendingRequiredTwoFactorEnrollment($request);
        if ($enrollment === null) {
            $this->clearTwoFactorPending($request);
            Router::getInstance()->redirect('authpage', 'name');
            return;
        }

        $code = trim((string) $request->rawPost('code', ''));
        $service = new TwoFactorService();
        $counter = $service->matchingCounter((string) $enrollment['secret'], $code);
        if ($counter === null) {
            SecurityEventLog::emit(
                'auth.two_factor_required_enrollment_failed',
                'warning',
                'auth',
                'user',
                (int) $enrollment['id'],
                ['client_ip' => RequestOrigin::clientIp($_SERVER)]
            );
            $this->renderRequiredTwoFactorSetup($enrollment, [[
                'CODE' => 'two_factor_setup_error',
                'MESSAGE' => 'Код аутентификатора неверен или уже устарел',
            ]]);
            return;
        }

        $recoveryCodes = $service->generateRecoveryCodes();
        DatabaseManager::getInstance()->execute(
            'UPDATE users SET totp_enabled = 1, totp_secret = :secret, '
            . 'totp_last_counter = :counter, totp_recovery_codes = :recovery_codes, '
            . 'totp_confirmed_at = :confirmed_at, updated_at = :updated_at '
            . 'WHERE id = :id AND is_active = 1 AND account_status = \'active\'',
            [
                ':secret' => (string) $enrollment['encrypted_secret'],
                ':counter' => $counter,
                ':recovery_codes' => $service->hashRecoveryCodes($recoveryCodes),
                ':confirmed_at' => date('Y-m-d H:i:s'),
                ':updated_at' => date('Y-m-d H:i:s'),
                ':id' => (int) $enrollment['id'],
            ]
        );

        $this->clearTwoFactorPending($request);
        $request->setSession('two_factor_recovery_codes', $recoveryCodes);

        SecurityEventLog::emit(
            'auth.two_factor_required_enrollment_completed',
            'info',
            'auth',
            'user',
            (int) $enrollment['id'],
            ['client_ip' => RequestOrigin::clientIp($_SERVER)]
        );

        $this->completeLogin(
            $request,
            (int) $enrollment['id'],
            (string) $enrollment['uid'],
            true,
            false,
            'auth_two_factor_recovery'
        );
    }

    public function recoveryCodes(Request $request): void
    {
        $codes = $request->session('two_factor_recovery_codes', []);
        $codes = is_array($codes) ? array_values(array_filter($codes, 'is_string')) : [];
        $request->unsetSession('two_factor_recovery_codes');

        if ($codes === []) {
            Router::getInstance()->redirect('main', 'name');
            return;
        }

        $this->noStoreAuthPage();
        $this->render_template('login_page/two_factor_recovery_view', [
            'recovery_codes' => $codes,
        ]);
    }

    private function beginRequiredTwoFactorEnrollment(Request $request, int $userId, string $userUid): void
    {
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Не удалось обновить идентификатор сессии');
        }

        $service = new TwoFactorService();
        $secret = $service->generateSecret();

        $request->unsetSession('auth');
        $request->unsetSession('user_id');
        $request->unsetSession('user_uid');
        $request->setSession('two_factor_required_enrollment', [
            'user_id' => $userId,
            'secret' => $service->encryptSecret($secret, $userUid),
            'started_at' => time(),
        ]);
        $request->setSession('_csrf_token', bin2hex(random_bytes(32)));
    }

    /**
     * @return array{id:int,uid:string,username:string,secret:string,encrypted_secret:string,uri:string}|null
     */
    private function pendingRequiredTwoFactorEnrollment(Request $request): ?array
    {
        $state = $request->session('two_factor_required_enrollment');
        if (!is_array($state) || !(new TwoFactorPolicyService())->required()) {
            return null;
        }

        $userId = (int) ($state['user_id'] ?? 0);
        $startedAt = (int) ($state['started_at'] ?? 0);
        $encryptedSecret = (string) ($state['secret'] ?? '');
        if ($userId <= 0 || $startedAt <= 0 || (time() - $startedAt) > 600 || $encryptedSecret === '') {
            return null;
        }

        $user = DatabaseManager::getInstance()->fetchOne(
            'SELECT id,uid,username,totp_enabled FROM users '
            . 'WHERE id = :id AND is_active = 1 AND account_status = \'active\' LIMIT 1',
            [':id' => $userId]
        );
        if (!$user || (int) ($user['totp_enabled'] ?? 0) === 1) {
            return null;
        }

        try {
            $service = new TwoFactorService();
            $secret = $service->decryptSecret($encryptedSecret, (string) $user['uid']);
            return [
                'id' => (int) $user['id'],
                'uid' => (string) $user['uid'],
                'username' => (string) $user['username'],
                'secret' => $secret,
                'encrypted_secret' => $encryptedSecret,
                'uri' => $service->provisioningUri(
                    (string) $user['username'],
                    'Workspace Organizer',
                    $secret
                ),
            ];
        } catch (Throwable $e) {
            error_log('Required two-factor enrollment state could not be decrypted: ' . $e->getMessage());
            return null;
        }
    }

    /** @param array<int,array{CODE:string,MESSAGE:string}> $errors */
    private function renderRequiredTwoFactorSetup(array $enrollment, array $errors = []): void
    {
        $this->noStoreAuthPage();
        $data = [
            'account' => (string) ($enrollment['username'] ?? ''),
            'secret' => (string) ($enrollment['secret'] ?? ''),
            'uri' => (string) ($enrollment['uri'] ?? ''),
        ];
        if ($errors !== []) {
            $data['errors'] = $errors;
        }
        $this->render_template('login_page/two_factor_setup_view', $data);
    }

    private function beginTwoFactor(Request $request, int $userId): void
    {
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Не удалось обновить идентификатор сессии');
        }

        $request->unsetSession('auth');
        $request->unsetSession('user_id');
        $request->unsetSession('user_uid');
        $request->setSession('two_factor_pending_user_id', $userId);
        $request->setSession('two_factor_pending_started_at', time());
        $request->setSession('_csrf_token', bin2hex(random_bytes(32)));
    }

    /**
     * @return array{id:int,uid:string,username:string}|null
     */
    private function pendingTwoFactorUser(Request $request): ?array
    {
        $userId = (int) $request->session('two_factor_pending_user_id', 0);
        $startedAt = (int) $request->session('two_factor_pending_started_at', 0);
        if ($userId <= 0 || $startedAt <= 0 || (time() - $startedAt) > 300) {
            return null;
        }

        return DatabaseManager::getInstance()->fetchOne(
            'SELECT id,uid,username FROM users '
            . 'WHERE id = :id AND is_active = 1 AND account_status = \'active\' AND totp_enabled = 1 LIMIT 1',
            [':id' => $userId]
        );
    }

    private function clearTwoFactorPending(Request $request): void
    {
        $request->unsetSession('two_factor_pending_user_id');
        $request->unsetSession('two_factor_pending_started_at');
        $request->unsetSession('two_factor_required_enrollment');
    }

    private function completeLogin(
        Request $request,
        int $userId,
        string $userUid,
        bool $twoFactorVerified,
        bool $usedRecoveryCode,
        string $redirectRoute = 'main'
    ): void {
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Не удалось обновить идентификатор сессии');
        }

        $request->setSession('auth', true);
        $request->setSession('user_id', $userId);
        $request->setSession('user_uid', $userUid);
        $request->setSession('_csrf_token', bin2hex(random_bytes(32)));
        SessionSecurity::refreshCurrentSessionCookie();

        SecurityEventLog::emit(
            'auth.login_success',
            'info',
            'auth',
            'user',
            $userId,
            [
                'client_ip' => RequestOrigin::clientIp($_SERVER),
                'two_factor_verified' => $twoFactorVerified,
                'used_recovery_code' => $usedRecoveryCode,
            ]
        );
        Router::getInstance()->redirect($redirectRoute, 'name');
    }

    /** @param array<int,array{CODE:string,MESSAGE:string}> $errors */
    private function renderTwoFactor(array $user, array $errors = []): void
    {
        $this->noStoreAuthPage();
        $data = ['account' => (string) ($user['username'] ?? '')];
        if ($errors !== []) {
            $data['errors'] = $errors;
        }
        $this->render_template('login_page/two_factor_view', $data);
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
