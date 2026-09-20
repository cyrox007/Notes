<?php

declare(strict_types=1);

namespace Core;

/**
 * Shared cross-module boundary for browsing/exporting a user's private files.
 * Callers never query File Manager tables directly.
 */
interface WorkspaceFileProvider
{
    /** @return list<array{uid:string,name:string,extension:string,type:string,mime_type:string,size:int,updated_at:string}> */
    public function listWorkspaceFiles(int $userId, string $query = '', int $limit = 50): array;

    /** @return array{uid:string,name:string,extension:string,type:string,mime_type:string,size:int,path:string} */
    public function exportWorkspaceFile(int $userId, string $uid): array;

    public function canShareWorkspaceFiles(int $userId): bool;

    /** @return array{token:string,expires_at:?string,file:array{uid:string,name:string,extension:string,mime_type:string,size:int}} */
    public function createWorkspaceFileShare(int $userId, string $uid, int $expiresHours = 0): array;

    public function revokeWorkspaceFileShare(int $userId, string $uid): void;

    /** @return array{token:string,expires_at:?string,file:array{uid:string,name:string,extension:string,type:string,mime_type:string,size:int,path:string}} */
    public function resolveWorkspaceFileShare(string $token): array;
}
