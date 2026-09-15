<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Models\UserModel;
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

        // Account availability is independent from RBAC role identity. Re-check
        // it on every protected request so block/deactivation takes effect for
        // already established sessions without rewriting role assignments.
        $user = UserModel::select('id', 'is_active', 'account_status')
            ->where('id', '=', $userId)
            ->first();

        if (
            !$user
            || (int) $user->is_active !== 1
            || (string) $user->account_status !== 'active'
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
