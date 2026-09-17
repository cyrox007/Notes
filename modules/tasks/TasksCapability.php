<?php
declare(strict_types=1);

namespace Modules\Tasks;

/**
 * Marker/service exported as `workspace.tasks`.
 * Cross-module consumers resolve this capability instead of reaching
 * into Tasks controllers/models/services directly.
 */
final class TasksCapability
{
    public function moduleId(): string
    {
        return 'tasks';
    }
}
