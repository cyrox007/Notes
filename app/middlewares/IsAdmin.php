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

        if ($userId <= 0) {
            $routeManager->redirect('authpage', 'name');
            return false;
        }

        $user = UserModel::select('id', 'role')
            ->where('id', '=', $userId)
            ->first();

        if (!$user || !Config::isAdminRole((int) $user->role)) {
            $routeManager->redirect('main', 'name');
            return false;
        }

        return true;
    }
}