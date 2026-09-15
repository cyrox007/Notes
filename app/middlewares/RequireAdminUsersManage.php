<?php

declare(strict_types=1);

namespace App\Middlewares;

final class RequireAdminUsersManage extends RequirePermission
{
    protected const PERMISSION = 'admin.users.manage';
}
