<?php

declare(strict_types=1);

namespace Modules\Messenger;

use Core\DatabaseManager;

/** Проверка владения группами. Схема Messenger не видна потребителям. */
final class MessengerAccountOwnership
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    public function ownedGroupName(int $userId): ?string
    {
        $row = $this->db->fetchOne(
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
        return $row === null ? null : (string) $row['name'];
    }
}
