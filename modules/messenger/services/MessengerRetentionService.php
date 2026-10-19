<?php

declare(strict_types=1);

namespace Modules\Messenger;

use Core\ModuleRetentionCleanup;
use Core\RetentionCleanupContext;

/** Хранение и очистка принадлежат модулю Messenger. */
final class MessengerRetentionService implements ModuleRetentionCleanup
{
    public function moduleId(): string
    {
        return 'messenger';
    }

    /** @return array<string,int> */
    public function preview(string $cutoff, int $limit, RetentionCleanupContext $context): array
    {
        $result = [];
        foreach (['messenger_attachments', 'messages'] as $table) {
            $count = $context->countRows($table,
                'is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff',
                $cutoff, $limit);
            if ($count !== null) {
                $result[$table] = $count;
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $result */
    public function apply(string $cutoff, int $limit, RetentionCleanupContext $context, array &$result): void
    {
        $this->purgeMessengerAttachments($cutoff, $limit, $context, $result);
        $this->purgeMessages($cutoff, $limit, $context, $result);
    }

    /** @param array<string,mixed> $result */
    private function purgeMessengerAttachments(string $cutoff, int $limit, RetentionCleanupContext $context, array &$result): void
    {
        if (!$context->hasTable('messenger_attachments')) {
            return;
        }
        $rows = $context->db->fetchAll(
            'SELECT id,stored_path FROM messenger_attachments '
            . 'WHERE is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff '
            . 'ORDER BY id ASC LIMIT ' . $limit,
            [':cutoff' => $cutoff]
        );
        $purged = 0;
        foreach ($rows as $row) {
            if (!$context->removePaths([(string) ($row['stored_path'] ?? '')], $result)) {
                continue;
            }
            $purged += $context->db->execute(
                'DELETE FROM messenger_attachments WHERE id = :id AND is_deleted = 1 AND deleted_at < :cutoff',
                [':id' => (int) $row['id'], ':cutoff' => $cutoff]
            );
        }
        $context->addPurged($result, 'messenger_attachments', $purged);
    }

    /** @param array<string,mixed> $result */
    private function purgeMessages(string $cutoff, int $limit, RetentionCleanupContext $context, array &$result): void
    {
        if (!$context->hasTable('messages')) {
            return;
        }
        $rows = $context->db->fetchAll(
            'SELECT id FROM messages WHERE is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff '
            . 'ORDER BY id ASC LIMIT ' . $limit,
            [':cutoff' => $cutoff]
        );
        $purged = 0;
        foreach ($rows as $row) {
            $messageId = (int) $row['id'];
            $paths = [];
            if ($context->hasTable('messenger_attachments')) {
                $attachments = $context->db->fetchAll(
                    'SELECT stored_path FROM messenger_attachments WHERE message_id = :message_id',
                    [':message_id' => $messageId]
                );
                foreach ($attachments as $attachment) {
                    $paths[] = (string) ($attachment['stored_path'] ?? '');
                }
            }
            if (!$context->removePaths($paths, $result)) {
                continue;
            }
            $purged += $context->db->execute(
                'DELETE FROM messages WHERE id = :id AND is_deleted = 1 AND deleted_at < :cutoff',
                [':id' => $messageId, ':cutoff' => $cutoff]
            );
        }
        $context->addPurged($result, 'messages', $purged);
    }
}
