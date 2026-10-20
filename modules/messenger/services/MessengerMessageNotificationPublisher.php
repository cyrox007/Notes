<?php

declare(strict_types=1);

namespace App\Services;

require_once dirname(__DIR__, 3) . '/app/services/notifications/NotificationEvent.php';
require_once dirname(__DIR__, 3) . '/app/services/notifications/NotificationService.php';

use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\NotificationService;
use Core\DatabaseManager;
use InvalidArgumentException;

/**
 * Messenger публикует семантическое событие, а канал внешней доставки
 * выбирает только ядро Notifications. Текст переписки сюда не передаётся.
 */
final class MessengerMessageNotificationPublisher
{
    public function __construct(
        private ?DatabaseManager $db = null,
        private ?NotificationService $notifications = null
    ) {
        $this->db ??= DatabaseManager::getInstance();
        $this->notifications ??= new NotificationService();
    }

    /**
     * Событие допустимо публиковать только после сохранения сообщения.
     * При повторной отправке одного message UID срабатывает идемпотентность.
     *
     * @return int количество адресатов, переданных платформе
     */
    public function publish(int $senderId, int $dialogId, string $dialogUid, string $messageUid): int
    {
        if ($senderId < 1 || $dialogId < 1
            || preg_match('/^[a-f0-9-]{36}$/iD', $dialogUid) !== 1
            || preg_match('/^[a-f0-9-]{36}$/iD', $messageUid) !== 1) {
            throw new InvalidArgumentException('Некорректные идентификаторы Messenger для уведомления');
        }

        // Не уведомляем отправителя, удаливших диалог и временно заглушивших
        // его участников. Права на прочтение текста проверяет сам Messenger.
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT m.user_id
             FROM user_to_dialogs m
             JOIN users u ON u.id=m.user_id
             WHERE m.dialog_id=:dialog AND m.user_id<>:sender AND m.is_deleted=0
               AND (m.muted_until IS NULL OR m.muted_until<=UTC_TIMESTAMP())
               AND u.is_active=1 AND u.account_status=\'active\'
               AND u.deletion_requested_at IS NULL AND u.anonymized_at IS NULL
             ORDER BY m.user_id',
            [':dialog' => $dialogId, ':sender' => $senderId]
        );
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (array $row): int => (int) ($row['user_id'] ?? 0), $rows),
            static fn (int $id): bool => $id > 0 && $id !== $senderId
        )));
        if ($ids === []) {
            return 0;
        }

        $target = '/messenger?dialog=' . rawurlencode($dialogUid)
            . '&message=' . rawurlencode($messageUid);
        foreach (array_chunk($ids, 1000) as $index => $recipients) {
            $this->notifications->publish(new NotificationEvent(
                name: 'messenger.message_received',
                version: 1,
                idempotencyKey: 'message:' . $messageUid . ':batch-' . $index,
                source: 'messenger',
                category: 'messenger.messages',
                importance: NotificationEvent::IMPORTANCE_NORMAL,
                title: 'Новое сообщение в Messenger',
                body: 'Вам пришло сообщение. Откройте диалог в Notes.',
                recipientUserIds: $recipients,
                payload: ['dialog_uid' => $dialogUid, 'message_uid' => $messageUid],
                targetPath: $target
            ));
        }
        return count($ids);
    }
}
