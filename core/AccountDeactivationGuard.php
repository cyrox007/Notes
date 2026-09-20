<?php

declare(strict_types=1);

namespace Core;

interface AccountDeactivationGuard
{
    /**
     * Return a user-facing blocker when this active module prevents account
     * deactivation, or null when the module has no blocking state for the user.
     *
     * @return array{code:string,message:string}|null
     */
    public function accountDeactivationBlocker(int $userId): ?array;
}
