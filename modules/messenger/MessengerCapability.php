<?php

declare(strict_types=1);

namespace Modules\Messenger;

use Core\AccountDeactivationGuard;
use Core\DatabaseManager;

final class MessengerCapability implements AccountDeactivationGuard
{
    public function __construct(private ?DatabaseManager $database = null)
    {
    }

    public function id(): string
    {
        return 'workspace.messenger';
    }

    /** @return array{code:string,message:string}|null */
    public function accountDeactivationBlocker(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $ownedGroupName = (new MessengerAccountOwnership($this->database ??= DatabaseManager::getInstance()))
            ->ownedGroupName($userId);
        if ($ownedGroupName === null) {
            return null;
        }

        return [
            'code' => 'group_owner_transfer_required',
            'message' => 'Перед деактивацией передайте владение группой «'
                . $ownedGroupName
                . '» другому участнику.',
        ];
    }
}
