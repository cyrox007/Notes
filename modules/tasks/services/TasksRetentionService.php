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
        // Минутные счётчики нужны лишь на время действия короткой квоты.
        $rateCutoff = gmdate('Y-m-d H:i:s', time() - 2 * 86400);
        $rateCount = $context->countRows('task_api_rate_buckets', 'window_start < :cutoff', $rateCutoff, $limit);
        if ($rateCount !== null) {
            $result['task_api_rate_buckets'] = $rateCount;
        }
        $logCount = $context->countRows('task_api_access_log', 'created_at < :cutoff', $cutoff, $limit);
        if ($logCount !== null) {
            $result['task_api_access_log'] = $logCount;
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
        // Составной ключ окна не подходит для purgeSimpleRows (он ожидает id).
        // Оба запроса ограничены объёмом одного прохода общей фоновой очистки.
        $rateCutoff = gmdate('Y-m-d H:i:s', time() - 2 * 86400);
        if ($context->hasTable('task_api_rate_buckets')) {
            $removed = $context->db->execute(
                'DELETE FROM task_api_rate_buckets WHERE window_start < :cutoff LIMIT ' . max(1, $limit),
                [':cutoff' => $rateCutoff]
            );
            $context->addPurged($result, 'task_api_rate_buckets', (int) $removed);
        }
        $context->purgeSimpleRows('task_api_access_log',
            'created_at < :cutoff', $cutoff, $limit, $result);
    }

}
