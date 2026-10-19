<?php

declare(strict_types=1);

// Загружаются только проверка данных модуля и интерфейс ядра, не runtime.php.
require_once dirname(__DIR__) . '/services/MessengerAccountOwnership.php';

return new class($database) implements \Core\BackgroundAccountRetentionGuard {
    public function __construct(private readonly \Core\DatabaseManager $database)
    {
    }

    public function moduleId(): string
    {
        return 'messenger';
    }

    public function blockingReason(int $userId): ?string
    {
        return (new \Modules\Messenger\MessengerAccountOwnership($this->database))
            ->ownedGroupName($userId) === null
            ? null : 'owned_shared_messenger_group';
    }
};
