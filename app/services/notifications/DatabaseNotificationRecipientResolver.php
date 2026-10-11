<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once dirname(__DIR__, 3) . '/core/DatabaseManager.php';
require_once __DIR__ . '/NotificationRecipientResolver.php';

use Core\DatabaseManager;

final class DatabaseNotificationRecipientResolver implements NotificationRecipientResolver
{
    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    public function eligibleUserIds(array $requestedUserIds): array
    {
        $requested = $this->normalize($requestedUserIds);
        if ($requested === []) {
            return [];
        }

        [$placeholders, $params] = $this->placeholders($requested, 'recipient');
        $rows = $this->db->fetchAll(
            'SELECT id
             FROM users
             WHERE id IN (' . implode(', ', $placeholders) . ')
               AND is_active = 1
               AND account_status = \'active\'
               AND deletion_requested_at IS NULL
               AND anonymized_at IS NULL',
            $params
        );

        $eligible = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $eligible[$id] = true;
            }
        }

        return array_values(array_filter(
            $requested,
            static fn (int $userId): bool => isset($eligible[$userId])
        ));
    }

    /**
     * @param list<int> $userIds
     * @return array{0:list<string>,1:array<string,int>}
     */
    private function placeholders(array $userIds, string $prefix): array
    {
        $placeholders = [];
        $params = [];

        foreach ($userIds as $index => $userId) {
            $name = ':' . $prefix . '_' . $index;
            $placeholders[] = $name;
            $params[$name] = $userId;
        }

        return [$placeholders, $params];
    }

    /**
     * @param list<int> $userIds
     * @return list<int>
     */
    private function normalize(array $userIds): array
    {
        $normalized = [];
        foreach ($userIds as $userId) {
            $userId = (int) $userId;
            if ($userId > 0) {
                $normalized[$userId] = $userId;
            }
        }

        return array_values($normalized);
    }
}
