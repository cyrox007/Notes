<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;

final class MessengerMediaCleanupService
{
    private const DEFAULT_TTL_SECONDS = 86400;
    private const MAX_BATCH = 1000;

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @return array{scanned:int,marked_deleted:int,files_deleted:int,files_missing:int} */
    public function cleanup(?int $ttlSeconds = null, int $limit = 250): array
    {
        $ttlSeconds ??= $this->configuredTtl();
        $ttlSeconds = max(300, min(30 * 86400, $ttlSeconds));
        $limit = max(1, min(self::MAX_BATCH, $limit));
        $cutoff = date('Y-m-d H:i:s', time() - $ttlSeconds);

        $rows = $this->db->fetchAll(
            'SELECT id, stored_path
             FROM messenger_attachments
             WHERE message_id IS NULL
               AND is_deleted = 0
               AND created_at < :cutoff
             ORDER BY id ASC
             LIMIT ' . $limit,
            [':cutoff' => $cutoff]
        );

        $result = [
            'scanned' => count($rows),
            'marked_deleted' => 0,
            'files_deleted' => 0,
            'files_missing' => 0,
        ];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $storedPath = (string) ($row['stored_path'] ?? '');
            $path = $this->resolveStoredPath($storedPath);

            $this->db->beginTransaction();
            try {
                $affected = $this->db->execute(
                    'UPDATE messenger_attachments
                     SET is_deleted = 1
                     WHERE id = :id AND message_id IS NULL AND is_deleted = 0',
                    [':id' => $id]
                );
                $this->db->endTransaction(true);
            } catch (\Throwable $e) {
                $this->db->endTransaction(false);
                error_log('Messenger orphan cleanup DB failure for attachment ' . $id . ': ' . $e->getMessage());
                continue;
            }

            if (!$affected) {
                continue;
            }

            $result['marked_deleted']++;
            if ($path === null || !is_file($path)) {
                $result['files_missing']++;
                continue;
            }

            if (@unlink($path)) {
                $result['files_deleted']++;
            } else {
                error_log('Messenger orphan cleanup could not delete file: ' . $path);
            }
        }

        return $result;
    }

    private function configuredTtl(): int
    {
        $value = getenv('MESSENGER_ORPHAN_TTL_SECONDS');
        return is_string($value) && ctype_digit($value) && (int) $value > 0
            ? (int) $value
            : self::DEFAULT_TTL_SECONDS;
    }

    private function messengerStorageRoot(): string
    {
        $configured = getenv('PRIVATE_STORAGE_PATH');
        $root = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : dirname(SITEPATH) . DIRECTORY_SEPARATOR . 'notes-private-storage';
        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'messenger';
    }

    private function resolveStoredPath(string $storedPath): ?string
    {
        if ($storedPath === '') {
            return null;
        }

        $realPath = realpath($storedPath);
        $root = realpath($this->messengerStorageRoot());
        if ($realPath === false || $root === false) {
            return null;
        }

        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $candidate = rtrim($realPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($candidate, $rootPrefix)) {
            error_log('Blocked orphan cleanup path outside messenger storage: ' . $storedPath);
            return null;
        }

        return $realPath;
    }
}
