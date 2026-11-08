<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Models\UserModel;
use Core\Request;
use Core\Router;
use Core\SessionSecurity;

class LoginRequared
{
    public function handle(Request $request): bool
    {
        $routeManager = Router::getInstance();
        $userId = (int) $request->session('user_id', 0);

        if (!$request->session('auth') || $userId <= 0) {
            $routeManager->redirect('authpage', 'name');
            return false;
        }

        // Для каждого защищённого HTTP-запроса проверяем статус аккаунта
        // и версию авторизации: старые PHP-сеансы блокируются после отзыва
        // E2EE-устройства без перебора файлов или хранилища сеансов.
        $user = UserModel::select('id', 'is_active', 'account_status', 'auth_session_epoch')
            ->where('id', '=', $userId)
            ->first();

        if (
            !$user
            || (int) $user->is_active !== 1
            || (string) $user->account_status !== 'active'
            || !SessionSecurity::matchesAuthSessionEpoch(
                $request->session('auth_session_epoch'),
                $user->auth_session_epoch ?? null
            )
        ) {
            $request->unsetSession('auth_session_epoch');
            $request->unsetSession('auth');
            $request->unsetSession('user_id');
            $request->unsetSession('user_uid');
            session_regenerate_id(true);
            $routeManager->redirect('authpage', 'name');
            return false;
        }

        return true;
    }
}
