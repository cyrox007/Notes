<?php

declare(strict_types=1);

namespace App\Middlewares;

final class RequireAdminSettingsManage extends RequirePermission
{
    protected const PERMISSION = 'admin.settings.manage';
}
