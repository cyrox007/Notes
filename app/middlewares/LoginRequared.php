<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Models\UserModel;
use Core\Config;
use Core\Request;
use Core\Router;

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

        // Re-check both role and active state on every protected request so an
        // account disabled after login cannot keep using an existing session.
        $user = UserModel::select('id', 'role', 'is_active')
            ->where('id', '=', $userId)
            ->first();

        if (
            !$user
            || (int) $user->is_active !== 1
            || !Config::canAuthenticate((int) $user->role)
        ) {
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
