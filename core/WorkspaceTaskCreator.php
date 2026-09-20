<?php

declare(strict_types=1);

namespace Core;

/**
 * Shared cross-module boundary for creating a personal task without coupling
 * the caller to the Tasks module's storage or controllers.
 */
interface WorkspaceTaskCreator
{
    /** @return array{uid:string,title:string,priority:string,due_date:?string} */
    public function createWorkspaceTask(
        int $userId,
        string $title,
        string $description,
        string $priority = 'medium',
        ?string $dueDate = null
    ): array;
}
