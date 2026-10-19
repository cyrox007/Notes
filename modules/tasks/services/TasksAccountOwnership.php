<?php

declare(strict_types=1);

namespace Modules\Tasks;

use Core\DatabaseManager;

/** Проверка владения досками выполняется только модулем Tasks. */
final class TasksAccountOwnership
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    public function ownedSharedBoardName(int $userId): ?string
    {
        $row = $this->db->fetchOne(
            "SELECT name FROM task_boards b
             WHERE b.owner_user_id=:user_id
               AND (b.audience='all_active'
                    OR EXISTS (
                        SELECT 1 FROM task_board_members m
                        WHERE m.board_id=b.id AND m.user_id<>:other_user_id
                    ))
             LIMIT 1",
            [':user_id' => $userId, ':other_user_id' => $userId]
        );
        return $row === null ? null : (string) ($row['name'] ?? 'Без названия');
    }
}
