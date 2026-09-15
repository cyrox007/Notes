<?php

declare(strict_types=1);

namespace App\Middlewares;

use Core\Request;

final class RequireAdminUsersManage
{
    public function handle(Request $request): bool
    {
        return RequirePermission::check($request, 'admin.users.manage');
    }
}
