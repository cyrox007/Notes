<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\PermissionService;
use Core\Request;
use Core\Router;

/**
 * Shared permission gate for legacy router middleware wrappers.
 *
 * This class is deliberately composed rather than inherited from: core.php
 * still loads app/middlewares recursively without dependency ordering, so a
 * concrete middleware must be safe to declare before this helper file is read.
 */
final class RequirePermission
{
    public static function check(Request $request, string $permission): bool
    {
        $userId = (int) $request->session('user_id', 0);

        if ($permission === '' || !(new PermissionService())->hasPermission($userId, $permission)) {
            Router::getInstance()->redirect('main', 'name');
            return false;
        }

        return true;
    }
}
