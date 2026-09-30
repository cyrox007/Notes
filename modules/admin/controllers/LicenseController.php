<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\UserModel;
use App\Services\LicenseService;
use Core\Controller;
use Core\Request;
use Core\Router;

final class LicenseController extends Controller
{
    public function index(Request $request): void
    {
        $actorId = (int) $request->session('user_id', 0);
        $user = UserModel::select()->where('id', '=', $actorId)->first();
        if (!$user) {
            http_response_code(401);
            return;
        }

        $flash = $request->session('license_flash');
        $request->unsetSession('license_flash');

        $this->render_template('@admin/license', [
            'user' => $user,
            'license' => (new LicenseService())->snapshot($actorId),
            'license_flash' => is_array($flash) ? $flash : null,
        ]);
    }

    public function activate(Request $request): void
    {
        try {
            $status = (new LicenseService())->activate(
                (int) $request->session('user_id', 0),
                (string) $request->post('license_token', '')
            );
            $accessReady = ($status['update_access'] ?? '') === 'ready';
            $message = $accessReady
                ? 'Лицензия активирована. Доступ к обновлениям настроен автоматически.'
                : 'Лицензия активирована. Доступ к обновлениям настроится автоматически при следующей проверке.';
            $features = isset($status['features']) && is_array($status['features'])
                ? array_values(array_map('strval', $status['features']))
                : [];
            $this->redirectWithFlash(
                $request,
                true,
                $message,
                in_array('workspace.admin', $features, true)
            );
        } catch (\Throwable $e) {
            $this->redirectWithFlash($request, false, $e->getMessage() ?: 'Не удалось активировать лицензию');
        }
    }

    public function clear(Request $request): void
    {
        try {
            (new LicenseService())->clear((int) $request->session('user_id', 0));
            $this->redirectWithFlash($request, true, 'Лицензионный ключ удалён из установки', false);
        } catch (\Throwable $e) {
            $this->redirectWithFlash($request, false, $e->getMessage() ?: 'Не удалось удалить лицензионный ключ');
        }
    }

    private function redirectWithFlash(
        Request $request,
        bool $success,
        string $message,
        bool $adminWillRemainAvailable = true
    ): void {
        $flashKey = $adminWillRemainAvailable ? 'license_flash' : 'system_license_flash';
        $request->setSession($flashKey, [
            'type' => $success ? 'success' : 'error',
            'message' => $message,
        ]);
        Router::getInstance()->redirect($adminWillRemainAvailable ? 'admin_license' : 'system_license');
    }
}
