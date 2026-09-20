<?php
declare(strict_types=1);

namespace Modules\Profile;

/**
 * Marker/service exported as `workspace.profile`.
 * Cross-module consumers resolve this capability instead of reaching
 * into Profile controllers/services directly.
 */
final class ProfileCapability
{
    public function moduleId(): string
    {
        return 'profile';
    }
}
