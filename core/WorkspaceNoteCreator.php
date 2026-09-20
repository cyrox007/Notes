<?php

declare(strict_types=1);

namespace Core;

/**
 * Shared cross-module boundary for creating a personal note without coupling
 * the caller to the Notes module's storage or controllers.
 */
interface WorkspaceNoteCreator
{
    /** @return array{uid:string,title:string} */
    public function createWorkspaceNote(int $userId, string $title, string $content): array;
}
