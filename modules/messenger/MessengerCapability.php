<?php

declare(strict_types=1);

namespace Modules\Messenger;

use Core\AccountDeactivationGuard;
use Core\DatabaseManager;

final class MessengerCapability implements AccountDeactivationGuard
{
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

        $ownedGroup = DatabaseManager::getInstance()->fetchOne(
            "SELECT COALESCE(NULLIF(d.name, ''), 'Без названия') AS name
             FROM user_to_dialogs utd
             JOIN dialogs d ON d.id = utd.dialog_id
             WHERE utd.user_id = :user_id
               AND utd.role = 'owner'
               AND utd.is_deleted = 0
               AND d.type = 'group'
             LIMIT 1",
            [':user_id' => $userId]
        );
        if ($ownedGroup === null) {
            return null;
        }

        return [
            'code' => 'group_owner_transfer_required',
            'message' => 'Перед деактивацией передайте владение группой «'
                . (string) ($ownedGroup['name'] ?? 'Без названия')
                . '» другому участнику.',
        ];
    }
}
