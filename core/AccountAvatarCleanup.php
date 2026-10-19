<?php

declare(strict_types=1);

namespace Core;

/**
 * Контракт очистки аватара. Реализация и хранилище принадлежат Profile.
 */
interface AccountAvatarCleanup
{
    /** @param array<string,mixed> $user */
    public function removeStoredAvatar(array $user): void;
}
