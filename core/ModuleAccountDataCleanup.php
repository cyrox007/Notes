<?php

declare(strict_types=1);

namespace Core;

/** Необязательный контракт модуля для очистки принадлежащих ему
 * вспомогательных данных внутри транзакции обезличивания аккаунта.
 */
interface ModuleAccountDataCleanup
{
    public function purgeAccountData(int $userId, RetentionCleanupContext $context): void;
}
