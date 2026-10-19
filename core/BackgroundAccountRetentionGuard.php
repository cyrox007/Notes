<?php

declare(strict_types=1);

namespace Core;

/**
 * Независимая от пользовательского runtime проверка фонового обезличивания.
 * Возвращает код блокировки без персональных данных или null.
 */
interface BackgroundAccountRetentionGuard
{
    public function moduleId(): string;

    public function blockingReason(int $userId): ?string;
}
