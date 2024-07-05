<?php
namespace App\Middlewares;

use App\Models\UserModel;
use Core\Request;
use Route;

class IsAdmin {
    public function handle(Request $request): bool {
        $routeManager = Route::getInstance();

        $userModel = new UserModel();
        $user = $userModel->select(['uid', 'role'])->where('uid', '=', $request->session('user_uid'))->first();
        
        if ($user['users_role'] < 900) {
            return $routeManager->redirect('main', 'name');
        }

        return true;
    }
}