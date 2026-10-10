<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use Core\Controller;
use Core\Request;

class MainController extends Controller
{
    public function index(Request $request): ?string
    {
        $user = UserModel::select()
            ->where('id', '=', $request->session('user_id'))
            ->first();

        if (!$user) {
            return 'Пользователь не загружен';
        }

        $this->render_template('main_page/index', [
            'user' => get_object_vars($user),
        ]);

        return null;
    }
}
