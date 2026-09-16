<?php

declare(strict_types=1);

namespace Modules\Notes;

/**
 * Marker/service exported as `workspace.notes`.
 *
 * Concrete cross-module operations are added here only when a consumer needs a
 * stable Notes contract; consumers must not reach into Notes models/controllers.
 */
final class NotesCapability
{
    public function moduleId(): string
    {
        return 'notes';
    }
}
