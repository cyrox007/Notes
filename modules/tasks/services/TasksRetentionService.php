<?php

declare(strict_types=1);

namespace Modules\Tasks;

use Core\ModuleRetentionCleanup;
use Core\RetentionCleanupContext;

/** Хранение и очистка принадлежат модулю Tasks. */
final class TasksRetentionService implements ModuleRetentionCleanup
{
    public function moduleId(): string
    {
        return 'tasks';
    }

    /** @return array<string,int> */
    public function preview(string $cutoff, int $limit, RetentionCleanupContext $context): array
    {
        $conditions = [
            'tasks' => 'is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff',
            'task_board_items' => 'is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff',
            'task_categories' => 'is_deleted = 1 AND updated_at < :cutoff',
        ];
        $result = [];
        foreach ($conditions as $table => $where) {
            $count = $context->countRows($table, $where, $cutoff, $limit);
            if ($count !== null) {
                $result[$table] = $count;
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $result */
    public function apply(string $cutoff, int $limit, RetentionCleanupContext $context, array &$result): void
    {
        $context->purgeSimpleRows('tasks',
            'is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff',
            $cutoff, $limit, $result);
        $context->purgeSimpleRows('task_board_items',
            'is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff',
            $cutoff, $limit, $result);
        $context->purgeSimpleRows('task_categories',
            'is_deleted = 1 AND updated_at < :cutoff',
            $cutoff, $limit, $result);
    }

}
