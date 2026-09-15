<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\PermissionService;
use Core\Request;
use Core\Router;

/**
 * @deprecated Use explicit RequireAdmin* permission middleware on routes.
 */
class IsAdmin
{
    public function handle(Request $request): bool
    {
        $userId = (int) $request->session('user_id', 0);
        if (!(new PermissionService())->hasPermission($userId, 'admin.access')) {
            Router::getInstance()->redirect('main', 'name');
            return false;
        }

        return true;
    }
}
