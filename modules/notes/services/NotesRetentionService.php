<?php

declare(strict_types=1);

namespace Modules\Notes;

use Core\ModuleRetentionCleanup;
use Core\RetentionCleanupContext;

/** Хранение и очистка принадлежат модулю Notes. */
final class NotesRetentionService implements ModuleRetentionCleanup
{
    public function moduleId(): string
    {
        return 'notes';
    }

    /** @return array<string,int> */
    public function preview(string $cutoff, int $limit, RetentionCleanupContext $context): array
    {
        $result = [];
        $count = $context->countRows('note_attachments',
            'is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff', $cutoff, $limit);
        if ($count !== null) {
            $result['note_attachments'] = $count;
        }
        if ($context->hasTable('notes')) {
            $filter = $context->hasTable('note_attachments')
                ? 'AND NOT EXISTS (SELECT 1 FROM note_attachments a WHERE a.note_id=n.id '
                    . 'AND (a.is_deleted=0 OR a.deleted_at IS NULL OR a.deleted_at >= :attachment_cutoff))'
                : '';
            $result['notes'] = min($limit, (int) $context->db->fetchValue(
                'SELECT COUNT(*) FROM notes n WHERE n.is_deleted=1 '
                . 'AND n.deleted_at IS NOT NULL AND n.deleted_at < :cutoff ' . $filter,
                $filter !== ''
                    ? [':cutoff' => $cutoff, ':attachment_cutoff' => $cutoff]
                    : [':cutoff' => $cutoff]
            ));
        }
        return $result;
    }

    /** @param array<string,mixed> $result */
    public function apply(string $cutoff, int $limit, RetentionCleanupContext $context, array &$result): void
    {
        $this->purgeNoteAttachments($cutoff, $limit, $context, $result);
        $this->purgeNotes($cutoff, $limit, $context, $result);
    }

    /** @param array<string,mixed> $result */
    private function purgeNoteAttachments(string $cutoff, int $limit, RetentionCleanupContext $context, array &$result): void
    {
        if (!$context->hasTable('note_attachments')) {
            return;
        }
        $rows = $context->db->fetchAll(
            'SELECT id,file_path FROM note_attachments '
            . 'WHERE is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff '
            . 'ORDER BY id ASC LIMIT ' . $limit,
            [':cutoff' => $cutoff]
        );
        $purged = 0;
        foreach ($rows as $row) {
            if (!$context->removePaths([(string) ($row['file_path'] ?? '')], $result)) {
                continue;
            }
            $purged += $context->db->execute(
                'DELETE FROM note_attachments WHERE id = :id AND is_deleted = 1 AND deleted_at < :cutoff',
                [':id' => (int) $row['id'], ':cutoff' => $cutoff]
            );
        }
        $context->addPurged($result, 'note_attachments', $purged);
    }

    /** @param array<string,mixed> $result */
    private function purgeNotes(string $cutoff, int $limit, RetentionCleanupContext $context, array &$result): void
    {
        if (!$context->hasTable('notes')) {
            return;
        }
        $rows = $context->db->fetchAll(
            'SELECT n.id FROM notes n WHERE n.is_deleted = 1 AND n.deleted_at IS NOT NULL AND n.deleted_at < :cutoff '
            . ($context->hasTable('note_attachments')
                ? 'AND NOT EXISTS (SELECT 1 FROM note_attachments a WHERE a.note_id=n.id '
                    . 'AND (a.is_deleted=0 OR a.deleted_at IS NULL OR a.deleted_at >= :attachment_cutoff)) '
                : '')
            . 'ORDER BY n.id ASC LIMIT ' . $limit,
            $context->hasTable('note_attachments')
                ? [':cutoff' => $cutoff, ':attachment_cutoff' => $cutoff]
                : [':cutoff' => $cutoff]
        );
        $purged = 0;
        foreach ($rows as $row) {
            $noteId = (int) $row['id'];
            $paths = [];
            if ($context->hasTable('note_attachments')) {
                $attachments = $context->db->fetchAll(
                    'SELECT file_path FROM note_attachments WHERE note_id = :note_id',
                    [':note_id' => $noteId]
                );
                foreach ($attachments as $attachment) {
                    $paths[] = (string) ($attachment['file_path'] ?? '');
                }
            }
            if (!$context->removePaths($paths, $result)) {
                continue;
            }
            $purged += $context->db->execute(
                'DELETE FROM notes WHERE id = :id AND is_deleted = 1 AND deleted_at < :cutoff',
                [':id' => $noteId, ':cutoff' => $cutoff]
            );
        }
        $context->addPurged($result, 'notes', $purged);
    }
}
