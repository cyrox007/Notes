<?php

declare(strict_types=1);

namespace App\Middlewares;

use Core\Request;

final class RequireProfileUse
{
    public function handle(Request $request): bool
    {
        return RequirePermission::check($request, 'profile.use');
    }
}
