<?php

declare(strict_types=1);

namespace Core;

/**
 * Поставщик фоновой очистки данных, принадлежащих конкретному модулю.
 * Работает без пользовательского runtime и лицензии.
 */
interface ModuleRetentionCleanup
{
    public function moduleId(): string;

    /** @return array<string,int> */
    public function preview(string $cutoff, int $limit, RetentionCleanupContext $context): array;

    /** @param array<string,mixed> $result */
    public function apply(string $cutoff, int $limit, RetentionCleanupContext $context, array &$result): void;
}
