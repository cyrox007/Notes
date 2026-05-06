<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Models\UserModel;
use Core\Request;
use Core\Router;

class IsAdmin
{
    public function handle(Request $request): bool
    {
        $routeManager = Router::getInstance();

        $user = UserModel::select('uid', 'role')->where('uid', '=', $request->session('user_uid'))->first();
        
        if ($user->role < 900) {
            return $routeManager->redirect('main', 'name');
        }

        return true;
    }
}