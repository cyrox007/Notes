<?php

declare(strict_types=1);

namespace App\Middlewares;

final class RequireAdminAccess extends RequirePermission
{
    protected const PERMISSION = 'admin.access';
}
