<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Models\UserModel;
use Core\Config;
use Core\Request;
use Core\Router;

class IsAdmin
{
    public function handle(Request $request): bool
    {
        $routeManager = Router::getInstance();
        $userId = (int) $request->session('user_id', 0);

        $user = $userId > 0
            ? UserModel::select('id', 'role', 'is_active')->where('id', '=', $userId)->first()
            : null;

        if (
            !$user
            || (int) $user->is_active !== 1
            || !Config::isAdminRole((int) $user->role)
        ) {
            $routeManager->redirect('main', 'name');
            return false;
        }

        return true;
    }
}
