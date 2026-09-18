<?php

declare(strict_types=1);

namespace App\Middlewares;

use Core\Request;

final class RequireLicenseManage
{
    public function handle(Request $request): bool
    {
        return RequirePermission::check($request, 'admin.settings.manage');
    }
}
