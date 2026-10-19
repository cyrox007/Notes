<?php
declare(strict_types=1);

namespace Modules\Profile;

use Core\AccountAvatarCleanup;
use Core\DatabaseManager;
use Core\ProfileCustomFields;
use App\Services\UserAvatarService;

/**
 * Marker/service exported as `workspace.profile`.
 * Cross-module consumers resolve this capability instead of reaching
 * into Profile controllers/services directly.
 */
final class ProfileCapability implements AccountAvatarCleanup, ProfileCustomFields
{
    /** @param array<string,mixed> $user */
    public function removeStoredAvatar(array $user): void
    {
        (new UserAvatarService())->removeStoredAvatar($user);
    }

    /** @return list<array<string,mixed>> */
    public function listFields(): array
    {
        return (new ProfileCustomFieldsService(DatabaseManager::getInstance()))->listFields();
    }

    /** @param list<array{id:?int,field_name:string,field_label:string,field_type:string,is_required:int}> $fields */
    public function saveFields(array $fields): void
    {
        (new ProfileCustomFieldsService(DatabaseManager::getInstance()))->saveFields($fields);
    }

    public function moduleId(): string
    {
        return 'profile';
    }
}
