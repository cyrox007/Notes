<?php

declare(strict_types=1);

namespace Modules\WorkPermits;

use Core\DatabaseManager;
use Modules\WorkPermits\Services\WorkPermitDraftService;

/** Только явный интерфейс собственного модуля; чужие таблицы не используются. */
final class WorkPermitsCapability
{
    /** @return array<string,mixed>|null */
    public function draft(int $documentId, int $userId): ?array
    {
        return (new WorkPermitDraftService(DatabaseManager::getInstance()))->detail($documentId, $userId);
    }
}
