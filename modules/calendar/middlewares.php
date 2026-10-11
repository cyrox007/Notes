<?php

declare(strict_types=1);

namespace Modules\Calendar;

use App\Services\PermissionService;
use Core\Request;
use Core\Router;

final class RequireCalendarUse
{
    public function handle(Request $request): bool
    {
        $userId = (int) $request->session('user_id', 0);
        if (!(new PermissionService())->hasPermission($userId, 'calendar.use')) {
            Router::getInstance()->redirect('main', 'name');
            return false;
        }
        return true;
    }
}
