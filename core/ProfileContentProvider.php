<?php

declare(strict_types=1);

namespace Core;

interface ProfileContentProvider
{
    /** @return list<array<string,mixed>> */
    public function ownerProfileItems(int $userId, int $limit): array;

    /** @return list<array<string,mixed>> */
    public function publicProfileItems(int $userId, int $limit): array;

    public function setProfileVisibility(int $userId, string $uid, bool $isPublic): void;

    /** @return array<string,mixed> */
    public function profileMetrics(int $userId): array;
}
