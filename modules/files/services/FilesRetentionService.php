<?php

declare(strict_types=1);

namespace Modules\Files;

use Core\ModuleRetentionCleanup;
use Core\RetentionCleanupContext;

/** Хранение и очистка принадлежат модулю Files. */
final class FilesRetentionService implements ModuleRetentionCleanup
{
    public function moduleId(): string
    {
        return 'files';
    }

    /** @return array<string,int> */
    public function preview(string $cutoff, int $limit, RetentionCleanupContext $context): array
    {
        $count = $context->countRows('user_files',
            'is_deleted = 1 AND updated_at < :cutoff', $cutoff, $limit);
        return $count === null ? [] : ['user_files' => $count];
    }

    /** @param array<string,mixed> $result */
    public function apply(string $cutoff, int $limit, RetentionCleanupContext $context, array &$result): void
    {
        $this->purgeUserFiles($cutoff, $limit, $context, $result);
    }

    /** @param array<string,mixed> $result */
    private function purgeUserFiles(string $cutoff, int $limit, RetentionCleanupContext $context, array &$result): void
    {
        if (!$context->hasTable('user_files')) {
            return;
        }
        $rows = $context->db->fetchAll(
            "SELECT id,path FROM user_files WHERE is_deleted = 1 AND updated_at < :cutoff AND type <> 'folder' "
            . 'ORDER BY id ASC LIMIT ' . $limit,
            [':cutoff' => $cutoff]
        );
        $purged = 0;
        foreach ($rows as $row) {
            if (!$context->removePaths([(string) ($row['path'] ?? '')], $result)) {
                continue;
            }
            $purged += $context->db->execute(
                "DELETE FROM user_files WHERE id = :id AND is_deleted = 1 AND updated_at < :cutoff AND type <> 'folder'",
                [':id' => (int) $row['id'], ':cutoff' => $cutoff]
            );
        }

        // Delete only empty folders so ON DELETE CASCADE cannot pull a newer child
        // across the retention boundary.
        $folders = $context->db->fetchAll(
            "SELECT uf.id FROM user_files uf "
            . "WHERE uf.is_deleted = 1 AND uf.updated_at < :cutoff AND uf.type = 'folder' "
            . 'AND NOT EXISTS (SELECT 1 FROM user_files child WHERE child.parent_id = uf.id) '
            . 'ORDER BY uf.id ASC LIMIT ' . $limit,
            [':cutoff' => $cutoff]
        );
        foreach ($folders as $folder) {
            $purged += $context->db->execute(
                "DELETE FROM user_files WHERE id = :id AND is_deleted = 1 AND updated_at < :cutoff AND type = 'folder'",
                [':id' => (int) $folder['id'], ':cutoff' => $cutoff]
            );
        }
        $context->addPurged($result, 'user_files', $purged);
    }
}
