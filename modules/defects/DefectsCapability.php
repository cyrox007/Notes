<?php

declare(strict_types=1);

namespace Modules\Defects;

use Core\DatabaseManager;
use Modules\Defects\Services\DefectRegistryService;

/**
 * Явный интерфейс данных модуля для будущих разрешённых интеграций.
 */
final class DefectsCapability
{
    /** @return array<string,mixed>|null */
    public function detail(int $id): ?array
    {
        return (new DefectRegistryService(DatabaseManager::getInstance()))->detail($id);
    }
}
