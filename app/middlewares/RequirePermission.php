<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\PermissionService;
use Core\Request;
use Core\Router;

abstract class RequirePermission
{
    protected const PERMISSION = '';

    public function handle(Request $request): bool
    {
        $userId = (int) $request->session('user_id', 0);
        $permission = static::PERMISSION;

        if ($permission === '' || !(new PermissionService())->hasPermission($userId, $permission)) {
            Router::getInstance()->redirect('main', 'name');
            return false;
        }

        return true;
    }
}
