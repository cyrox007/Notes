<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once dirname(__DIR__, 3) . '/core/DatabaseManager.php';
require_once __DIR__ . '/NotificationEmailRecipientResolver.php';

use Core\DatabaseManager;
use InvalidArgumentException;

final class DatabaseNotificationEmailRecipientResolver implements NotificationEmailRecipientResolver
{
    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    public function emailForUser(int $userId): ?string
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Идентификатор получателя E-mail должен быть положительным');
        }

        $row = $this->db->fetchOne(
            'SELECT email
             FROM users
             WHERE id = :id
               AND is_active = 1
               AND account_status = \'active\'
               AND deletion_requested_at IS NULL
               AND anonymized_at IS NULL
             LIMIT 1',
            [':id' => $userId]
        );
        if ($row === null) {
            return null;
        }

        $email = trim((string) ($row['email'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }
}
