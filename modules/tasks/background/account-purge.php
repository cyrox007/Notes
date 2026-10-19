<?php

declare(strict_types=1);

// Фоновая проверка может работать при disabled/unlicensed без Tasks runtime.
require_once dirname(__DIR__) . '/services/TasksAccountOwnership.php';

return new class($database) implements \Core\BackgroundAccountRetentionGuard {
    public function __construct(private readonly \Core\DatabaseManager $database)
    {
    }

    public function moduleId(): string
    {
        return 'tasks';
    }

    public function blockingReason(int $userId): ?string
    {
        return (new \Modules\Tasks\TasksAccountOwnership($this->database))
            ->ownedSharedBoardName($userId) === null
            ? null : 'owned_shared_task_board';
    }
};
