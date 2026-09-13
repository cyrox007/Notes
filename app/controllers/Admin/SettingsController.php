<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\UserModel;
use App\Services\StorageQuotaService;
use Core\Controller;
use Core\Request;
use Core\Router;

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
        $this->render_template('admin-page/settings', [
            'user' => $user,
            'default_quota_bytes' => $service->defaultQuotaBytes(),
            'storage_users' => $service->adminUsage($actorId),
            'settings_flash' => is_array($flash) ? $flash : null,
        ]);
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
