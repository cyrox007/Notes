<?php
declare(strict_types=1);

namespace Modules\Files;

/**
 * Marker/service exported as `workspace.files`.
 * Cross-module consumers resolve this capability instead of reaching
 * into File Manager controllers/models/services directly.
 */
final class FilesCapability
{
    public function moduleId(): string
    {
        return 'files';
    }
}
