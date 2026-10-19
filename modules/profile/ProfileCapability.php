<?php
declare(strict_types=1);

namespace Modules\Profile;

use Core\AccountAvatarCleanup;
use App\Services\UserAvatarService;

/**
 * Marker/service exported as `workspace.profile`.
 * Cross-module consumers resolve this capability instead of reaching
 * into Profile controllers/services directly.
 */
final class ProfileCapability implements AccountAvatarCleanup
{
    /** @param array<string,mixed> $user */
    public function removeStoredAvatar(array $user): void
    {
        (new UserAvatarService())->removeStoredAvatar($user);
    }

    public function moduleId(): string
    {
        return 'profile';
    }
}
