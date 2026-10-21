<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/notifications/DatabaseNotificationDeliveryQueue.php';

use App\Services\Notifications\DatabaseNotificationDeliveryQueue;

function assertChannelOwner(bool $actual, bool $expected, string $description): void
{
    if ($actual !== $expected) {
        throw new RuntimeException('Нарушена изоляция адресата: ' . $description);
    }
}

foreach (['telegram', 'max', 'sms', 'browser'] as $channel) {
    assertChannelOwner(
        DatabaseNotificationDeliveryQueue::validChannelOwner($channel, 0),
        false,
        'глобальный адресат запрещён для ' . $channel
    );
    assertChannelOwner(
        DatabaseNotificationDeliveryQueue::validChannelOwner($channel, 42),
        true,
        'личный адресат допускается для ' . $channel
    );
}

assertChannelOwner(DatabaseNotificationDeliveryQueue::validChannelOwner('email', 0), true, 'общая SMTP-конфигурация');
assertChannelOwner(DatabaseNotificationDeliveryQueue::validChannelOwner('email', 42), true, 'личная SMTP-конфигурация');
assertChannelOwner(DatabaseNotificationDeliveryQueue::validChannelOwner('webhook', 0), true, 'общий Webhook');
assertChannelOwner(DatabaseNotificationDeliveryQueue::validChannelOwner('webhook', 42), false, 'личный Webhook');
assertChannelOwner(DatabaseNotificationDeliveryQueue::validChannelOwner('other', 42), false, 'неизвестный канал');

$source = file_get_contents(dirname(__DIR__, 2) . '/app/services/notifications/DatabaseNotificationDeliveryQueue.php');
if (!is_string($source)
    || !str_contains($source, "'telegram', 'max', 'sms' => [\$configs[\$userId][\$channel] ?? null]")
    || !str_contains($source, 'self::validChannelOwner($channel, $owner)')) {
    throw new RuntimeException('Очередь должна использовать политику изоляции и не выбирать глобальный адресат бота');
}

echo "Политика принадлежности получателей для шести каналов уведомлений проверена\n";
