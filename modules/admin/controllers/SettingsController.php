<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Helpers\CryptMethods;
use App\Models\UserModel;
use App\Services\StorageQuotaService;
use App\Services\TwoFactorPolicyService;
use Core\Controller;
use Core\Request;
use Core\RequestOrigin;
use Core\Router;
use Core\SecurityEventLog;

final class SettingsController extends Controller
{
    public function index(Request $request): void
    {
        $actorId = (int) $request->session('user_id', 0);
        $user = UserModel::select()->where('id', '=', $actorId)->first();
        if (!$user) {
            http_response_code(401);
            return;
        }

        $service = new StorageQuotaService();
        $flash = $request->session('settings_flash');
        $request->unsetSession('settings_flash');
        $this->render_template('@admin/settings', [
            'user' => $user,
            'default_quota_bytes' => $service->defaultQuotaBytes(),
            'storage_users' => $service->adminUsage($actorId),
            'two_factor_required' => (new TwoFactorPolicyService())->required(),
            'settings_flash' => is_array($flash) ? $flash : null,
        ]);
    }

    public function saveTwoFactorPolicy(Request $request): void
    {
        try {
            $actorId = (int) $request->session('user_id', 0);
            $user = UserModel::select()->where('id', '=', $actorId)->first();
            $password = (string) $request->rawPost('current_password', '');
            if (!$user || !CryptMethods::verifyPassword($password, (string) $user->password_hash)) {
                throw new \DomainException('Неверный текущий пароль');
            }

            $required = (string) $request->post('two_factor_required', '0') === '1';
            (new TwoFactorPolicyService())->setRequired($required);

            SecurityEventLog::emit(
                'auth.two_factor_policy_changed',
                'warning',
                'auth',
                'user',
                $actorId,
                [
                    'required' => $required,
                    'client_ip' => RequestOrigin::clientIp($_SERVER),
                ]
            );

            $message = $required
                ? 'Двухфакторная аутентификация теперь обязательна для всех активных пользователей'
                : 'Обязательная 2FA отключена; личные настройки пользователей сохранены';
            $this->redirectWithFlash($request, true, $message);
        } catch (\Throwable $e) {
            $this->redirectWithFlash(
                $request,
                false,
                $e instanceof \DomainException ? $e->getMessage() : 'Не удалось изменить политику 2FA'
            );
        }
    }

    public function saveDefaultQuota(Request $request): void
    {
        try {
            $quota = $this->megabytesToBytes($request->post('default_quota_mb'));
            (new StorageQuotaService())->setDefaultQuota((int) $request->session('user_id', 0), $quota);
            $this->redirectWithFlash($request, true, 'Лимит по умолчанию обновлён');
        } catch (\Throwable $e) {
            $this->redirectWithFlash($request, false, $e->getMessage() ?: 'Не удалось сохранить лимит');
        }
    }

    public function saveUserQuota(Request $request): void
    {
        try {
            $raw = trim((string) $request->post('quota_mb', ''));
            $quota = $raw === '' ? null : $this->megabytesToBytes($raw);
            (new StorageQuotaService())->setUserQuota(
                (int) $request->session('user_id', 0),
                (int) $request->post('user_id', 0),
                $quota
            );
            $this->redirectWithFlash($request, true, $quota === null ? 'Персональный лимит сброшен' : 'Персональный лимит обновлён');
        } catch (\Throwable $e) {
            $this->redirectWithFlash($request, false, $e->getMessage() ?: 'Не удалось сохранить персональный лимит');
        }
    }

    private function megabytesToBytes(mixed $value): int
    {
        $raw = is_scalar($value) ? (string) $value : '';
        if (!is_numeric($raw) || (float) $raw <= 0) {
            throw new \InvalidArgumentException('Укажите положительный лимит в мегабайтах');
        }
        return (int) round((float) $raw * 1024 * 1024);
    }

    private function redirectWithFlash(Request $request, bool $success, string $message): void
    {
        $request->setSession('settings_flash', ['type' => $success ? 'success' : 'error', 'message' => $message]);
        Router::getInstance()->redirect('admin_settings');
    }
}
